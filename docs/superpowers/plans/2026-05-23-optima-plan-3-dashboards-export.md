# OPTIMA Plan 3: VCB Dashboards + Export

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build DashboardService and all 4 VCB dashboards (Produksi, Mesin, Quality, Dandori) with Chart.js grouped/stacked bar charts, AJAX filters, traffic light formatting, and Excel/PDF export.

**Architecture:** VCB controllers expose two routes each: `index()` (returns Blade view) and `data()` (returns JSON for AJAX). All aggregation logic lives in DashboardService. Chart.js initialized in each Blade view, calling `/vcb/*/data` on load and on filter submit. Export controllers accept same filter params, return Excel or PDF via Laravel packages.

**Pre-requisite:** Plans 1 and 2 must be complete.

**Tech Stack:** Laravel 11, Chart.js 4, maatwebsite/laravel-excel 3, barryvdh/laravel-dompdf 3

---

## Task 1: DashboardService

**Files:**
- Create: `app/Services/DashboardService.php`
- Create: `tests/Unit/Services/DashboardServiceTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/Services/DashboardServiceTest.php`:
```php
<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\DashboardService;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\MasterLosstime;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\TransaksiDandori;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;
    private MasterPartProses $proses;
    private MasterKaryawan $karyawan;
    private MasterMesin $mesin;
    private MasterShift $shift;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = new DashboardService();
        $this->shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $this->karyawan = MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $this->mesin    = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'A1-AIDA', 'line' => 'A', 'tipe_mesin' => 'STAMPING']);
        $part           = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $this->proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    public function test_vcb_produksi_calculates_achievement_correctly(): void
    {
        // 8 jam × 120 SPH = 960 target. Aktual = 960. Achievement = 100%
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-05-23', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00',
            'qty_aktual' => 960, 'actual_cycle_time' => 30, 'created_by' => $this->user->id,
        ]);

        $result = $this->service->getVcbProduksi(
            Carbon::parse('2026-05-23'), Carbon::parse('2026-05-23')
        );

        $this->assertCount(1, $result);
        $this->assertEquals(100.00, $result[0]['achievement_pct']);
        $this->assertEquals(120.00, $result[0]['avg_actual_sph']);
    }

    public function test_vcb_produksi_uses_sum_not_average(): void
    {
        // Two records for same operator — must SUM, not avg each separately then average
        // Record 1: 4 jam × 120 SPH = 480 target, aktual 480
        // Record 2: 4 jam × 120 SPH = 480 target, aktual 420 (short)
        // Total: 960 target, 900 aktual → 93.75%
        $base = [
            'tanggal_produksi' => '2026-05-23', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id, 'actual_cycle_time' => 30,
            'created_by' => $this->user->id,
        ];
        TransaksiProduksi::create([...$base, 'jam_start' => '08:00:00', 'jam_finish' => '12:00:00', 'qty_aktual' => 480]);
        TransaksiProduksi::create([...$base, 'jam_start' => '12:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 420]);

        $result = $this->service->getVcbProduksi(
            Carbon::parse('2026-05-23'), Carbon::parse('2026-05-23')
        );

        $this->assertEquals(93.75, $result[0]['achievement_pct']);
    }

    public function test_vcb_produksi_traffic_light_green(): void
    {
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-05-23', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00',
            'qty_aktual' => 960, 'actual_cycle_time' => 30, 'created_by' => $this->user->id,
        ]);
        $result = $this->service->getVcbProduksi(Carbon::parse('2026-05-23'), Carbon::parse('2026-05-23'));
        $this->assertEquals('success', $result[0]['traffic_light']);
    }

    public function test_traffic_light_rules(): void
    {
        $this->assertEquals('success', $this->service->getTrafficLight(100));
        $this->assertEquals('success', $this->service->getTrafficLight(105));
        $this->assertEquals('warning', $this->service->getTrafficLight(99));
        $this->assertEquals('warning', $this->service->getTrafficLight(85));
        $this->assertEquals('danger',  $this->service->getTrafficLight(84.9));
        $this->assertEquals('danger',  $this->service->getTrafficLight(0));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Unit/Services/DashboardServiceTest.php
```

- [ ] **Step 3: Implement DashboardService**

