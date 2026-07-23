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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceDashboardServiceCurrentCycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'CC-P'.uniqid(), 'part_name' => 'CC Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        return compact('user', 'shift', 'karyawan', 'proses');
    }

    private function mesin(?string $instalasi = null, string $tipe = 'PROGRESSIVE'): MasterMesin
    {
        return MasterMesin::create([
            'id_mesin' => 'CC'.uniqid(), 'nama_mesin' => 'CC Mesin', 'line' => '1',
            'tipe_mesin' => $tipe, 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $instalasi,
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

    private function preventive(array $f, MasterMesin $mesin, string $tanggal, int $strokeAt): MesinPreventiveLog
    {
        return MesinPreventiveLog::create([
            'id_mesin' => $mesin->id, 'tanggal_preventive' => $tanggal,
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => $strokeAt,
            'created_by' => $f['user']->id,
        ]);
    }

    public function test_current_stroke_falls_back_to_tanggal_instalasi_when_never_preventived(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin('2026-01-01');
        $this->produksi($f, $m, '2025-12-01', 999); // sebelum instalasi, tidak dihitung
        $this->produksi($f, $m, '2026-02-01', 100);
        $this->produksi($f, $m, '2026-03-01', 50);

        $svc = new MaintenanceDashboardService;
        $this->assertSame(150, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_current_stroke_is_all_time_when_no_preventive_and_no_instalasi(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin(null);
        $this->produksi($f, $m, '2020-01-01', 40);
        $this->produksi($f, $m, '2026-01-01', 60);

        $svc = new MaintenanceDashboardService;
        $this->assertSame(100, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_current_stroke_only_counts_transactions_after_latest_preventive(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin('2026-01-01');
        $this->produksi($f, $m, '2026-02-01', 300000); // pra-preventive, dikecualikan
        $this->preventive($f, $m, '2026-03-01', 300000);
        $this->produksi($f, $m, '2026-04-01', 500);
        $this->produksi($f, $m, '2026-05-01', 300);

        $svc = new MaintenanceDashboardService;
        $this->assertSame(800, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_produksi_exactly_on_preventive_date_belongs_to_the_old_cycle(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin('2026-01-01');
        $this->preventive($f, $m, '2026-03-01', 300000);
        $this->produksi($f, $m, '2026-03-01', 9999); // TEPAT di tanggal preventive — sudah masuk snapshot lama
        $this->produksi($f, $m, '2026-04-01', 500);  // setelah preventive — masuk siklus baru

        $svc = new MaintenanceDashboardService;
        // Perbandingan sengaja strict `>`: produksi di tanggal preventive TIDAK ikut siklus baru.
        $this->assertSame(500, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_current_stroke_uses_latest_preventive_not_earliest(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin('2026-01-01');
        $this->preventive($f, $m, '2026-02-01', 100000);
        $this->preventive($f, $m, '2026-04-01', 200000);
        $this->produksi($f, $m, '2026-03-01', 5000); // di antara dua preventive, dikecualikan
        $this->produksi($f, $m, '2026-05-01', 700);  // setelah yang terakhir, dihitung

        $svc = new MaintenanceDashboardService;
        $this->assertSame(700, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_threshold_is_300000(): void
    {
        $this->assertSame(300000, MaintenanceDashboardService::STROKE_THRESHOLD);
    }

    public function test_compute_current_cycle_status_bands(): void
    {
        $f = $this->fixtures();

        $ok = $this->mesin('2026-01-01');
        $this->produksi($f, $ok, '2026-02-01', 10000);   // 3.3% -> ok

        $warn = $this->mesin('2026-01-01');
        $this->produksi($f, $warn, '2026-02-01', 250000); // 83.3% -> warning

        $over = $this->mesin('2026-01-01');
        $this->produksi($f, $over, '2026-02-01', 310000); // 103.3% -> overdue

        $svc = new MaintenanceDashboardService;
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_mesin');

        $this->assertSame('ok', $rows[$ok->id]['status']);
        $this->assertSame('warning', $rows[$warn->id]['status']);
        $this->assertSame('overdue', $rows[$over->id]['status']);
        $this->assertSame(300000, $rows[$ok->id]['threshold']);
    }

    public function test_compute_current_cycle_sorts_overdue_first_then_warning_then_ok(): void
    {
        $f = $this->fixtures();

        $ok = $this->mesin('2026-01-01');
        $this->produksi($f, $ok, '2026-02-01', 10000);

        $over = $this->mesin('2026-01-01');
        $this->produksi($f, $over, '2026-02-01', 310000);

        $warn = $this->mesin('2026-01-01');
        $this->produksi($f, $warn, '2026-02-01', 250000);

        $svc = new MaintenanceDashboardService;
        $rows = $svc->computeCurrentCycle();

        $this->assertSame('overdue', $rows[0]['status']);
        $this->assertSame('warning', $rows[1]['status']);
        $this->assertSame('ok', $rows[2]['status']);
    }

    public function test_mesin_without_any_transaksi_still_appears_with_zero_stroke(): void
    {
        $this->fixtures();
        $m = $this->mesin('2026-01-01'); // tidak ada TransaksiProduksi sama sekali

        $svc = new MaintenanceDashboardService;
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_mesin');

        $this->assertArrayHasKey($m->id, $rows);
        $this->assertSame(0, $rows[$m->id]['current_stroke']);
        $this->assertSame('ok', $rows[$m->id]['status']);
        $this->assertNull($rows[$m->id]['last_preventive_date']);
    }

    public function test_row_exposes_last_preventive_date_and_pic(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin('2026-01-01');
        $this->produksi($f, $m, '2026-02-01', 300000);
        $this->preventive($f, $m, '2026-03-01', 300000);

        $svc = new MaintenanceDashboardService;
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_mesin');

        $this->assertSame(0, $rows[$m->id]['current_stroke']);
        $this->assertSame('2026-03-01', $rows[$m->id]['last_preventive_date']);
        $this->assertSame('Teknisi A', $rows[$m->id]['last_preventive_pic']);
    }

    public function test_mesin_id_filter_narrows_to_single_mesin(): void
    {
        $f = $this->fixtures();
        $m1 = $this->mesin('2026-01-01');
        $m2 = $this->mesin('2026-01-01');
        $this->produksi($f, $m1, '2026-02-01', 100);
        $this->produksi($f, $m2, '2026-02-01', 999);

        $svc = new MaintenanceDashboardService;
        $rows = $svc->computeCurrentCycle($m1->id);

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['current_stroke']);
    }

    public function test_tipe_mesin_filter_narrows_result(): void
    {
        $f = $this->fixtures();
        $m1 = $this->mesin('2026-01-01', 'PROGRESSIVE');
        $m2 = $this->mesin('2026-01-01', 'STAMPING');
        $this->produksi($f, $m1, '2026-02-01', 100);
        $this->produksi($f, $m2, '2026-02-01', 200);

        $svc = new MaintenanceDashboardService;
        $rows = $svc->computeCurrentCycle(null, 'STAMPING');

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['current_stroke']);
        $this->assertSame('STAMPING', $rows[0]['tipe_mesin']);
    }

    /**
     * N+1 guard: computeCurrentCycle already eager-loads preventiveLogs, so the latest
     * preventive date must be read from that relation — NOT re-queried once per mesin.
     */
    public function test_compute_current_cycle_does_not_run_per_mesin_max_preventive_query(): void
    {
        $f = $this->fixtures();
        for ($i = 0; $i < 3; $i++) {
            $m = $this->mesin('2026-01-01');
            $this->preventive($f, $m, '2026-02-01', 1000);
            $this->produksi($f, $m, '2026-03-01', 100);
        }

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        (new MaintenanceDashboardService)->computeCurrentCycle();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $maxPreventiveQueries = collect($queries)->filter(fn ($q) => str_contains(strtolower($q['query']), 'max(')
            && str_contains($q['query'], 'tanggal_preventive')
        )->count();

        $this->assertSame(0, $maxPreventiveQueries,
            'Latest preventive date should come from the eager-loaded relation, not a per-mesin MAX() query.');
    }
}
