# Control Stroke Mesin & Dies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 2 new maintenance pages — Control Stroke Mesin & Control Stroke Dies — showing cumulative press-stroke totals (`SUM(qty_aktual)`) per Mesin and per Dies (part+proses), computed from `TransaksiProduksi`, filterable by date range.

**Architecture:** Reuse existing `MaintenanceDashboardService` / `MaintenanceDiesDashboardService` (add `computeStroke()` method to each, no new service class). New controllers/routes/views clone the existing Availability pages' structure exactly (filter form, KPI card, Chart.js bar, table), swapping the data source. No migrations — pure aggregate query over the existing `transaksi_produksi` table.

**Tech Stack:** Laravel 13 / PHP, Eloquent, Blade, Alpine.js (`taField` autocomplete component, copy-pasted per existing convention), Chart.js 4.4.4, PHPUnit + RefreshDatabase.

## Global Constraints

- 1 stroke = 1 `qty_aktual` (no multiplier). Source: `TransaksiProduksi.qty_aktual`.
- "Dies" unit = `MasterPartProses` row (part+proses), matching `MaintenanceDiesDashboardService` convention.
- Date range only — no all-time running total, no reset-counter feature.
- No threshold/traffic-light coloring — plain table + monochrome bar chart (`#1f2937`).
- No new migrations, no new tables/columns.
- Role gate: `admin, staff_maintenance, manager` (same as existing Availability routes).
- Route names: `maintenance.control-stroke-mesin`, `maintenance.control-stroke-mesin.data`, `maintenance.control-stroke-dies`, `maintenance.control-stroke-dies.data`.
- Route paths: `maintenance/availability/control-stroke-mesin`, `maintenance/availability/control-stroke-dies`.

---

## File Structure

- Modify `app/Services/MaintenanceDashboardService.php` — add `computeStroke()`.
- Modify `app/Services/MaintenanceDiesDashboardService.php` — add `computeStroke()`.
- Create `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`.
- Create `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`.
- Modify `routes/web.php` — add 4 routes inside the existing `maintenance.` staff_maintenance group (line 168 is the last route inside it, line 169 is the closing `});`).
- Create `resources/views/maintenance/control-stroke-mesin.blade.php`.
- Create `resources/views/maintenance/control-stroke-dies.blade.php`.
- Modify `resources/views/layouts/app.blade.php` — add nav links in "MTC Mesin" (~line 1047) and "MTC Dies" (~line 1067) groups.
- Create `tests/Unit/MaintenanceDashboardServiceStrokeTest.php`.
- Create `tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php`.
- Create `tests/Feature/Maintenance/ControlStrokeMesinTest.php`.
- Create `tests/Feature/Maintenance/ControlStrokeDiesTest.php`.

---

### Task 1: `MaintenanceDashboardService::computeStroke()`

**Files:**
- Modify: `app/Services/MaintenanceDashboardService.php`
- Test: `tests/Unit/MaintenanceDashboardServiceStrokeTest.php`

**Interfaces:**
- Consumes: `App\Models\MasterMesin` (`id`, `id_mesin`, `nama_mesin`, `tipe_mesin`), `App\Models\TransaksiProduksi` (`id_mesin`, `qty_aktual`, `tanggal_produksi`).
- Produces: `MaintenanceDashboardService::computeStroke(Carbon $from, Carbon $to, ?int $mesinId = null, ?string $tipeMesin = null): array` — each row: `['id_mesin' => int, 'kode_mesin' => string, 'nama_mesin' => string, 'total_stroke' => int]`. Used by Task 3's `ControlStrokeMesinController`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/MaintenanceDashboardServiceStrokeTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/MaintenanceDashboardServiceStrokeTest.php`
Expected: FAIL — `Call to undefined method App\Services\MaintenanceDashboardService::computeStroke()`

- [ ] **Step 3: Implement `computeStroke()`**

In `app/Services/MaintenanceDashboardService.php`, add `use App\Models\TransaksiProduksi;` to the imports at the top, then add this method inside the class (after `compute()`):