`app/Services/DashboardService.php`:
```php
<?php
namespace App\Services;
use App\Models\MasterLosstime;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\TransaksiDandori;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * VCB Produksi: aggregate by karyawan for date range.
     * Returns array of rows, one per unique karyawan in the period.
     */
    public function getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null): array
    {
        $records = TransaksiProduksi::with(['karyawan', 'partProses', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_karyawan')->map(function ($rows) {
            $totalQtyAktual    = $rows->sum('qty_aktual');
            $totalDurationHours = $rows->sum(fn($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty    = $rows->sum(fn($r) =>
                $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_produksi
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyAktual / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyAktual / $totalDurationHours, 2)
                : 0;

            return [
                'karyawan'            => $rows->first()->karyawan->nama_karyawan,
                'total_qty_aktual'    => $totalQtyAktual,
                'total_target_qty'    => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct'     => $achievementPct,
                'avg_actual_sph'      => $avgActualSph,
                'traffic_light'       => $this->getTrafficLight($achievementPct),
                'detail'              => $rows->map(fn($r) => [
                    'tanggal'      => $r->tanggal_produksi->format('d/m/Y'),
                    'mesin'        => $r->mesin->nama_mesin,
                    'part'         => $r->partProses->part->part_name ?? '-',
                    'proses'       => $r->partProses->nama_proses,
                    'qty_aktual'   => $r->qty_aktual,
                    'target_qty'   => round($this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_produksi, 0),
                    'actual_ct'    => $r->actual_cycle_time,
                ])->values()->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Mesin: aggregate by mesin for date range.
     */
    public function getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null): array
    {
        $records = TransaksiProduksi::with(['mesin', 'partProses'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_mesin')->map(function ($rows) {
            $totalQtyAktual    = $rows->sum('qty_aktual');
            $totalDurationHours = $rows->sum(fn($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty    = $rows->sum(fn($r) =>
                $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_produksi
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyAktual / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyAktual / $totalDurationHours, 2)
                : 0;

            $mesin = $rows->first()->mesin;
            return [
                'mesin'               => $mesin->nama_mesin,
                'id_mesin'            => $mesin->id_mesin,
                'line'                => $mesin->line,
                'tipe_mesin'          => $mesin->tipe_mesin,
                'total_qty_aktual'    => $totalQtyAktual,
                'total_target_qty'    => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct'     => $achievementPct,
                'avg_actual_sph'      => $avgActualSph,
                'traffic_light'       => $this->getTrafficLight($achievementPct),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Quality: aggregate by karyawan_qc for date range.
     */
    public function getVcbQuality(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null): array
    {
        $records = TransaksiQc::with(['karyawanQc', 'partProses'])
            ->whereBetween('tgl_pengecekan', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_karyawan_qc')->map(function ($rows) {
            $totalQtyOk        = $rows->sum('qty_ok');
            $totalQtyNg        = $rows->sum('qty_ng');
            $totalQtyCheck     = $totalQtyOk + $totalQtyNg;
            $totalDurationHours = $rows->sum(fn($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty    = $rows->sum(fn($r) =>
                $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_qc
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyCheck / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyCheck / $totalDurationHours, 2)
                : 0;

            return [
                'karyawan'            => $rows->first()->karyawanQc->nama_karyawan,
                'total_qty_ok'        => $totalQtyOk,
                'total_qty_ng'        => $totalQtyNg,
                'total_qty_check'     => $totalQtyCheck,
                'total_target_qty'    => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct'     => $achievementPct,
                'avg_actual_sph'      => $avgActualSph,
                'traffic_light'       => $this->getTrafficLight($achievementPct),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Dandori: aggregate menit_loss and menit_over by mesin × jenis_loss.
     * Mirrors Google Sheets getDashboardDandoriData() logic.
     */
    public function getVcbDandori(?string $bulan = null, ?string $week = null, ?int $mesinId = null, ?int $shiftId = null): array
    {
        $query = TransaksiDandori::with(['mesin', 'rincian.losstime'])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->when($mesinId, fn($q) => $q->where('id_mesin', $mesinId))
            ->when($bulan, function ($q) use ($bulan) {
                $monthNum = date('m', strtotime("1 {$bulan} 2000"));
                $q->whereMonth('tanggal', $monthNum);
            })
            ->when($week, function ($q) use ($week) {
                $weekNum = (int) str_replace('WEEK ', '', $week);
                $start   = ($weekNum - 1) * 7 + 1;
                $end     = min($weekNum * 7, 31);
                $q->whereDay('tanggal', '>=', $start)->whereDay('tanggal', '<=', $end);
            });

        $dandoriRecords = $query->get();

        // Build pivot: mesin → jenis_loss → {total_loss, total_over}
        $pivot   = [];
        $mesinMap = [];
        $jenisSet = [];

        foreach ($dandoriRecords as $d) {
            $mesinKey  = $d->mesin->nama_mesin;
            $mesinMap[$mesinKey] = $d->mesin;
            if (!isset($pivot[$mesinKey])) {
                $pivot[$mesinKey] = [];
            }
            foreach ($d->rincian as $r) {
                $jenis = $r->losstime->jenis_losstime;
                $jenisSet[$jenis] = true;
                if (!isset($pivot[$mesinKey][$jenis])) {
                    $pivot[$mesinKey][$jenis] = ['total_loss' => 0, 'total_over' => 0];
                }
                $pivot[$mesinKey][$jenis]['total_loss'] += $r->menit_loss;
                $pivot[$mesinKey][$jenis]['total_over'] += $r->menit_over;
            }
        }

        $jenisList = array_keys($jenisSet);
        sort($jenisList);

        $rows = [];
        foreach ($pivot as $mesinKey => $jenisPivot) {
            $row = ['mesin' => $mesinKey, 'total_loss' => 0, 'total_over' => 0];
            foreach ($jenisList as $jenis) {
                $row[$jenis . '_loss'] = $jenisPivot[$jenis]['total_loss'] ?? 0;
                $row[$jenis . '_over'] = $jenisPivot[$jenis]['total_over'] ?? 0;
                $row['total_loss'] += $row[$jenis . '_loss'];
                $row['total_over'] += $row[$jenis . '_over'];
            }
            $rows[] = $row;
        }

        return [
            'rows'        => $rows,
            'jenis_list'  => $jenisList,
        ];
    }

    /**
     * Traffic light: Green ≥ 100%, Yellow 85-99%, Red < 85%
     */
    public function getTrafficLight(float $achievementPct): string
    {
        if ($achievementPct >= 100) return 'success';
        if ($achievementPct >= 85)  return 'warning';
        return 'danger';
    }

    private function durationHours(string $jamStart, string $jamFinish): float
    {
        $startSec  = $this->timeToSeconds($jamStart);
        $finishSec = $this->timeToSeconds($jamFinish);
        $durationSec = $finishSec >= $startSec
            ? $finishSec - $startSec
            : (86400 - $startSec) + $finishSec;
        return $durationSec / 3600;
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        return ((int)($parts[0] ?? 0)) * 3600 + ((int)($parts[1] ?? 0)) * 60 + ((int)($parts[2] ?? 0));
    }
}
```

