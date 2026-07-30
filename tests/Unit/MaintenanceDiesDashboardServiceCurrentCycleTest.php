<?php

namespace Tests\Unit;

use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceDiesDashboardServiceCurrentCycleTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'CC'.uniqid(), 'nama_mesin' => 'CC Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        return compact('user', 'shift', 'karyawan', 'mesin');
    }

    private function partProses(?string $instalasi = null, string $customer = 'Toyota'): MasterPartProses
    {
        $part = MasterPart::create([
            'part_no' => 'CC-P'.uniqid(), 'part_name' => 'CC Part', 'customer' => $customer,
            'tanggal_instalasi' => $instalasi,
        ]);

        return MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    private function produksi(array $f, MasterPartProses $proses, string $tanggal, int $qty, ?int $ng = null): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty, 'qty_ng' => $ng,
            'created_by' => $f['user']->id,
        ]);
    }

    private function preventive(array $f, MasterPartProses $proses, string $tanggal, int $strokeAt): DiesPreventiveLog
    {
        return DiesPreventiveLog::create([
            'id_part_proses' => $proses->id, 'tanggal_preventive' => $tanggal,
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => $strokeAt,
            'created_by' => $f['user']->id,
        ]);
    }

    public function test_current_stroke_falls_back_to_tanggal_instalasi_when_never_preventived(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2025-12-01', 999); // before instalasi, must not count
        $this->produksi($f, $pr, '2026-02-01', 100);
        $this->produksi($f, $pr, '2026-03-01', 50);

        $svc = new MaintenanceDiesDashboardService;
        $this->assertSame(150, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_current_stroke_is_all_time_when_no_preventive_and_no_instalasi(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses(null);
        $this->produksi($f, $pr, '2020-01-01', 40);
        $this->produksi($f, $pr, '2026-01-01', 60);

        $svc = new MaintenanceDiesDashboardService;
        $this->assertSame(100, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_current_stroke_only_counts_transactions_after_latest_preventive(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2026-02-01', 30000); // pre-preventive, excluded
        $this->preventive($f, $pr, '2026-03-01', 30000);
        $this->produksi($f, $pr, '2026-04-01', 500);
        $this->produksi($f, $pr, '2026-05-01', 300);

        $svc = new MaintenanceDiesDashboardService;
        $this->assertSame(800, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_current_stroke_uses_latest_preventive_not_earliest(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->preventive($f, $pr, '2026-02-01', 10000);
        $this->preventive($f, $pr, '2026-04-01', 20000);
        $this->produksi($f, $pr, '2026-03-01', 5000);  // between the two preventives, excluded
        $this->produksi($f, $pr, '2026-05-01', 700);   // after the latest, included

        $svc = new MaintenanceDiesDashboardService;
        $this->assertSame(700, $svc->currentStrokeFor($pr->fresh()));
    }

    /**
     * Stroke adalah aksi FISIK dies: dies tetap menekan untuk barang yang jadi NG.
     * `qty_aktual` berisi barang baik saja (dikonfirmasi ke produksi 2026-07-28,
     * lihat OeeService), jadi stroke = qty_aktual + qty_ng. Menghitung barang baik
     * saja membuat siklus stroke understated dan preventive terpicu TERLAMBAT.
     */
    public function test_current_stroke_counts_ng_because_dies_still_pressed_for_it(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2026-02-01', 100, 20);
        $this->produksi($f, $pr, '2026-03-01', 50, 5);

        $svc = new MaintenanceDiesDashboardService;
        $this->assertSame(175, $svc->currentStrokeFor($pr->fresh()));
    }

    /**
     * `qty_ng` nullable default null di `transaksi_produksi` — `qty_aktual + qty_ng`
     * tanpa COALESCE menghasilkan NULL di SQL, yang membuat stroke jatuh ke 0 dan
     * dies tampak tak pernah dipakai.
     */
    public function test_current_stroke_treats_null_qty_ng_as_zero(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2026-02-01', 100, null);
        $this->produksi($f, $pr, '2026-03-01', 50, 5);

        $svc = new MaintenanceDiesDashboardService;
        $this->assertSame(155, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_compute_current_cycle_status_bands(): void
    {
        $f = $this->fixtures();

        $ok = $this->partProses('2026-01-01');
        $this->produksi($f, $ok, '2026-02-01', 1000); // 3.3% -> ok

        $warn = $this->partProses('2026-01-01');
        $this->produksi($f, $warn, '2026-02-01', 25000); // 83.3% -> warning

        $over = $this->partProses('2026-01-01');
        $this->produksi($f, $over, '2026-02-01', 31000); // 103.3% -> overdue

        $svc = new MaintenanceDiesDashboardService;
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_part_proses');

        $this->assertSame('ok', $rows[$ok->id]['status']);
        $this->assertSame('warning', $rows[$warn->id]['status']);
        $this->assertSame('overdue', $rows[$over->id]['status']);
        $this->assertSame(30000, $rows[$ok->id]['threshold']);
    }

    public function test_compute_current_cycle_sorts_overdue_first_then_warning_then_ok(): void
    {
        $f = $this->fixtures();

        $ok = $this->partProses('2026-01-01');
        $this->produksi($f, $ok, '2026-02-01', 1000);

        $over = $this->partProses('2026-01-01');
        $this->produksi($f, $over, '2026-02-01', 31000);

        $warn = $this->partProses('2026-01-01');
        $this->produksi($f, $warn, '2026-02-01', 25000);

        $svc = new MaintenanceDiesDashboardService;
        $rows = $svc->computeCurrentCycle();

        $this->assertSame('overdue', $rows[0]['status']);
        $this->assertSame('warning', $rows[1]['status']);
        $this->assertSame('ok', $rows[2]['status']);
    }

    public function test_dies_with_no_transaksi_ever_is_excluded_from_listing(): void
    {
        $this->fixtures();
        $this->partProses('2026-01-01'); // no TransaksiProduksi created for it

        $svc = new MaintenanceDiesDashboardService;
        $this->assertCount(0, $svc->computeCurrentCycle());
    }

    public function test_dies_with_zero_current_stroke_after_reset_still_appears(): void
    {
        $f = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2026-02-01', 30000);
        $this->preventive($f, $pr, '2026-03-01', 30000); // reset — no transactions after this

        $svc = new MaintenanceDiesDashboardService;
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_part_proses');

        $this->assertArrayHasKey($pr->id, $rows);
        $this->assertSame(0, $rows[$pr->id]['current_stroke']);
        $this->assertSame('2026-03-01', $rows[$pr->id]['last_preventive_date']);
        $this->assertSame('Teknisi A', $rows[$pr->id]['last_preventive_pic']);
    }

    public function test_customer_filter_narrows_result(): void
    {
        $f = $this->fixtures();
        $toy = $this->partProses('2026-01-01', 'Toyota');
        $honda = $this->partProses('2026-01-01', 'Honda');
        $this->produksi($f, $toy, '2026-02-01', 100);
        $this->produksi($f, $honda, '2026-02-01', 200);

        $svc = new MaintenanceDiesDashboardService;
        $rows = $svc->computeCurrentCycle(null, 'Honda');

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['current_stroke']);
    }

    /**
     * N+1 guard: computeCurrentCycle already eager-loads preventiveLogs, so the latest
     * preventive date must be read from that relation — NOT re-queried once per dies.
     */
    public function test_compute_current_cycle_does_not_run_per_dies_max_preventive_query(): void
    {
        $f = $this->fixtures();
        for ($i = 0; $i < 3; $i++) {
            $pr = $this->partProses('2026-01-01');
            $this->preventive($f, $pr, '2026-02-01', 1000);
            $this->produksi($f, $pr, '2026-03-01', 100);
        }

        \DB::flushQueryLog();
        \DB::enableQueryLog();
        (new MaintenanceDiesDashboardService)->computeCurrentCycle();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $maxPreventiveQueries = collect($queries)->filter(fn ($q) => str_contains(strtolower($q['query']), 'max(')
            && str_contains($q['query'], 'tanggal_preventive')
        )->count();

        $this->assertSame(0, $maxPreventiveQueries,
            'Latest preventive date should come from the eager-loaded relation, not a per-dies MAX() query.');
    }
}