```php
    public function computeStroke(Carbon $from, Carbon $to, ?int $mesinId = null, ?string $tipeMesin = null): array
    {
        $hasTipe = Schema::hasColumn('master_mesin', 'tipe_mesin');

        $mesins = MasterMesin::when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
            ->orderBy('id_mesin')->get();

        $stats = TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->selectRaw('id_mesin, SUM(qty_aktual) as total_stroke')
            ->groupBy('id_mesin')
            ->pluck('total_stroke', 'id_mesin');

        return $mesins->map(fn ($m) => [
            'id_mesin'     => $m->id,
            'kode_mesin'   => $m->id_mesin,
            'nama_mesin'   => $m->nama_mesin,
            'total_stroke' => (int) ($stats->get($m->id) ?? 0),
        ])->values()->all();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/MaintenanceDashboardServiceStrokeTest.php`
Expected: PASS (4/4)

- [ ] **Step 5: Commit**

```bash
git add app/Services/MaintenanceDashboardService.php tests/Unit/MaintenanceDashboardServiceStrokeTest.php
git commit -m "feat(maintenance): add computeStroke() to MaintenanceDashboardService"
```

---

### Task 2: `MaintenanceDiesDashboardService::computeStroke()`

**Files:**
- Modify: `app/Services/MaintenanceDiesDashboardService.php`
- Test: `tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php`

**Interfaces:**
- Consumes: `App\Models\MasterPartProses` (`id`, `id_part`, `nama_proses`, relation `part()`), `App\Models\MasterPart` (`part_no`, `part_name`, `customer`), `App\Models\TransaksiProduksi` (`id_part_proses`, `qty_aktual`, `tanggal_produksi`, relation `partProses()`).
- Produces: `MaintenanceDiesDashboardService::computeStroke(Carbon $from, Carbon $to, ?int $partId = null, ?string $customer = null): array` — each row: `['id_mesin' => int, 'kode_mesin' => string, 'nama_mesin' => string, 'nama_proses' => string, 'total_stroke' => int]` (key names `id_mesin`/`kode_mesin`/`nama_mesin` intentionally mirror the mesin service, matching the existing `compute()` convention in this file). Rows with `total_stroke === 0` are omitted. Used by Task 4's `ControlStrokeDiesController`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php`:

```php
<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceDiesDashboardServiceStrokeTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create([
            'id_mesin' => 'SD'.uniqid(), 'nama_mesin' => 'Stroke Dies Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        return compact('user', 'shift', 'karyawan', 'mesin');
    }

    private function partProses(string $customer = 'Toyota'): MasterPartProses
    {
        $part = MasterPart::create(['part_no' => 'SD-P'.uniqid(), 'part_name' => 'Stroke Dies Part', 'customer' => $customer]);
        return MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    private function produksi(array $f, MasterPartProses $proses, string $tanggal, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty,
            'created_by' => $f['user']->id,
        ]);
    }

    public function test_total_stroke_sums_qty_aktual_per_dies(): void
    {
        $f  = $this->fixtures();
        $pr = $this->partProses();
        $this->produksi($f, $pr, '2026-06-01', 300);
        $this->produksi($f, $pr, '2026-06-10', 80);
        $this->produksi($f, $pr, '2026-06-20', 70);

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(450, $rows[0]['total_stroke']);
        $this->assertSame($pr->part->part_no, $rows[0]['kode_mesin']);
    }

    public function test_dies_with_no_transaksi_is_omitted(): void
    {
        $f = $this->fixtures();
        $this->partProses(); // no TransaksiProduksi created for it

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')
        );

        $this->assertCount(0, $rows);
    }

    public function test_part_id_filter_narrows_to_single_part(): void
    {
        $f   = $this->fixtures();
        $pr1 = $this->partProses();
        $pr2 = $this->partProses();
        $this->produksi($f, $pr1, '2026-06-01', 100);
        $this->produksi($f, $pr2, '2026-06-01', 999);

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), $pr1->id_part
        );

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['total_stroke']);
    }

    public function test_customer_filter_narrows_result(): void
    {
        $f   = $this->fixtures();
        $pr1 = $this->partProses('Toyota');
        $pr2 = $this->partProses('Honda');
        $this->produksi($f, $pr1, '2026-06-01', 100);
        $this->produksi($f, $pr2, '2026-06-01', 200);

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), null, 'Honda'
        );

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['total_stroke']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php`
Expected: FAIL — `Call to undefined method App\Services\MaintenanceDiesDashboardService::computeStroke()`