- [ ] **Step 4: Run tests**
```bash
php artisan test tests/Unit/Services/DashboardServiceTest.php
```
Expected: All PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Services/DashboardService.php tests/Unit/Services/DashboardServiceTest.php
git commit -m "feat: DashboardService with deterministic aggregation for all 4 VCB dashboards"
```

---

## Task 2: VCB Produksi Dashboard

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbProduksiController.php`
- Create: `resources/views/vcb/produksi.blade.php`
- Test: `tests/Feature/Vcb/VcbProduksiTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Vcb/VcbProduksiTest.php`:
```php
<?php
namespace Tests\Feature\Vcb;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VcbProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_vcb_produksi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/vcb/produksi')->assertStatus(200);
    }

    public function test_data_endpoint_returns_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
             ->get('/vcb/produksi/data?date_from=2026-05-01&date_to=2026-05-31')
             ->assertStatus(200)
             ->assertJsonStructure(['labels', 'datasets', 'table']);
    }

    public function test_staff_qc_cannot_view_vcb_produksi(): void
    {
        $staffQc = User::factory()->create(['role' => 'staff_qc']);
        $this->actingAs($staffQc)->get('/vcb/produksi')->assertStatus(403);
    }

    public function test_manager_can_view_vcb_produksi(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/vcb/produksi')->assertStatus(200);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Feature/Vcb/VcbProduksiTest.php
```

- [ ] **Step 3: Implement VcbProduksiController**

`app/Http/Controllers/Vcb/VcbProduksiController.php`:
```php
<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbProduksiController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        return view('vcb.produksi', compact('shifts'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows = $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId);

        // Build Chart.js format
        $labels   = array_column($rows, 'karyawan');
        $targets  = array_column($rows, 'total_target_qty');
        $actuals  = array_column($rows, 'total_qty_aktual');
        $colors   = array_map(fn($r) => $this->trafficColor($r['traffic_light']), $rows);
        $achievements = array_column($rows, 'achievement_pct');

        return response()->json([
            'labels'   => $labels,
            'datasets' => [
                [
                    'label'           => 'Target QTY',
                    'data'            => $targets,
                    'backgroundColor' => 'rgba(99,102,241,0.6)',
                ],
                [
                    'label'           => 'Actual QTY',
                    'data'            => $actuals,
                    'backgroundColor' => $colors,
                ],
            ],
            'table'        => $rows,
            'achievements' => $achievements,
        ]);
    }

    private function trafficColor(string $light): string
    {
        return match($light) {
            'success' => 'rgba(34,197,94,0.8)',
            'warning' => 'rgba(234,179,8,0.8)',
            default   => 'rgba(239,68,68,0.8)',
        };
    }
}
```

- [ ] **Step 4: Create VCB Produksi view**

