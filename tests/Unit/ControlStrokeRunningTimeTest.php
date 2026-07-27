<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\MesinPreventiveLog;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\MaintenanceDashboardService;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Running time di Control Stroke = SUM(durasi_menit) pada window siklus yang SAMA
 * dengan stroke (sejak preventive terakhir / instalasi). Menguji agregasi window
 * + reset saat ada preventive.
 */
class ControlStrokeRunningTimeTest extends TestCase
{
    use RefreshDatabase;

    private MasterShift $shift;

    private MasterKaryawan $kar;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift = MasterShift::create(['nama_shift' => 'S', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->kar = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $this->user = User::factory()->create();
    }

    private function lhp(int $mesinId, int $prosesId, string $tgl, int $qty, string $start = '08:00', string $finish = '16:00'): void
    {
        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl, 'id_shift' => $this->shift->id, 'id_karyawan' => $this->kar->id,
            'id_mesin' => $mesinId, 'id_part_proses' => $prosesId,
            'jam_start' => $start, 'jam_finish' => $finish, 'qty_aktual' => $qty, 'qty_ng' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_gracefully_degrades_when_durasi_menit_column_missing(): void
    {
        // Simulasi hosting yang belum migrasi: buang kolomnya, app WAJIB tetap jalan.
        Schema::table('transaksi_produksi', fn ($t) => $t->dropColumn('durasi_menit'));

        $mesin = MasterMesin::create(['id_mesin' => 'MX', 'nama_mesin' => 'P', 'line' => '1', 'tipe_mesin' => 'BLANK', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01']);
        $part = MasterPart::create(['part_no' => 'PX', 'part_name' => 'X', 'customer' => 'Toyota']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);

        // Input LHP TIDAK boleh 500 walau kolom hilang (saving hook di-guard).
        $this->lhp($mesin->id, $pr->id, '2026-02-10', 1000);

        // Service tetap balik stroke; running time 0 (bukan error unknown column).
        $totals = (new MaintenanceDashboardService)->cycleTotalsFor($mesin->fresh());
        $this->assertSame(1000, $totals['stroke']);
        $this->assertSame(0, $totals['durasi_menit']);

        $row = collect((new MaintenanceDashboardService)->computeCurrentCycle($mesin->id))->first();
        $this->assertSame(0, $row['running_time_menit']);
    }

    public function test_mesin_running_time_sums_durasi_since_install(): void
    {
        $mesin = MasterMesin::create(['id_mesin' => 'M-01', 'nama_mesin' => 'Press', 'line' => '1', 'tipe_mesin' => 'BLANK', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01']);
        $part = MasterPart::create(['part_no' => 'P1', 'part_name' => 'X', 'customer' => 'Toyota']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);

        // 2 LHP 8 jam masing-masing = 960 menit, stroke 1000+2000=3000.
        $this->lhp($mesin->id, $pr->id, '2026-02-10', 1000);
        $this->lhp($mesin->id, $pr->id, '2026-03-10', 2000);

        $totals = (new MaintenanceDashboardService)->cycleTotalsFor($mesin->fresh());
        $this->assertSame(3000, $totals['stroke']);
        $this->assertSame(960, $totals['durasi_menit']);

        $row = collect((new MaintenanceDashboardService)->computeCurrentCycle($mesin->id))->first();
        $this->assertSame(960, $row['running_time_menit']);
    }

    public function test_mesin_running_time_resets_after_preventive(): void
    {
        $mesin = MasterMesin::create(['id_mesin' => 'M-02', 'nama_mesin' => 'Press', 'line' => '1', 'tipe_mesin' => 'BLANK', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01']);
        $part = MasterPart::create(['part_no' => 'P2', 'part_name' => 'X', 'customer' => 'Toyota']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);

        $this->lhp($mesin->id, $pr->id, '2026-02-10', 1000);       // sebelum preventive → tak dihitung
        MesinPreventiveLog::create(['id_mesin' => $mesin->id, 'tanggal_preventive' => '2026-03-01', 'id_karyawan' => $this->kar->id, 'stroke_at_preventive' => 1000, 'created_by' => $this->user->id]);
        $this->lhp($mesin->id, $pr->id, '2026-03-10', 2000);       // sesudah preventive → dihitung (8 jam)

        $totals = (new MaintenanceDashboardService)->cycleTotalsFor($mesin->fresh());
        $this->assertSame(2000, $totals['stroke'], 'Hanya LHP sesudah preventive.');
        $this->assertSame(480, $totals['durasi_menit']);
    }

    public function test_dies_running_time_aggregates_across_machines(): void
    {
        $m1 = MasterMesin::create(['id_mesin' => 'MA', 'nama_mesin' => 'A', 'line' => '1', 'tipe_mesin' => 'BLANK', 'kapasitas_max_stroke' => 100]);
        $m2 = MasterMesin::create(['id_mesin' => 'MB', 'nama_mesin' => 'B', 'line' => '1', 'tipe_mesin' => 'BLANK', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'PD', 'part_name' => 'Dies', 'customer' => 'Toyota', 'tanggal_instalasi' => '2026-01-01']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);

        // Dies sama dijalankan 2 mesin: 8 jam + 4 jam = 720 menit total.
        $this->lhp($m1->id, $pr->id, '2026-02-10', 1000, '08:00', '16:00');
        $this->lhp($m2->id, $pr->id, '2026-02-11', 500, '08:00', '12:00');

        $totals = (new MaintenanceDiesDashboardService)->cycleTotalsFor($pr->fresh());
        $this->assertSame(1500, $totals['stroke']);
        $this->assertSame(720, $totals['durasi_menit']);
    }
}
