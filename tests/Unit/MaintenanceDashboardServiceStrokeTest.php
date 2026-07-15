<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\MaintenanceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceDashboardServiceStrokeTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part     = MasterPart::create(['part_no' => 'SK-P1', 'part_name' => 'Stroke Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        return compact('user', 'shift', 'karyawan', 'proses');
    }

    private function mesin(string $tipe = 'PROGRESSIVE'): MasterMesin
    {
        return MasterMesin::create([
            'id_mesin' => 'SK'.uniqid(), 'nama_mesin' => 'Stroke Mesin', 'line' => '1',
            'tipe_mesin' => $tipe, 'kapasitas_max_stroke' => 100,
        ]);
    }

    private function produksi(array $f, MasterMesin $mesin, string $tanggal, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $f['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty,
            'created_by' => $f['user']->id,
        ]);
    }

    public function test_total_stroke_sums_qty_aktual_per_mesin(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin();
        $this->produksi($f, $m, '2026-06-01', 300);
        $this->produksi($f, $m, '2026-06-10', 150);
        $this->produksi($f, $m, '2026-06-20', 200);

        $rows = (new MaintenanceDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(650, $rows[0]['total_stroke']);
        $this->assertSame($m->id_mesin, $rows[0]['kode_mesin']);
    }

    public function test_date_window_excludes_produksi_outside_range(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin();
        $this->produksi($f, $m, '2026-03-01', 100);

        $rows = (new MaintenanceDashboardService())->computeStroke(
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')
        );

        $this->assertSame(0, $rows[0]['total_stroke']);
    }

    public function test_mesin_id_filter_narrows_to_single_mesin(): void
    {
        $f  = $this->fixtures();
        $m1 = $this->mesin();
        $m2 = $this->mesin();
        $this->produksi($f, $m1, '2026-06-01', 100);
        $this->produksi($f, $m2, '2026-06-01', 999);

        $rows = (new MaintenanceDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), $m1->id
        );

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['total_stroke']);
    }

    public function test_tipe_mesin_filter_narrows_result(): void
    {
        $f  = $this->fixtures();
        $m1 = $this->mesin('PROGRESSIVE');
        $m2 = $this->mesin('SINGLE');
        $this->produksi($f, $m1, '2026-06-01', 100);
        $this->produksi($f, $m2, '2026-06-01', 200);

        $rows = (new MaintenanceDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), null, 'SINGLE'
        );

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['total_stroke']);
    }
}