`resources/views/vcb/produksi.blade.php`:
```html
@extends('layouts.app')
@section('title', 'VCB Produksi')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-bar-chart me-2"></i>VCB Produksi — Operator Achievement</h4>
</div>

{{-- Filter Bar --}}
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Tanggal Dari</label>
                <input type="date" id="date_from" name="date_from" class="form-control form-control-sm"
                       value="{{ date('Y-m-01') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Tanggal Sampai</label>
                <input type="date" id="date_to" name="date_to" class="form-control form-control-sm"
                       value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Shift</label>
                <select id="shift_id" name="shift_id" class="form-select form-select-sm">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $shift)
                        <option value="{{ $shift->id }}">{{ $shift->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search me-1"></i>Tampilkan
                </button>
            </div>
            <div class="col-md-2">
                <a id="exportExcel" href="#" class="btn btn-success btn-sm w-100">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Chart --}}
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <canvas id="vcbChart" height="100"></canvas>
    </div>
</div>

{{-- Table --}}
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Detail per Operator</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0" id="vcbTable">
                <thead class="table-dark">
                    <tr>
                        <th>Operator</th><th>Target QTY</th><th>Actual QTY</th>
                        <th>Duration (jam)</th><th>Achievement %</th><th>Avg Actual SPH</th>
                    </tr>
                </thead>
                <tbody id="vcbTableBody">
                    <tr><td colspan="6" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let vcbChart = null;

async function loadData() {
    const dateFrom = document.getElementById('date_from').value;
    const dateTo   = document.getElementById('date_to').value;
    const shiftId  = document.getElementById('shift_id').value;

    const params = new URLSearchParams({ date_from: dateFrom, date_to: dateTo, shift_id: shiftId });
    const res    = await fetch(`/vcb/produksi/data?${params}`);
    const data   = await res.json();

    // Update export link
    document.getElementById('exportExcel').href = `/export/produksi?${params}&format=excel`;

    // Update chart
    if (vcbChart) vcbChart.destroy();
    vcbChart = new Chart(document.getElementById('vcbChart'), {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: data.datasets,
        },
        options: {
            responsive: true,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        afterDatasetLabel: (ctx) => {
                            if (ctx.datasetIndex === 1 && data.achievements[ctx.dataIndex] !== undefined) {
                                return `Achievement: ${data.achievements[ctx.dataIndex]}%`;
                            }
                        }
                    }
                }
            },
            scales: { y: { beginAtZero: true } }
        }
    });

    // Update table
    const tbody = document.getElementById('vcbTableBody');
    if (data.table.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Tidak ada data untuk filter ini.</td></tr>';
        return;
    }
    tbody.innerHTML = data.table.map(row => {
        const badgeClass = row.traffic_light === 'success' ? 'bg-success' : row.traffic_light === 'warning' ? 'bg-warning text-dark' : 'bg-danger';
        return `<tr class="table-${row.traffic_light}">
            <td>${row.karyawan}</td>
            <td>${Number(row.total_target_qty).toLocaleString()}</td>
            <td>${Number(row.total_qty_aktual).toLocaleString()}</td>
            <td>${row.total_duration_hours}</td>
            <td><span class="badge ${badgeClass}">${row.achievement_pct}%</span></td>
            <td>${row.avg_actual_sph}</td>
        </tr>`;
    }).join('');
}

document.getElementById('filterForm').addEventListener('submit', (e) => {
    e.preventDefault();
    loadData();
});

// Auto-load on page open
loadData();
</script>
@endpush
```

- [ ] **Step 5: Run tests**
```bash
php artisan test tests/Feature/Vcb/VcbProduksiTest.php
```
Expected: 4 PASS

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/Vcb/VcbProduksiController.php \
        resources/views/vcb/produksi.blade.php \
        tests/Feature/Vcb/VcbProduksiTest.php
git commit -m "feat: VCB Produksi dashboard with Chart.js and AJAX filter"
```

---

## Task 3: VCB Mesin Dashboard

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbMesinController.php`
- Create: `resources/views/vcb/mesin.blade.php`

Follows exactly the same pattern as VcbProduksiController. Key differences:
- Calls `$dashboardService->getVcbMesin(...)` instead of `getVcbProduksi`
- Table columns: Mesin | ID Mesin | Line | Tipe | Target QTY | Actual QTY | Duration | Achievement % | Avg SPH

- [ ] **Step 1: Implement VcbMesinController**

`app/Http/Controllers/Vcb/VcbMesinController.php`:
```php
<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbMesinController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        return view('vcb.mesin', compact('shifts'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows = $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId);

        $labels  = array_column($rows, 'mesin');
        $targets = array_column($rows, 'total_target_qty');
        $actuals = array_column($rows, 'total_qty_aktual');
        $colors  = array_map(fn($r) => match($r['traffic_light']) {
            'success' => 'rgba(34,197,94,0.8)',
            'warning' => 'rgba(234,179,8,0.8)',
            default   => 'rgba(239,68,68,0.8)',
        }, $rows);

        return response()->json([
            'labels'       => $labels,
            'datasets'     => [
                ['label' => 'Target QTY', 'data' => $targets, 'backgroundColor' => 'rgba(99,102,241,0.6)'],
                ['label' => 'Actual QTY', 'data' => $actuals, 'backgroundColor' => $colors],
            ],
            'table'        => $rows,
            'achievements' => array_column($rows, 'achievement_pct'),
        ]);
    }
}
```

- [ ] **Step 2: Create VCB Mesin view**

`resources/views/vcb/mesin.blade.php` — same structure as `vcb/produksi.blade.php`.

Differences:
- Title: "VCB Mesin — Machine Achievement"
- Export URL: `/export/produksi?...` (same data source, different grouping)
- Table columns: Mesin | ID | Line | Tipe | Target QTY | Actual QTY | Duration (jam) | Achievement % | Avg SPH
- JS calls `/vcb/mesin/data` instead of `/vcb/produksi/data`

Copy the entire `vcb/produksi.blade.php` and update:
1. `@section('title', 'VCB Mesin')`
2. The `<h4>` heading text
3. `fetch('/vcb/mesin/data?...')`
4. Export link to `/export/produksi?${params}&group=mesin&format=excel`
5. Table headers and `tbody.innerHTML` mapping (use `row.mesin`, add `row.id_mesin`, `row.line`, `row.tipe_mesin` columns)