- [ ] **Step 3: Implement `computeStroke()`**

In `app/Services/MaintenanceDiesDashboardService.php`, add `use App\Models\TransaksiProduksi;` to the imports at the top, then add this method inside the class (after `compute()`):

```php
    public function computeStroke(Carbon $from, Carbon $to, ?int $partId = null, ?string $customer = null): array
    {
        $partProsesList = MasterPartProses::with('part')
            ->when($partId, fn ($q) => $q->where('id_part', $partId))
            ->when($customer, fn ($q) => $q->whereHas('part', fn ($p) => $p->where('customer', $customer)))
            ->get()
            ->sortBy(fn ($pr) => $pr->part->part_no ?? '');

        $stats = TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->when($partId, fn ($q) => $q->whereHas('partProses', fn ($p) => $p->where('id_part', $partId)))
            ->selectRaw('id_part_proses, SUM(qty_aktual) as total_stroke')
            ->groupBy('id_part_proses')
            ->pluck('total_stroke', 'id_part_proses');

        $rows = [];
        foreach ($partProsesList as $pr) {
            $total = (int) ($stats->get($pr->id) ?? 0);
            if ($total === 0) {
                continue;
            }
            $rows[] = [
                'id_mesin'     => $pr->id,
                'kode_mesin'   => $pr->part?->part_no,
                'nama_mesin'   => $pr->part?->part_name,
                'nama_proses'  => $pr->nama_proses,
                'total_stroke' => $total,
            ];
        }

        return array_values($rows);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php`
Expected: PASS (4/4)

- [ ] **Step 5: Commit**

```bash
git add app/Services/MaintenanceDiesDashboardService.php tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php
git commit -m "feat(maintenance): add computeStroke() to MaintenanceDiesDashboardService"
```

---

### Task 3: `ControlStrokeMesinController` + routes

**Files:**
- Create: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`
- Modify: `routes/web.php:169` (insert before the `});` closing the `role:admin,staff_maintenance,manager` maintenance group)
- Test: `tests/Feature/Maintenance/ControlStrokeMesinTest.php`

**Interfaces:**
- Consumes: `MaintenanceDashboardService::computeStroke()` (Task 1), `ResolvesMaintenanceRange::resolveRange()` (existing trait at `app/Http/Controllers/Maintenance/Concerns/ResolvesMaintenanceRange.php`), `App\Models\MasterMesin::TIPE_MESIN` (existing const).
- Produces: routes `maintenance.control-stroke-mesin` (GET, view) and `maintenance.control-stroke-mesin.data` (GET, JSON `{rows: [...], summary: {total_stroke: int}}`). View name `maintenance.control-stroke-mesin` consumed by Task 5.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Maintenance/ControlStrokeMesinTest.php`:

```php
<?php
namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlStrokeMesinTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_control_stroke_mesin_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('maintenance.control-stroke-mesin'));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin');
    }

    public function test_staff_produksi_cannot_access_control_stroke_mesin(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->get(route('maintenance.control-stroke-mesin'));

        $response->assertForbidden();
    }

    public function test_data_endpoint_returns_total_stroke_summary(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create(['id_mesin' => 'CS-01', 'nama_mesin' => 'CS Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part     = MasterPart::create(['part_no' => 'CS-P1', 'part_name' => 'CS Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => 250,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('maintenance.control-stroke-mesin.data', [
            'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('summary.total_stroke', 250);
        $response->assertJsonFragment(['kode_mesin' => 'CS-01', 'total_stroke' => 250]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: FAIL — route `maintenance.control-stroke-mesin` not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`:

