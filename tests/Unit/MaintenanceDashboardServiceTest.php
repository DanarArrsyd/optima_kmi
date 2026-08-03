<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use App\Services\MaintenanceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mesin(?string $instalasi): MasterMesin
    {
        return MasterMesin::create([
            'id_mesin' => 'M'.uniqid(), 'nama_mesin' => 'Test', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $instalasi,
        ]);
    }

    public function test_mttr_is_average_repair_time_over_breakdown_count(): void
    {
        $m = $this->mesin('2026-01-01');
        foreach ([['2026-06-01', 2.0, 3.0], ['2026-06-10', 4.0, 5.0]] as [$tgl, $repair, $down]) {
            TransaksiBreakdownMesin::create([
                'no_lkm' => uniqid(), 'id_mesin' => $m->id,
                'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
                'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Sedang', 'pic_maintenance' => 'PIC',
                'repair_time' => $repair, 'total_downtime' => $down,
            ]);
        }

        $rows = (new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertEqualsWithDelta(3.0, $rows[0]['mttr_jam'], 0.01); // (2+4)/2
        $this->assertEqualsWithDelta(8.0, $rows[0]['sum_downtime'], 0.01);
        $this->assertTrue($rows[0]['instalasi_set']);
        $this->assertGreaterThan(0, $rows[0]['availability_pct']);
        $this->assertLessThanOrEqual(100, $rows[0]['availability_pct']);

        // availability == (operating - sum_downtime) / operating * 100, computed from the same window
        $row = $rows[0];
        $operating = Carbon::parse('2026-01-01')
            ->diffInRealHours(Carbon::parse('2026-06-30')->endOfDay(), false);
        $expectedAvail = ($operating - $row['sum_downtime']) / $operating * 100;
        $this->assertEqualsWithDelta($expectedAvail, $row['availability_pct'], 0.5);
    }

    public function test_null_instalasi_marks_row_and_nulls_mtbf_availability(): void
    {
        $m = $this->mesin(null);
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-06-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'PIC',
            'repair_time' => 1.0, 'total_downtime' => 2.0,
        ]);

        $rows = (new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertFalse($rows[0]['instalasi_set']);
        $this->assertNull($rows[0]['mtbf_jam']);
        $this->assertNull($rows[0]['mtbf_hari']);
        $this->assertNull($rows[0]['availability_pct']);
        $this->assertSame(1.0, $rows[0]['mttr_jam']);
    }

    public function test_date_window_excludes_breakdowns_outside_range(): void
    {
        $m = $this->mesin('2026-01-01');
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-03-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-03-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-03-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'PIC',
            'repair_time' => 1.0, 'total_downtime' => 2.0,
        ]);

        $rows = (new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')
        );

        $this->assertSame(0, $rows[0]['count']);
        $this->assertSame(0.0, $rows[0]['mttr_jam']);
    }

    /* ═══ Dandori sebagai kerugian availability ═══════════════════ */

    private function dandori(int $mesinId, float $menit, string $tgl): void
    {
        $part = MasterPart::firstOrCreate(
            ['part_no' => 'PT-MDS'],
            ['part_name' => 'X', 'customer' => 'Toyota']
        );
        $proses = MasterPartProses::firstOrCreate(
            ['id_part' => $part->id, 'id_proses' => 1],
            ['nama_proses' => 'BLANKING', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]
        );
        $shift = MasterShift::firstOrCreate(
            ['nama_shift' => 'Shift 1'],
            ['jam_start' => '06:00', 'jam_finish' => '14:00']
        );
        $kar = MasterKaryawan::firstOrCreate(
            ['nik' => 'K-MDS'],
            ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]
        );
        $lt = MasterLosstime::firstOrCreate(
            ['kode' => '9'],
            ['jenis_losstime' => 'Setting_Dies', 'std_menit_default' => null, 'std_menit_progressive' => null]
        );

        TransaksiDandoriRincian::create([
            'tanggal' => $tgl, 'id_shift' => $shift->id, 'id_karyawan' => $kar->id,
            'id_mesin' => $mesinId, 'id_part_proses' => $proses->id, 'id_losstime' => $lt->id,
            'actual_menit' => $menit, 'std_menit' => 0, 'over_menit' => 0,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    public function test_dandori_menurunkan_availability_walau_nol_breakdown(): void
    {
        // Inti perubahannya. Sebelum ini hari tanpa breakdown SELALU 100%,
        // walau belasan jam habis untuk ganti dies.
        $m = $this->mesin('2026-01-01');
        $this->dandori($m->id, menit: 360, tgl: '2026-07-15');   // 6 jam

        $rows = (new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-07-15'), Carbon::parse('2026-07-15')
        );

        // Jendela 1 hari = 24 jam. (24 − 6) / 24 = 75%.
        $this->assertSame(6.0, $rows[0]['sum_dandori']);
        $this->assertSame(0.0, $rows[0]['sum_downtime']);
        $this->assertSame(6.0, $rows[0]['sum_loss']);
        $this->assertEqualsWithDelta(75.0, $rows[0]['availability_pct'], 0.5);
        $this->assertNotSame(100.0, $rows[0]['availability_pct']);
    }

    public function test_breakdown_dan_dandori_dijumlah_tapi_tetap_dilaporkan_terpisah(): void
    {
        $m = $this->mesin('2026-01-01');
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-07-15', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-15', 'jam_mulai_perbaikan' => '08:00',
            'tanggal_selesai_perbaikan' => '2026-07-15', 'jam_selesai_perbaikan' => '11:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'PIC',
            'repair_time' => 3.0, 'total_downtime' => 3.0,
        ]);
        $this->dandori($m->id, menit: 180, tgl: '2026-07-15');   // 3 jam

        $rows = (new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-07-15'), Carbon::parse('2026-07-15')
        );

        // Keduanya dilaporkan terpisah supaya halaman bisa menjelaskan KENAPA
        // availability turun — rusak, atau kelamaan ganti dies.
        $this->assertSame(3.0, $rows[0]['sum_downtime']);
        $this->assertSame(3.0, $rows[0]['sum_dandori']);
        $this->assertSame(6.0, $rows[0]['sum_loss']);
        $this->assertEqualsWithDelta(75.0, $rows[0]['availability_pct'], 0.5);
    }

    public function test_mtbf_tidak_ikut_bergeser_karena_dandori(): void
    {
        // MTBF = "berapa lama antar KEGAGALAN". Dandori bukan kegagalan;
        // memasukkannya membuat mesin yang sering ganti dies terlihat lebih
        // sering rusak. Angkanya HARUS identik dengan dan tanpa dandori.
        $tanpa = $this->mesin('2026-01-01');
        $dengan = $this->mesin('2026-01-01');

        foreach ([$tanpa, $dengan] as $m) {
            TransaksiBreakdownMesin::create([
                'no_lkm' => uniqid(), 'id_mesin' => $m->id,
                'tanggal_breakdown' => '2026-07-15', 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-15', 'jam_mulai_perbaikan' => '08:00',
                'tanggal_selesai_perbaikan' => '2026-07-15', 'jam_selesai_perbaikan' => '10:00',
                'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Sedang', 'pic_maintenance' => 'PIC',
                'repair_time' => 2.0, 'total_downtime' => 2.0,
            ]);
        }
        $this->dandori($dengan->id, menit: 480, tgl: '2026-07-15');   // 8 jam

        $rows = collect((new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-07-15'), Carbon::parse('2026-07-15')
        ))->keyBy('id_mesin');

        $this->assertSame(
            $rows[$tanpa->id]['mtbf_jam'],
            $rows[$dengan->id]['mtbf_jam'],
            'MTBF tidak boleh bergeser karena dandori.'
        );
        // Sementara availability-nya JELAS berbeda — itu maksudnya.
        $this->assertLessThan(
            $rows[$tanpa->id]['availability_pct'],
            $rows[$dengan->id]['availability_pct']
        );
    }

    /**
     * Gerbang dialek SQL. Cast 'tanggal' di TransaksiDandoriRincian itu 'date'
     * TANPA format, jadi di SQLite nilainya bawa jam dan pembanding batas atas
     * bergaya whereBetween akan membuang dandori di HARI TERAKHIR rentang.
     * Halaman Overview memakai rentang satu hari (from == to), jadi kalau ini
     * salah, SELURUH dandori hari itu hilang dan availability balik 100%.
     */
    public function test_dandori_di_hari_terakhir_rentang_ikut_terhitung(): void
    {
        $m = $this->mesin('2026-01-01');
        $this->dandori($m->id, menit: 120, tgl: '2026-07-17');   // hari TERAKHIR

        $rows = (new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-07-15'), Carbon::parse('2026-07-17')
        );

        $this->assertSame(2.0, $rows[0]['sum_dandori']);
    }

    public function test_dandori_mesin_lain_tidak_bocor(): void
    {
        $a = $this->mesin('2026-01-01');
        $b = $this->mesin('2026-01-01');
        $this->dandori($a->id, menit: 300, tgl: '2026-07-15');

        $rows = collect((new MaintenanceDashboardService)->compute(
            Carbon::parse('2026-07-15'), Carbon::parse('2026-07-15')
        ))->keyBy('id_mesin');

        $this->assertSame(5.0, $rows[$a->id]['sum_dandori']);
        $this->assertSame(0.0, $rows[$b->id]['sum_dandori']);
        $this->assertSame(100.0, $rows[$b->id]['availability_pct']);
    }
}