- [ ] **Step 3: Commit**
```bash
git add app/Http/Controllers/Vcb/VcbMesinController.php resources/views/vcb/mesin.blade.php
git commit -m "feat: VCB Mesin dashboard"
```

---

## Task 4: VCB Quality Dashboard

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbQualityController.php`
- Create: `resources/views/vcb/quality.blade.php`

- [ ] **Step 1: Implement VcbQualityController**

`app/Http/Controllers/Vcb/VcbQualityController.php`:
```php
<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbQualityController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        return view('vcb.quality', compact('shifts'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows = $this->dashboardService->getVcbQuality($dateFrom, $dateTo, $shiftId);

        $labels  = array_column($rows, 'karyawan');
        $targets = array_column($rows, 'total_target_qty');
        $ok      = array_column($rows, 'total_qty_ok');
        $ng      = array_column($rows, 'total_qty_ng');
        $colors  = array_map(fn($r) => match($r['traffic_light']) {
            'success' => 'rgba(34,197,94,0.8)',
            'warning' => 'rgba(234,179,8,0.8)',
            default   => 'rgba(239,68,68,0.8)',
        }, $rows);

        return response()->json([
            'labels'   => $labels,
            'datasets' => [
                ['label' => 'Target QTY',  'data' => $targets, 'backgroundColor' => 'rgba(99,102,241,0.6)'],
                ['label' => 'Qty OK',      'data' => $ok,      'backgroundColor' => $colors],
                ['label' => 'Qty NG',      'data' => $ng,      'backgroundColor' => 'rgba(239,68,68,0.5)'],
            ],
            'table'        => $rows,
            'achievements' => array_column($rows, 'achievement_pct'),
        ]);
    }
}
```

- [ ] **Step 2: Create VCB Quality view**

`resources/views/vcb/quality.blade.php` — same structure as produksi view.

Differences:
- Title: "VCB Quality — QC Inspector Achievement"
- JS calls `/vcb/quality/data`
- Export: `/export/qc?${params}&format=excel`
- Table columns: QC Inspector | Target QTY | Qty OK | Qty NG | Total Check | Achievement % | Avg SPH QC
- Table row maps `row.karyawan`, `row.total_target_qty`, `row.total_qty_ok`, `row.total_qty_ng`, `row.total_qty_check`, `row.achievement_pct`, `row.avg_actual_sph`

- [ ] **Step 3: Commit**
```bash
git add app/Http/Controllers/Vcb/VcbQualityController.php resources/views/vcb/quality.blade.php
git commit -m "feat: VCB Quality dashboard with 3-dataset chart (target, ok, ng)"
```

---

## Task 5: VCB Dandori Dashboard

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbDandoriController.php`
- Create: `resources/views/vcb/dandori.blade.php`

This dashboard has unique filters (bulan, week, mesin) and uses a **stacked bar chart** instead of grouped.

- [ ] **Step 1: Implement VcbDandoriController**

`app/Http/Controllers/Vcb/VcbDandoriController.php`:
```php
<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class VcbDandoriController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $mesin  = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $weeks  = ['WEEK 1','WEEK 2','WEEK 3','WEEK 4','WEEK 5'];
        return view('vcb.dandori', compact('shifts', 'mesin', 'months', 'weeks'));
    }

    public function data(Request $request)
    {
        $bulan   = $request->input('bulan');
        $week    = $request->input('week');
        $mesinId = $request->filled('mesin_id') ? (int) $request->input('mesin_id') : null;
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $result    = $this->dashboardService->getVcbDandori($bulan, $week, $mesinId, $shiftId);
        $rows      = $result['rows'];
        $jenisList = $result['jenis_list'];

        // Build stacked chart datasets — one dataset per jenis_loss
        $labels   = array_column($rows, 'mesin');
        $colors   = $this->generateColors(count($jenisList));
        $datasets = [];
        foreach ($jenisList as $i => $jenis) {
            $datasets[] = [
                'label'           => $jenis,
                'data'            => array_map(fn($r) => $r[$jenis . '_loss'] ?? 0, $rows),
                'backgroundColor' => $colors[$i],
                'stack'           => 'loss',
            ];
        }

        // Second chart: menit_over stacked
        $datasetsOver = [];
        foreach ($jenisList as $i => $jenis) {
            $datasetsOver[] = [
                'label'           => $jenis,
                'data'            => array_map(fn($r) => $r[$jenis . '_over'] ?? 0, $rows),
                'backgroundColor' => $colors[$i],
                'stack'           => 'over',
            ];
        }

        return response()->json([
            'labels'        => $labels,
            'datasets_loss' => $datasets,
            'datasets_over' => $datasetsOver,
            'jenis_list'    => $jenisList,
            'table'         => $rows,
        ]);
    }

    private function generateColors(int $count): array
    {
        $palette = [
            'rgba(239,68,68,0.75)',   'rgba(249,115,22,0.75)',  'rgba(234,179,8,0.75)',
            'rgba(34,197,94,0.75)',   'rgba(6,182,212,0.75)',   'rgba(99,102,241,0.75)',
            'rgba(168,85,247,0.75)',  'rgba(236,72,153,0.75)',  'rgba(156,163,175,0.75)',
        ];
        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = $palette[$i % count($palette)];
        }
        return $colors;
    }
}
```