```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Maintenance\Concerns\ResolvesMaintenanceRange;
use App\Models\MasterMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;

class ControlStrokeMesinController extends Controller
{
    use ResolvesMaintenanceRange;

    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.control-stroke-mesin', [
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'tipeList'  => MasterMesin::TIPE_MESIN,
        ]);
    }

    public function data(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $mesinId   = (int) $request->input('mesin_id', 0) ?: null;
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows      = $this->svc->computeStroke($from, $to, $mesinId, $tipeMesin);

        return response()->json([
            'rows'    => $rows,
            'summary' => ['total_stroke' => (int) collect($rows)->sum('total_stroke')],
        ]);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, find the `maintenance.` group with middleware `role:admin,staff_maintenance,manager` (the `// ── MTC DIES ──` block's last route is at line 168, closing `});` at line 169). Add these two lines right before that closing `});`:

```php
        Route::get('availability/control-stroke-mesin',      [ControlStrokeMesinController::class, 'index'])->name('control-stroke-mesin');
        Route::get('availability/control-stroke-mesin/data',  [ControlStrokeMesinController::class, 'data'])->name('control-stroke-mesin.data');
```

Add the corresponding `use` import near the other `Maintenance\` controller imports at the top of `routes/web.php`:

```php
use App\Http\Controllers\Maintenance\ControlStrokeMesinController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: FAIL on `test_admin_can_view_control_stroke_mesin_page` and `test_data_endpoint_returns_total_stroke_summary` (view doesn't exist yet — that's Task 5), but `test_staff_produksi_cannot_access_control_stroke_mesin` should PASS. This is expected; the view-dependent tests go green in Task 5.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeMesinController.php routes/web.php tests/Feature/Maintenance/ControlStrokeMesinTest.php
git commit -m "feat(maintenance): add ControlStrokeMesinController and routes"
```

---

### Task 4: `ControlStrokeDiesController` + routes

**Files:**
- Create: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
- Modify: `routes/web.php` (same group as Task 3)
- Test: `tests/Feature/Maintenance/ControlStrokeDiesTest.php`

**Interfaces:**
- Consumes: `MaintenanceDiesDashboardService::computeStroke()` (Task 2).
- Produces: routes `maintenance.control-stroke-dies` (GET, view) and `maintenance.control-stroke-dies.data` (GET, JSON `{rows: [...], summary: {total_stroke: int}}`). View name `maintenance.control-stroke-dies` consumed by Task 6.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/Maintenance/ControlStrokeDiesTest.php`:

