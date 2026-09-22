<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * VCB aggregation. Menjaga business logic yang halus: achievement Produksi = rata-rata
 * PER-SESI (bukan total qty / total target), sedangkan Mesin & Quality = total-based.
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new DashboardService;
    }

    private function base(): array
    {
        $user = User::factory()->create();
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 1', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);

        return compact('user', 'shift', 'karyawan', 'mesin', 'proses');
    }

    private function produksi(array $b, string $tgl, string $start, string $finish, int $qty): void
    {
        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl, 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $b['mesin']->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => $start, 'jam_finish' => $finish, 'qty_aktual' => $qty, 'created_by' => $b['user']->id,
        ]);
    }

    public function test_vcb_produksi_achievement_is_per_session_average(): void
    {
        $b = $this->base();
        // Sesi 1: 8 jam, 4000 pcs, SPH std 500 → sph_act 500 → 100%.
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);
        // Sesi 2: 4 jam, 1000 pcs, SPH std 500 → sph_act 250 → 50%.
        $this->produksi($b, '2026-07-02', '08:00', '12:00', 1000);

        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertCount(1, $rows);
        // Rata-rata per-sesi (100 + 50) / 2 = 75 — BUKAN total 5000/(4000+2000)=83.3.
        $this->assertEqualsWithDelta(75.0, $rows[0]['achievement_pct'], 0.01);
        $this->assertSame(5000, $rows[0]['total_qty_aktual']);
        $this->assertEqualsWithDelta(375, $rows[0]['avg_actual_sph'], 0.01); // (500+250)/2
        $this->assertSame('danger', $rows[0]['traffic_light']); // <100
    }

    public function test_vcb_produksi_traffic_light_bands(): void
    {
        $b = $this->base();
        // Tepat 100% → success.
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);
        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $this->assertEqualsWithDelta(100.0, $rows[0]['achievement_pct'], 0.01);
        $this->assertSame('success', $rows[0]['traffic_light']);
    }

    public function test_vcb_produksi_over_100_is_info(): void
    {
        $b = $this->base();
        // 8 jam, 4800 pcs, std 500 → sph_act 600 → 120% → info.
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4800);
        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $this->assertSame('info', $rows[0]['traffic_light']);
    }

    public function test_vcb_produksi_handles_overnight_shift(): void
    {
        $b = $this->base();
        // 22:00 → 06:00 = 8 jam lewat tengah malam; 4000 pcs, std 500 → 100%.
        $this->produksi($b, '2026-07-01', '22:00', '06:00', 4000);
        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));
        $this->assertEqualsWithDelta(100.0, $rows[0]['achievement_pct'], 0.01);
        $this->assertEqualsWithDelta(8.0, $rows[0]['total_duration_hours'], 0.01);
    }

    public function test_vcb_mesin_achievement_is_total_based(): void
    {
        $b = $this->base();
        // Dua sesi beda pencapaian; Mesin memakai total qty / total target.
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000); // target 4000
        $this->produksi($b, '2026-07-02', '08:00', '12:00', 1000); // target 2000
        // total 5000 / total target 6000 = 83.33%.
        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertCount(1, $rows);
        $this->assertEqualsWithDelta(83.33, $rows[0]['achievement_pct'], 0.01);
        $this->assertSame(5000, $rows[0]['total_qty_aktual']);
    }

    public function test_vcb_quality_ng_rate_and_totals(): void
    {
        $b = $this->base();
        TransaksiQc::create([
            'tgl_pengecekan' => '2026-07-01', 'tgl_produksi_part' => '2026-07-01',
            'id_shift' => $b['shift']->id, 'id_karyawan_qc' => $b['karyawan']->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_ok' => 900, 'qty_ng' => 100, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbQuality(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'));

        $this->assertCount(1, $rows);
        $this->assertSame(900, $rows[0]['total_qty_ok']);
        $this->assertSame(100, $rows[0]['total_qty_ng']);
        $this->assertSame(1000, $rows[0]['total_qty_check']);
        $this->assertEqualsWithDelta(10.0, $rows[0]['ng_rate'], 0.01); // 100/1000
    }

    public function test_shift_filter_narrows_produksi(): void
    {
        $b = $this->base();
        $shift2 = MasterShift::create(['nama_shift' => 'S2', 'jam_start' => '16:00', 'jam_finish' => '00:00']);
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);
        // Sesi shift lain:
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $shift2->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $b['mesin']->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '16:00', 'jam_finish' => '20:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), $b['shift']->id);
        $this->assertCount(1, $rows);
        $this->assertSame(4000, $rows[0]['total_qty_aktual']); // sesi shift2 tersaring
    }

    public function test_mesin_filter_narrows_produksi(): void
    {
        $b = $this->base();
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        // Karyawan sama di kedua sesi → tanpa filter jadi 1 baris gabungan (4999).
        // Dengan filter mesin id ($b['mesin']->id saja), sesi mesinB harus tersaring.
        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, [$b['mesin']->id]);
        $this->assertCount(1, $rows);
        $this->assertSame(4000, $rows[0]['total_qty_aktual']);
    }

    public function test_mesin_filter_narrows_mesin(): void
    {
        $b = $this->base();
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, [$mesinB->id]);
        $this->assertCount(1, $rows);
        $this->assertSame($mesinB->id_mesin, $rows[0]['id_mesin']);
        $this->assertSame(999, $rows[0]['total_qty_aktual']);
    }

    public function test_mesin_filter_accepts_multiple_values(): void
    {
        $b = $this->base();
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        // Mesin ketiga TIDAK diikutkan di filter — kalau filter benar-benar
        // tercabut, count jadi 3 (bukan 2) dan mesin ini bakal ikut muncul.
        $mesinC = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 3', 'line' => '3', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinC->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 111, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, [$b['mesin']->id, $mesinB->id]);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$b['mesin']->id_mesin, $mesinB->id_mesin], array_column($rows, 'id_mesin'));
    }
}