- [ ] **Step 2: Create VCB Dandori view**

`resources/views/vcb/dandori.blade.php`:
```html
@extends('layouts.app')
@section('title', 'VCB Dandori')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>VCB Dandori — Loss Time Analysis</h4>
</div>

{{-- Filter Bar --}}
<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Bulan</label>
                <select id="bulan" name="bulan" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($months as $m)
                        <option value="{{ $m }}" {{ $m === date('M') ? 'selected' : '' }}>{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Week</label>
                <select id="week" name="week" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($weeks as $w)
                        <option value="{{ $w }}">{{ $w }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Mesin</label>
                <select id="mesin_id" name="mesin_id" class="form-select form-select-sm">
                    <option value="">Semua Mesin</option>
                    @foreach($mesin as $m)
                        <option value="{{ $m->id }}">{{ $m->nama_mesin }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Shift</label>
                <select id="shift_id" name="shift_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100">
                    <i class="bi bi-search"></i>
                </button>
            </div>
            <div class="col-md-2">
                <a id="exportExcel" href="#" class="btn btn-success btn-sm w-100">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Charts: side by side --}}
<div class="row mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Total Loss per Mesin (menit)</div>
            <div class="card-body"><canvas id="chartLoss" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Total Over-Standard per Mesin (menit)</div>
            <div class="card-body"><canvas id="chartOver" height="160"></canvas></div>
        </div>
    </div>
</div>

{{-- Summary Table --}}
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Summary per Mesin</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-dark" id="tableHead">
                    <tr><th>Mesin</th><th>Total Loss</th><th>Total Over</th></tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="3" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let chartLoss = null;
let chartOver = null;

async function loadData() {
    const params = new URLSearchParams({
        bulan:    document.getElementById('bulan').value,
        week:     document.getElementById('week').value,
        mesin_id: document.getElementById('mesin_id').value,
        shift_id: document.getElementById('shift_id').value,
    });

    document.getElementById('exportExcel').href = `/export/dandori?${params}&format=excel`;

    const res  = await fetch(`/vcb/dandori/data?${params}`);
    const data = await res.json();

    const chartOptions = {
        responsive: true,
        plugins: { legend: { position: 'right' } },
        scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Menit' } }
        }
    };

    if (chartLoss) chartLoss.destroy();
    chartLoss = new Chart(document.getElementById('chartLoss'), {
        type: 'bar',
        data: { labels: data.labels, datasets: data.datasets_loss },
        options: { ...chartOptions },
    });

    if (chartOver) chartOver.destroy();
    chartOver = new Chart(document.getElementById('chartOver'), {
        type: 'bar',
        data: { labels: data.labels, datasets: data.datasets_over },
        options: { ...chartOptions },
    });

    // Build dynamic table headers
    const jenisList = data.jenis_list;
    const thead = document.getElementById('tableHead');
    const theadHtml = `<tr>
        <th>Mesin</th>
        ${jenisList.map(j => `<th>${j} (Loss)</th><th>${j} (Over)</th>`).join('')}
        <th>Total Loss</th><th>Total Over</th>
    </tr>`;
    thead.innerHTML = theadHtml;

    const tbody = document.getElementById('tableBody');
    if (data.table.length === 0) {
        tbody.innerHTML = `<td colspan="${2 + jenisList.length * 2 + 2}" class="text-center text-muted py-3">Tidak ada data.</td>`;
        return;
    }
    tbody.innerHTML = data.table.map(row => `
        <tr>
            <td><strong>${row.mesin}</strong></td>
            ${jenisList.map(j => `<td>${row[j + '_loss'] ?? 0}</td><td>${row[j + '_over'] ?? 0}</td>`).join('')}
            <td><strong>${row.total_loss}</strong></td>
            <td><strong>${row.total_over}</strong></td>
        </tr>
    `).join('');
}

document.getElementById('filterForm').addEventListener('submit', (e) => {
    e.preventDefault();
    loadData();
});

loadData();
</script>
@endpush
```

- [ ] **Step 3: Commit**
```bash
git add app/Http/Controllers/Vcb/VcbDandoriController.php resources/views/vcb/dandori.blade.php
git commit -m "feat: VCB Dandori dashboard with stacked charts and dynamic table"
```

---

## Task 6: Export (Excel + PDF)

**Files:**
- Create: `app/Exports/ProduksiExport.php`
- Create: `app/Exports/QcExport.php`
- Create: `app/Exports/DandoriExport.php`
- Modify: `app/Http/Controllers/ExportController.php`
- Create: `resources/views/exports/produksi_pdf.blade.php`
- Create: `resources/views/exports/qc_pdf.blade.php`
- Create: `resources/views/exports/dandori_pdf.blade.php`