```php
<?php
namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlStrokeDiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_control_stroke_dies_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('maintenance.control-stroke-dies'));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-dies');
    }

    public function test_staff_produksi_cannot_access_control_stroke_dies(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->get(route('maintenance.control-stroke-dies'));

        $response->assertForbidden();
    }

    public function test_data_endpoint_returns_total_stroke_summary(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create(['id_mesin' => 'CD-01', 'nama_mesin' => 'CD Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part     = MasterPart::create(['part_no' => 'CD-P1', 'part_name' => 'CD Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => 450,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('maintenance.control-stroke-dies.data', [
            'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('summary.total_stroke', 450);
        $response->assertJsonFragment(['kode_mesin' => 'CD-P1', 'total_stroke' => 450]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: FAIL — route `maintenance.control-stroke-dies` not defined.

- [ ] **Step 3: Create the controller**

Create `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`:

```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterPart;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ControlStrokeDiesController extends Controller
{
    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.control-stroke-dies', [
            'partList'  => MasterPart::orderBy('part_no')->get(),
            'customers' => MasterPart::whereNotNull('customer')->where('customer', '!=', '')->distinct()->orderBy('customer')->pluck('customer'),
        ]);
    }

    public function data(Request $request)
    {
        $from     = Carbon::parse($request->input('date_from', date('Y-01-01')));
        $to       = Carbon::parse($request->input('date_to', date('Y-m-d')));
        $partId   = (int) $request->input('part_id', 0) ?: null;
        $customer = trim((string) $request->input('customer', '')) ?: null;
        $rows     = $this->svc->computeStroke($from, $to, $partId, $customer);

        return response()->json([
            'rows'    => $rows,
            'summary' => ['total_stroke' => (int) collect($rows)->sum('total_stroke')],
        ]);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add these two lines directly after the two added in Task 3 (same group, before its closing `});`):

```php
        Route::get('availability/control-stroke-dies',       [ControlStrokeDiesController::class, 'index'])->name('control-stroke-dies');
        Route::get('availability/control-stroke-dies/data',   [ControlStrokeDiesController::class, 'data'])->name('control-stroke-dies.data');
```

Add the corresponding `use` import next to the one added in Task 3:

```php
use App\Http\Controllers\Maintenance\ControlStrokeDiesController;
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: `test_staff_produksi_cannot_access_control_stroke_dies` PASSES now; the other two still FAIL until Task 6 adds the view. Expected at this point.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeDiesController.php routes/web.php tests/Feature/Maintenance/ControlStrokeDiesTest.php
git commit -m "feat(maintenance): add ControlStrokeDiesController and routes"
```

---

### Task 5: `control-stroke-mesin.blade.php` view

**Files:**
- Create: `resources/views/maintenance/control-stroke-mesin.blade.php`

**Interfaces:**
- Consumes: view data from `ControlStrokeMesinController::index()` (Task 3) — `$mesinList` (Eloquent collection of `MasterMesin`, each with `id`, `id_mesin`, `nama_mesin`), `$tipeList` (array of strings, from `MasterMesin::TIPE_MESIN`). Consumes JSON from `route('maintenance.control-stroke-mesin.data')` — `{rows: [{kode_mesin, nama_mesin, total_stroke}], summary: {total_stroke}}`.
- Produces: nothing consumed by later tasks (Task 7 just links to the route, not the view internals).

- [ ] **Step 1: Create the view**

Create `resources/views/maintenance/control-stroke-mesin.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Control Stroke Mesin')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Mesin</h4>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Tanggal Dari</label>
                <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="{{ date('Y-01-01') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tanggal Sampai</label>
                <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Bulan</label>
                <select id="bulan" name="bulan" class="form-select form-select-sm">
                    <option value="">Semua Bulan</option>
                    @php $namaBulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']; $thn = date('Y'); @endphp
                    @foreach($namaBulan as $no => $nm)
                        <option value="{{ $thn }}-{{ sprintf('%02d', $no) }}">{{ $nm }} {{ $thn }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tipe Mesin</label>
                <select id="tipe_mesin" name="tipe_mesin" class="form-select form-select-sm">
                    <option value="">Semua Tipe</option>
                    @foreach($tipeList as $tipe)
                        <option value="{{ $tipe }}">{{ $tipe }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Mesin</label>
                <div x-data="taField(taMesin)">
                    <div class="ta-wrap">
                        <input type="text" class="form-control form-control-sm ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Semua Mesin...">
                        <input type="hidden" id="mesin_id" name="mesin_id" :value="val ?? ''">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}" @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <div class="text-muted small">Total Stroke</div>
        <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalStroke">0</span></div>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body"><canvas id="strokeChart" height="100"></canvas></div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>Nama Mesin</th><th class="text-end">Total Stroke</th></tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="3" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin]));

function taField(items, initText = '', initVal = null) {
    return {
        items, query: initText, val: initVal, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() { setTimeout(() => { this.open = false; if (!this.val) this.query = ''; }, 180); },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) { this.query = item.t; this.val = item.v; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}

let strokeChart = null;

async function loadData() {
    const params = new URLSearchParams({
        date_from:  document.getElementById('date_from').value,
        date_to:    document.getElementById('date_to').value,
        bulan:      document.getElementById('bulan').value,
        tipe_mesin: document.getElementById('tipe_mesin').value,
        mesin_id:   document.getElementById('mesin_id').value,
    });
    const res  = await fetch(`{{ route('maintenance.control-stroke-mesin.data') }}?${params}`);
    const data = await res.json();
    const rows = data.rows || [];

    document.getElementById('kpiTotalStroke').textContent = (data.summary?.total_stroke ?? 0).toLocaleString();

    if (strokeChart) strokeChart.destroy();
    strokeChart = new Chart(document.getElementById('strokeChart'), {
        type: 'bar',
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'Total Stroke', data: rows.map(r => r.total_stroke), backgroundColor: '#1f2937' }] },
        options: { responsive: true, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v.toLocaleString() } }, scales: { y: { beginAtZero: true } } }
    });

    const tbody = document.getElementById('strokeTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `<tr>
        <td>${r.kode_mesin}</td><td>${r.nama_mesin}</td>
        <td class="text-end"><strong>${Number(r.total_stroke).toLocaleString()}</strong></td>
    </tr>`).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
```

- [ ] **Step 2: Run the Task 3 feature test to verify it now passes**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: PASS (3/3)

- [ ] **Step 3: Commit**

```bash
git add resources/views/maintenance/control-stroke-mesin.blade.php
git commit -m "feat(maintenance): add Control Stroke Mesin view"
```

---

### Task 6: `control-stroke-dies.blade.php` view

**Files:**
- Create: `resources/views/maintenance/control-stroke-dies.blade.php`

**Interfaces:**
- Consumes: view data from `ControlStrokeDiesController::index()` (Task 4) — `$partList` (Eloquent collection of `MasterPart`, each with `id`, `part_no`, `part_name`), `$customers` (collection of strings). Consumes JSON from `route('maintenance.control-stroke-dies.data')` — `{rows: [{kode_mesin, nama_mesin, nama_proses, total_stroke}], summary: {total_stroke}}`.
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Create the view**

Create `resources/views/maintenance/control-stroke-dies.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Control Stroke Dies')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Dies</h4>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Tanggal Dari</label>
                <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="{{ date('Y-01-01') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Tanggal Sampai</label>
                <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Customer</label>
                <div x-data="taField(taCustomer)">
                    <div class="ta-wrap">
                        <input type="text" class="form-control form-control-sm ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Semua Customer...">
                        <input type="hidden" id="customer" name="customer" :value="val ?? ''">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}" @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Part No</label>
                <div x-data="taField(taPart)">
                    <div class="ta-wrap">
                        <input type="text" class="form-control form-control-sm ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Semua Dies...">
                        <input type="hidden" id="part_id" name="part_id" :value="val ?? ''">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}" @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <div class="text-muted small">Total Stroke</div>
        <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalStroke">0</span></div>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body"><canvas id="strokeChart" height="100"></canvas></div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Dies</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr><th>Part No</th><th>Nama Dies</th><th>Proses</th><th class="text-end">Total Stroke</th></tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="4" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taCustomer = @json($customers->map(fn($c) => ['v' => $c, 't' => $c]));
const taPart = @json($partList->map(fn($m) => ['v' => $m->id, 't' => $m->part_no.' – '.$m->part_name]));

function taField(items, initText = '', initVal = null) {
    return {
        items, query: initText, val: initVal, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() { setTimeout(() => { this.open = false; if (!this.val) this.query = ''; }, 180); },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) { this.query = item.t; this.val = item.v; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}

let strokeChart = null;

async function loadData() {
    const params = new URLSearchParams({
        date_from: document.getElementById('date_from').value,
        date_to:   document.getElementById('date_to').value,
        part_id:   document.getElementById('part_id').value,
        customer:  document.getElementById('customer').value,
    });
    const res  = await fetch(`{{ route('maintenance.control-stroke-dies.data') }}?${params}`);
    const data = await res.json();
    const rows = data.rows || [];

    document.getElementById('kpiTotalStroke').textContent = (data.summary?.total_stroke ?? 0).toLocaleString();

    if (strokeChart) strokeChart.destroy();
    strokeChart = new Chart(document.getElementById('strokeChart'), {
        type: 'bar',
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'Total Stroke', data: rows.map(r => r.total_stroke), backgroundColor: '#1f2937' }] },
        options: { responsive: true, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v.toLocaleString() } }, scales: { y: { beginAtZero: true } } }
    });

    const tbody = document.getElementById('strokeTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `<tr>
        <td>${r.kode_mesin}</td><td>${r.nama_mesin}</td><td>${r.nama_proses}</td>
        <td class="text-end"><strong>${Number(r.total_stroke).toLocaleString()}</strong></td>
    </tr>`).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
```

- [ ] **Step 2: Run the Task 4 feature test to verify it now passes**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS (3/3)

- [ ] **Step 3: Commit**

```bash
git add resources/views/maintenance/control-stroke-dies.blade.php
git commit -m "feat(maintenance): add Control Stroke Dies view"
```

---

### Task 7: Sidebar nav links

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1047` (MTC Mesin group) and `:1067` (MTC Dies group)

**Interfaces:**
- Consumes: routes `maintenance.control-stroke-mesin` (Task 3), `maintenance.control-stroke-dies` (Task 4).
- Produces: nothing (leaf UI task).

- [ ] **Step 1: Fix the existing Availability link's active-state guard**

The new route path `maintenance/availability/control-stroke-mesin` starts with `maintenance/availability`, so the existing Availability link's `str_starts_with($uri,'maintenance/availability')` check would also light up on the new stroke pages. In `resources/views/layouts/app.blade.php`, find this line (~1047):

```php
            <a href="{{ route('maintenance.availability') }}" class="nav-item-link {{ $vcbActive==='mtcmesin' && str_starts_with($uri,'maintenance/availability')?'active':'' }}">
```

Replace it with:

```php
            <a href="{{ route('maintenance.availability') }}" class="nav-item-link {{ $vcbActive==='mtcmesin' && str_starts_with($uri,'maintenance/availability') && !str_starts_with($uri,'maintenance/availability/control-stroke')?'active':'' }}">
```

Do the same for the dies Availability link (~1067):

```php
            <a href="{{ route('maintenance.availability-dies') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/availability-dies')?'active':'' }}">
```

This one already only matches `maintenance/availability-dies` (with the trailing dash), which does NOT prefix-match `maintenance/availability/control-stroke-dies` — no change needed here.

- [ ] **Step 2: Add the Control Stroke Mesin link**

Directly after the Availability link inside the `mtcmesin` nav-group-body (~line 1049, right before the closing `</div>` of that group), add:

```php
            <a href="{{ route('maintenance.control-stroke-mesin') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/availability/control-stroke-mesin')?'active':'' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-text">Control Stroke</span>
            </a>
```

- [ ] **Step 3: Add the Control Stroke Dies link**

Directly after the Availability Dies link inside the `mtcdies` nav-group-body (~line 1069, right before the closing `</div>` of that group), add:

```php
            <a href="{{ route('maintenance.control-stroke-dies') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/availability/control-stroke-dies')?'active':'' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-text">Control Stroke</span>
            </a>
```

- [ ] **Step 4: Manually verify in browser**

Run: `php artisan serve` (or use existing local dev server), log in as an `admin` user, click through Maintenance → MTC Mesin → Control Stroke and MTC Dies → Control Stroke in the sidebar. Confirm:
- Both new links appear and navigate correctly.
- Clicking "Availability" no longer keeps the "Control Stroke" link visually active, and vice versa.
- Filter → Tampilkan loads the KPI, chart, and table without console errors.

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(maintenance): add Control Stroke nav links, fix Availability active-state prefix collision"
```

---

### Task 8: Final whole-branch review

**Files:** none (verification only)

**Interfaces:** none

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests PASS, including all tests added in Tasks 1–4.

- [ ] **Step 2: Manually smoke-test both new pages end-to-end**

Using a browser (or the in-app browser tool), log in as `admin`, visit both:
- `/maintenance/availability/control-stroke-mesin`
- `/maintenance/availability/control-stroke-dies`

For each: change the date filter, click "Tampilkan", confirm the KPI card, chart, and table all update and match each other's totals (KPI `total_stroke` == chart bars sum == table column sum).

- [ ] **Step 3: Confirm role gate on both pages**

Log in as `staff_produksi` (or use a feature test if no test user is available), confirm both new routes return 403.

- [ ] **Step 4: Review diff for scope creep**

Run: `git diff main --stat` (or the appropriate base branch)
Expected: only the files listed in "File Structure" above are touched — no unrelated changes to `MasterMesin.kapasitas_max_stroke`, no new migrations, no changes to existing Availability/MTTR/MTBF pages beyond the nav-link fix in Task 7.