- [ ] **Step 1: Create ProduksiExport**

`app/Exports/ProduksiExport.php`:
```php
<?php
namespace App\Exports;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class ProduksiExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private DashboardService $service,
        private Carbon $dateFrom,
        private Carbon $dateTo,
        private ?int $shiftId,
        private string $groupBy = 'karyawan'
    ) {}

    public function title(): string { return 'VCB Produksi'; }

    public function headings(): array
    {
        return ['Operator/Mesin', 'Target QTY', 'Actual QTY', 'Duration (jam)', 'Achievement %', 'Avg Actual SPH'];
    }

    public function collection()
    {
        $rows = $this->groupBy === 'mesin'
            ? $this->service->getVcbMesin($this->dateFrom, $this->dateTo, $this->shiftId)
            : $this->service->getVcbProduksi($this->dateFrom, $this->dateTo, $this->shiftId);

        return collect($rows)->map(fn($r) => [
            $this->groupBy === 'mesin' ? ($r['mesin'] ?? '-') : ($r['karyawan'] ?? '-'),
            $r['total_target_qty'],
            $r['total_qty_aktual'],
            $r['total_duration_hours'],
            $r['achievement_pct'] . '%',
            $r['avg_actual_sph'],
        ]);
    }
}
```

`app/Exports/QcExport.php`:
```php
<?php
namespace App\Exports;
use App\Services\DashboardService;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class QcExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private DashboardService $service,
        private Carbon $dateFrom,
        private Carbon $dateTo,
        private ?int $shiftId
    ) {}

    public function title(): string { return 'VCB Quality'; }

    public function headings(): array
    {
        return ['QC Inspector', 'Target QTY', 'Qty OK', 'Qty NG', 'Total Check', 'Achievement %', 'Avg SPH QC'];
    }

    public function collection()
    {
        $rows = $this->service->getVcbQuality($this->dateFrom, $this->dateTo, $this->shiftId);
        return collect($rows)->map(fn($r) => [
            $r['karyawan'], $r['total_target_qty'],
            $r['total_qty_ok'], $r['total_qty_ng'], $r['total_qty_check'],
            $r['achievement_pct'] . '%', $r['avg_actual_sph'],
        ]);
    }
}
```

`app/Exports/DandoriExport.php`:
```php
<?php
namespace App\Exports;
use App\Services\DashboardService;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

class DandoriExport implements FromCollection, WithHeadings, WithTitle
{
    public function __construct(
        private DashboardService $service,
        private ?string $bulan,
        private ?string $week,
        private ?int $mesinId,
        private ?int $shiftId
    ) {}

    public function title(): string { return 'VCB Dandori'; }

    public function headings(): array
    {
        return ['Mesin', 'Total Loss (menit)', 'Total Over (menit)'];
    }

    public function collection()
    {
        $result = $this->service->getVcbDandori($this->bulan, $this->week, $this->mesinId, $this->shiftId);
        return collect($result['rows'])->map(fn($r) => [
            $r['mesin'], $r['total_loss'], $r['total_over'],
        ]);
    }
}
```

- [ ] **Step 2: Create PDF Blade views**

`resources/views/exports/produksi_pdf.blade.php`:
```html
<!DOCTYPE html><html>
<head>
<style>
    body { font-family: sans-serif; font-size: 11px; }
    h2 { text-align: center; margin-bottom: 4px; }
    p  { text-align: center; color: #666; margin-top: 0; }
    table { width: 100%; border-collapse: collapse; margin-top: 12px; }
    th { background: #1a1a2e; color: #fff; padding: 6px; text-align: left; }
    td { padding: 5px 6px; border-bottom: 1px solid #eee; }
    .green { background: #dcfce7; }
    .yellow { background: #fef9c3; }
    .red { background: #fee2e2; }
</style>
</head>
<body>
<h2>VCB Produksi — Operator Achievement</h2>
<p>{{ $dateFrom }} s/d {{ $dateTo }}{{ $shiftLabel ? ' | ' . $shiftLabel : '' }}</p>
<table>
    <thead><tr><th>Operator</th><th>Target QTY</th><th>Actual QTY</th><th>Duration</th><th>Achievement %</th><th>Avg SPH</th></tr></thead>
    <tbody>
    @foreach($rows as $row)
        @php $cls = $row['traffic_light'] === 'success' ? 'green' : ($row['traffic_light'] === 'warning' ? 'yellow' : 'red'); @endphp
        <tr class="{{ $cls }}">
            <td>{{ $row['karyawan'] }}</td>
            <td>{{ number_format($row['total_target_qty']) }}</td>
            <td>{{ number_format($row['total_qty_aktual']) }}</td>
            <td>{{ $row['total_duration_hours'] }} jam</td>
            <td>{{ $row['achievement_pct'] }}%</td>
            <td>{{ $row['avg_actual_sph'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<p style="margin-top:20px;color:#999">Generated: {{ now()->format('d/m/Y H:i') }} | OPTIMA</p>
</body></html>
```

`resources/views/exports/qc_pdf.blade.php` — same structure, columns: QC Inspector | Target | OK | NG | Total | Achievement % | SPH.

`resources/views/exports/dandori_pdf.blade.php` — same structure, columns: Mesin | Total Loss | Total Over.

- [ ] **Step 3: Implement ExportController**

`app/Http/Controllers/ExportController.php`:
```php
<?php
namespace App\Http\Controllers;
use App\Exports\ProduksiExport;
use App\Exports\QcExport;
use App\Exports\DandoriExport;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function produksi(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $groupBy  = $request->input('group', 'karyawan');
        $format   = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $rows = $groupBy === 'mesin'
                ? $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId)
                : $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId);
            $pdf = Pdf::loadView('exports.produksi_pdf', [
                'rows'       => $rows,
                'dateFrom'   => $dateFrom->format('d/m/Y'),
                'dateTo'     => $dateTo->format('d/m/Y'),
                'shiftLabel' => null,
            ])->setPaper('a4', 'landscape');
            return $pdf->download("vcb_produksi_{$dateFrom->format('Ymd')}.pdf");
        }

        return Excel::download(
            new ProduksiExport($this->dashboardService, $dateFrom, $dateTo, $shiftId, $groupBy),
            "vcb_produksi_{$dateFrom->format('Ymd')}.xlsx"
        );
    }

    public function qc(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $format   = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $rows = $this->dashboardService->getVcbQuality($dateFrom, $dateTo, $shiftId);
            $pdf  = Pdf::loadView('exports.qc_pdf', [
                'rows' => $rows, 'dateFrom' => $dateFrom->format('d/m/Y'), 'dateTo' => $dateTo->format('d/m/Y'),
            ])->setPaper('a4', 'landscape');
            return $pdf->download("vcb_quality_{$dateFrom->format('Ymd')}.pdf");
        }

        return Excel::download(
            new QcExport($this->dashboardService, $dateFrom, $dateTo, $shiftId),
            "vcb_quality_{$dateFrom->format('Ymd')}.xlsx"
        );
    }

    public function dandori(Request $request)
    {
        $bulan   = $request->input('bulan');
        $week    = $request->input('week');
        $mesinId = $request->filled('mesin_id') ? (int) $request->input('mesin_id') : null;
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $format  = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $result = $this->dashboardService->getVcbDandori($bulan, $week, $mesinId, $shiftId);
            $pdf    = Pdf::loadView('exports.dandori_pdf', [
                'rows' => $result['rows'], 'bulan' => $bulan, 'week' => $week,
            ])->setPaper('a4', 'landscape');
            return $pdf->download("vcb_dandori.pdf");
        }

        return Excel::download(
            new DandoriExport($this->dashboardService, $bulan, $week, $mesinId, $shiftId),
            "vcb_dandori.xlsx"
        );
    }
}
```

- [ ] **Step 4: Register Laravel Excel + DomPDF service providers (if not auto-discovered)**

Check `config/app.php` providers section — if `Maatwebsite\Excel\ExcelServiceProvider` and `Barryvdh\DomPDF\ServiceProvider` are not listed, add them:
```bash
php artisan vendor:publish --provider="Maatwebsite\Excel\ExcelServiceProvider" --tag=config
php artisan vendor:publish --provider="Barryvdh\DomPDF\ServiceProvider"
```

- [ ] **Step 5: Test export endpoints manually**

Open browser as admin:
1. Go to VCB Produksi, set date range, click Export Excel → verify `.xlsx` downloads with correct data
2. Change URL to `?format=pdf` → verify PDF renders with traffic light row colors
3. Repeat for QC and Dandori

- [ ] **Step 6: Commit**
```bash
git add app/Exports/ app/Http/Controllers/ExportController.php resources/views/exports/
git commit -m "feat: Excel and PDF export for VCB Produksi, Quality, and Dandori"
```

---

## Task 7: Final Plan 3 Verification

- [ ] **Step 1: Run full test suite**
```bash
php artisan test
```
Expected: All tests PASS, 0 failures.

- [ ] **Step 2: Migrate fresh + seed + verify**
```bash
php artisan migrate:fresh --seed
```

- [ ] **Step 3: End-to-end smoke test**
1. Login as admin → input 3 produksi records across 2 operators → open VCB Produksi → verify chart shows 2 operators with correct achievement %
2. Open VCB Mesin → verify same data grouped by mesin
3. Login as staff_qc → input 2 QC records → open VCB Quality → verify chart correct
4. Login as staff_produksi → input 3 dandori records → open VCB Dandori → verify stacked chart and summary table
5. Export each dashboard as Excel and PDF → verify files download correctly
6. Login as manager → verify can see all VCB but cannot access Input Data menu

- [ ] **Step 4: Final commit**
```bash
git add .
git commit -m "feat: Plan 3 complete — all 4 VCB dashboards and export functionality"
```

---

**OPTIMA v1.0 is complete.** All 3 plans delivered:
- Plan 1: Foundation + Auth + Master Data CRUD
- Plan 2: Service Layer + Input Forms (Produksi, QC, Dandori)
- Plan 3: VCB Dashboards (4) + Export (Excel/PDF)
