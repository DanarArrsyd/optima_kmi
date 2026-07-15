# Dies Preventive Maintenance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Upgrade the Control Stroke Dies page into a preventive-maintenance monitoring dashboard: each dies (part+proses) tracks a running stroke counter since its last preventive (or since install), compared against a 30,000-stroke threshold, with status coloring, a reset-via-preventive-input flow, and per-dies history.

**Architecture:** New `dies_preventive_log` table + model records every preventive event. `MaintenanceDiesDashboardService` gains `currentStrokeFor()` (single-dies running count) and `computeCurrentCycle()` (all-dies list, replaces `computeStroke()` which becomes dead code and is removed). `ControlStrokeDiesController` gains `history()` and `storePreventive()` alongside the rewired `index()`/`data()`. The Blade view is rewritten: date filter removed, progress bar + status badge + two Bootstrap modals (input preventive, view history) added. Control Stroke Mesin is untouched.

**Tech Stack:** Laravel 13 / PHP, Eloquent, Blade, Bootstrap 5 modals, vanilla JS `fetch`, PHPUnit + RefreshDatabase.

## Global Constraints

- Threshold is a single constant `MaintenanceDiesDashboardService::STROKE_THRESHOLD = 30000`, same for every dies — no per-dies configuration.
- Status bands: `pct < 80` → `ok`, `80 <= pct < 100` → `warning`, `pct >= 100` → `overdue`.
- `current_stroke` for a dies = `SUM(qty_aktual)` from `TransaksiProduksi` where `id_part_proses` matches AND `tanggal_produksi > cycleStart`, where `cycleStart` = the dies's latest `dies_preventive_log.tanggal_preventive`, falling back to `MasterPart.tanggal_instalasi`, falling back to no lower bound (all-time) if both are null.
- Listing scope: include a dies row only if it has at least one `TransaksiProduksi` record ever (`whereHas('transaksiProduksi')`) — this keeps freshly-reset (0-stroke) dies visible.
- Sort order: `overdue` rows first, then `warning`, then `ok`; within the same status, higher `pct` first.
- `stroke_at_preventive` on a new log row is always computed server-side via `currentStrokeFor()` at submission time — never trust a client-supplied value.
- Only `admin` and `staff_maintenance` may call `storePreventive()`; `manager` gets 403 (they can still view/read `index`, `data`, `history`).
- No date-range filter (`date_from`/`date_to`) anywhere on this page anymore — it is a running-counter dashboard, not a historical range report.
- No new migrations touch `transaksi_produksi` or `master_part_proses` columns — all new state lives in `dies_preventive_log`.
- Control Stroke Mesin (`ControlStrokeMesinController`, `MaintenanceDashboardService::computeStroke()`, `control-stroke-mesin.blade.php`) is out of scope — do not modify it.

---

## File Structure

- Create `database/migrations/2026_07_15_000001_create_dies_preventive_log_table.php`.
- Create `app/Models/DiesPreventiveLog.php`.
- Modify `app/Models/MasterPartProses.php` — add `preventiveLogs()` relation.
- Modify `app/Services/MaintenanceDiesDashboardService.php` — add `STROKE_THRESHOLD`, `currentStrokeFor()`, `computeCurrentCycle()`; remove `computeStroke()`.
- Modify `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php` — rewrite `index()`/`data()`, add `history()`/`storePreventive()`.
- Modify `routes/web.php` — add 2 routes (`history` GET, `preventive` POST) after the existing `control-stroke-dies.data` route.
- Modify `resources/views/maintenance/control-stroke-dies.blade.php` — full rewrite.
- Delete `tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php` (tests the removed `computeStroke()`).
- Create `tests/Unit/MaintenanceDiesDashboardServiceCurrentCycleTest.php`.
- Modify `tests/Feature/Maintenance/ControlStrokeDiesTest.php` — rewrite to match the new JSON contract and add history/storePreventive coverage.

---

### Task 1: `dies_preventive_log` migration + model + relation

**Files:**
- Create: `database/migrations/2026_07_15_000001_create_dies_preventive_log_table.php`
- Create: `app/Models/DiesPreventiveLog.php`
- Modify: `app/Models/MasterPartProses.php`
- Test: `tests/Unit/DiesPreventiveLogTest.php`

**Interfaces:**
- Consumes: `master_part_proses.id`, `master_karyawan.id`, `users.id` (existing tables/PKs).
- Produces: `App\Models\DiesPreventiveLog` with fillable `['id_part_proses', 'tanggal_preventive', 'id_karyawan', 'stroke_at_preventive', 'catatan', 'created_by']`, cast `'tanggal_preventive' => 'date:Y-m-d'`, relations `partProses(): BelongsTo`, `karyawan(): BelongsTo`, `createdBy(): BelongsTo`. `MasterPartProses::preventiveLogs(): HasMany` (ordered `orderByDesc('tanggal_preventive')`). Consumed by Task 2 (service) and Task 3 (controller).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/DiesPreventiveLogTest.php`:

```php
<?php
namespace Tests\Unit;

use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiesPreventiveLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Maintenance', 'is_aktif' => true]);
        $part     = MasterPart::create(['part_no' => 'DPL-P1', 'part_name' => 'DPL Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        return compact('user', 'karyawan', 'part', 'proses');
    }

    public function test_can_create_log_with_all_fields_and_relations_resolve(): void
    {
        $f = $this->fixtures();

        $log = DiesPreventiveLog::create([
            'id_part_proses'       => $f['proses']->id,
            'tanggal_preventive'   => '2026-07-01',
            'id_karyawan'          => $f['karyawan']->id,
            'stroke_at_preventive' => 30450,
            'catatan'              => 'Ganti pisau potong',
            'created_by'           => $f['user']->id,
        ]);

        $this->assertSame('2026-07-01', $log->fresh()->tanggal_preventive->format('Y-m-d'));
        $this->assertSame(30450, $log->fresh()->stroke_at_preventive);
        $this->assertSame($f['proses']->id, $log->partProses->id);
        $this->assertSame('Teknisi A', $log->karyawan->nama_karyawan);
        $this->assertSame($f['user']->id, $log->createdBy->id);
    }

    public function test_master_part_proses_preventive_logs_relation_orders_newest_first(): void
    {
        $f = $this->fixtures();

        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30100, 'created_by' => $f['user']->id,
        ]);
        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-07-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30200, 'created_by' => $f['user']->id,
        ]);

        $logs = $f['proses']->fresh()->preventiveLogs;

        $this->assertCount(2, $logs);
        $this->assertSame('2026-07-01', $logs->first()->tanggal_preventive->format('Y-m-d'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/DiesPreventiveLogTest.php`
Expected: FAIL — `Class "App\Models\DiesPreventiveLog" not found` (table/model don't exist yet).

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_15_000001_create_dies_preventive_log_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dies_preventive_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_part_proses')->constrained('master_part_proses');
            $table->date('tanggal_preventive');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->integer('stroke_at_preventive');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index('id_part_proses');
            $table->index('tanggal_preventive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dies_preventive_log');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/DiesPreventiveLog.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DiesPreventiveLog extends Model
{
    use SoftDeletes;

    protected $table = 'dies_preventive_log';

    protected $fillable = [
        'id_part_proses', 'tanggal_preventive', 'id_karyawan',
        'stroke_at_preventive', 'catatan', 'created_by',
    ];

    protected $casts = [
        'tanggal_preventive' => 'date:Y-m-d',
    ];

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
```

- [ ] **Step 5: Add the relation to `MasterPartProses`**

In `app/Models/MasterPartProses.php`, add this method after `transaksiDandori()`:

```php
    public function preventiveLogs(): HasMany
    {
        return $this->hasMany(DiesPreventiveLog::class, 'id_part_proses')
                    ->orderByDesc('tanggal_preventive');
    }
```

(The `HasMany` import already exists at the top of this file — no new `use` statement needed.)

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/DiesPreventiveLogTest.php`
Expected: PASS (2/2)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_15_000001_create_dies_preventive_log_table.php app/Models/DiesPreventiveLog.php app/Models/MasterPartProses.php tests/Unit/DiesPreventiveLogTest.php
git commit -m "feat(maintenance): add dies_preventive_log table and model"
```

---

### Task 2: `MaintenanceDiesDashboardService` — `currentStrokeFor()` + `computeCurrentCycle()`

**Files:**
- Modify: `app/Services/MaintenanceDiesDashboardService.php`
- Delete: `tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php`
- Create: `tests/Unit/MaintenanceDiesDashboardServiceCurrentCycleTest.php`

**Interfaces:**
- Consumes: `App\Models\DiesPreventiveLog` (Task 1), `App\Models\MasterPartProses::preventiveLogs()` (Task 1), `App\Models\TransaksiProduksi`.
- Produces:
  - `MaintenanceDiesDashboardService::STROKE_THRESHOLD` (public const, `int`, value `30000`).
  - `MaintenanceDiesDashboardService::currentStrokeFor(MasterPartProses $pr): int` — single-dies running stroke count. Used by Task 3's `storePreventive()`.
  - `MaintenanceDiesDashboardService::computeCurrentCycle(?int $partId = null, ?string $customer = null): array` — each row: `['id_part_proses' => int, 'kode_mesin' => string, 'nama_mesin' => string, 'nama_proses' => string, 'current_stroke' => int, 'threshold' => int, 'pct' => float, 'status' => string, 'last_preventive_date' => ?string, 'last_preventive_pic' => ?string]`. Used by Task 3's `ControlStrokeDiesController::data()`.
  - `computeStroke()` is REMOVED from this file (no longer called anywhere after Task 3 rewires the controller).

- [ ] **Step 1: Delete the now-superseded test file**

```bash
rm tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php
```

This file tests `computeStroke()`, which this task removes. Deleting it now means the test suite won't reference a method that's about to disappear.

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/MaintenanceDiesDashboardServiceCurrentCycleTest.php`:

```php
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
        $user     = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Maintenance', 'is_aktif' => true]);
        $mesin    = MasterMesin::create(['id_mesin' => 'CC'.uniqid(), 'nama_mesin' => 'CC Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

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

    private function produksi(array $f, MasterPartProses $proses, string $tanggal, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty,
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
        $f  = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2025-12-01', 999); // before instalasi, must not count
        $this->produksi($f, $pr, '2026-02-01', 100);
        $this->produksi($f, $pr, '2026-03-01', 50);

        $svc = new MaintenanceDiesDashboardService();
        $this->assertSame(150, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_current_stroke_is_all_time_when_no_preventive_and_no_instalasi(): void
    {
        $f  = $this->fixtures();
        $pr = $this->partProses(null);
        $this->produksi($f, $pr, '2020-01-01', 40);
        $this->produksi($f, $pr, '2026-01-01', 60);

        $svc = new MaintenanceDiesDashboardService();
        $this->assertSame(100, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_current_stroke_only_counts_transactions_after_latest_preventive(): void
    {
        $f  = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2026-02-01', 30000); // pre-preventive, excluded
        $this->preventive($f, $pr, '2026-03-01', 30000);
        $this->produksi($f, $pr, '2026-04-01', 500);
        $this->produksi($f, $pr, '2026-05-01', 300);

        $svc = new MaintenanceDiesDashboardService();
        $this->assertSame(800, $svc->currentStrokeFor($pr->fresh()));
    }

    public function test_current_stroke_uses_latest_preventive_not_earliest(): void
    {
        $f  = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->preventive($f, $pr, '2026-02-01', 10000);
        $this->preventive($f, $pr, '2026-04-01', 20000);
        $this->produksi($f, $pr, '2026-03-01', 5000);  // between the two preventives, excluded
        $this->produksi($f, $pr, '2026-05-01', 700);   // after the latest, included

        $svc = new MaintenanceDiesDashboardService();
        $this->assertSame(700, $svc->currentStrokeFor($pr->fresh()));
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

        $svc  = new MaintenanceDiesDashboardService();
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

        $svc  = new MaintenanceDiesDashboardService();
        $rows = $svc->computeCurrentCycle();

        $this->assertSame('overdue', $rows[0]['status']);
        $this->assertSame('warning', $rows[1]['status']);
        $this->assertSame('ok', $rows[2]['status']);
    }

    public function test_dies_with_no_transaksi_ever_is_excluded_from_listing(): void
    {
        $this->fixtures();
        $this->partProses('2026-01-01'); // no TransaksiProduksi created for it

        $svc = new MaintenanceDiesDashboardService();
        $this->assertCount(0, $svc->computeCurrentCycle());
    }

    public function test_dies_with_zero_current_stroke_after_reset_still_appears(): void
    {
        $f  = $this->fixtures();
        $pr = $this->partProses('2026-01-01');
        $this->produksi($f, $pr, '2026-02-01', 30000);
        $this->preventive($f, $pr, '2026-03-01', 30000); // reset — no transactions after this

        $svc  = new MaintenanceDiesDashboardService();
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_part_proses');

        $this->assertArrayHasKey($pr->id, $rows);
        $this->assertSame(0, $rows[$pr->id]['current_stroke']);
        $this->assertSame('2026-03-01', $rows[$pr->id]['last_preventive_date']);
        $this->assertSame('Teknisi A', $rows[$pr->id]['last_preventive_pic']);
    }

    public function test_customer_filter_narrows_result(): void
    {
        $f    = $this->fixtures();
        $toy  = $this->partProses('2026-01-01', 'Toyota');
        $honda = $this->partProses('2026-01-01', 'Honda');
        $this->produksi($f, $toy, '2026-02-01', 100);
        $this->produksi($f, $honda, '2026-02-01', 200);

        $svc  = new MaintenanceDiesDashboardService();
        $rows = $svc->computeCurrentCycle(null, 'Honda');

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['current_stroke']);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test tests/Unit/MaintenanceDiesDashboardServiceCurrentCycleTest.php`
Expected: FAIL — `Call to undefined method App\Services\MaintenanceDiesDashboardService::currentStrokeFor()`

- [ ] **Step 4: Implement the service changes**

In `app/Services/MaintenanceDiesDashboardService.php`:
1. Add `use App\Models\DiesPreventiveLog;` to the imports.
2. Remove the entire `computeStroke()` method.
3. Add these members inside the class (the constant at the top, the two methods after `compute()`):

```php
    public const STROKE_THRESHOLD = 30000;
```

```php
    public function currentStrokeFor(MasterPartProses $pr): int
    {
        $lastPreventive = DiesPreventiveLog::where('id_part_proses', $pr->id)->max('tanggal_preventive');
        $cycleStart     = $lastPreventive ?? $pr->part?->tanggal_instalasi;

        return (int) TransaksiProduksi::query()
            ->where('id_part_proses', $pr->id)
            ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
            ->sum('qty_aktual');
    }

    public function computeCurrentCycle(?int $partId = null, ?string $customer = null): array
    {
        $partProsesList = MasterPartProses::with(['part', 'preventiveLogs.karyawan'])
            ->whereHas('transaksiProduksi')
            ->when($partId, fn ($q) => $q->where('id_part', $partId))
            ->when($customer, fn ($q) => $q->whereHas('part', fn ($p) => $p->where('customer', $customer)))
            ->get()
            ->sortBy(fn ($pr) => $pr->part->part_no ?? '');

        $rows = [];
        foreach ($partProsesList as $pr) {
            $currentStroke = $this->currentStrokeFor($pr);
            $pct           = round($currentStroke / self::STROKE_THRESHOLD * 100, 1);
            $status        = $pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok');
            $lastLog       = $pr->preventiveLogs->first();

            $rows[] = [
                'id_part_proses'        => $pr->id,
                'kode_mesin'            => $pr->part?->part_no,
                'nama_mesin'            => $pr->part?->part_name,
                'nama_proses'           => $pr->nama_proses,
                'current_stroke'        => $currentStroke,
                'threshold'             => self::STROKE_THRESHOLD,
                'pct'                   => $pct,
                'status'                => $status,
                'last_preventive_date'  => $lastLog?->tanggal_preventive?->format('Y-m-d'),
                'last_preventive_pic'   => $lastLog?->karyawan?->nama_karyawan,
            ];
        }

        $statusOrder = ['overdue' => 0, 'warning' => 1, 'ok' => 2];
        usort($rows, function ($a, $b) use ($statusOrder) {
            $byStatus = $statusOrder[$a['status']] <=> $statusOrder[$b['status']];
            return $byStatus !== 0 ? $byStatus : $b['pct'] <=> $a['pct'];
        });

        return array_values($rows);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/MaintenanceDiesDashboardServiceCurrentCycleTest.php`
Expected: PASS (9/9)

- [ ] **Step 6: Run the full suite to confirm `computeStroke()` removal broke nothing else**

Run: `php artisan test`
Expected: FAIL — `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php` still calls `computeStroke()`, so `ControlStrokeDiesTest` will error. This is expected; Task 3 fixes the controller. Confirm the ONLY failures are in `tests/Feature/Maintenance/ControlStrokeDiesTest.php` — if any other test file fails, stop and investigate before committing.

- [ ] **Step 7: Commit**

```bash
git add app/Services/MaintenanceDiesDashboardService.php tests/Unit/MaintenanceDiesDashboardServiceCurrentCycleTest.php
git rm tests/Unit/MaintenanceDiesDashboardServiceStrokeTest.php
git commit -m "feat(maintenance): replace computeStroke() with running-cycle computeCurrentCycle() on dies service"
```

---

### Task 3: `ControlStrokeDiesController` — rewire `index()`/`data()`, add `history()`/`storePreventive()`, routes

**Files:**
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Maintenance/ControlStrokeDiesTest.php` (full rewrite)

**Interfaces:**
- Consumes: `MaintenanceDiesDashboardService::computeCurrentCycle()` and `::currentStrokeFor()` (Task 2), `App\Models\DiesPreventiveLog` (Task 1), `App\Models\MasterKaryawan::aktif()` scope (existing).
- Produces:
  - `GET maintenance.control-stroke-dies` (unchanged route name/path) — view now also receives `karyawanList`.
  - `GET maintenance.control-stroke-dies.data` (unchanged route name/path) — JSON shape changes to `{rows: [...9-field rows from Task 2...], summary: {total_dies: int, overdue: int, warning: int}}`. No longer accepts `date_from`/`date_to`.
  - `GET maintenance.control-stroke-dies.history` (NEW) — `{id_part_proses}` route param, JSON `{logs: [{tanggal_preventive, pic, stroke_at_preventive, catatan, input_by}]}`.
  - `POST maintenance.control-stroke-dies.preventive` (NEW) — `{id_part_proses}` route param, body `{tanggal_preventive, id_karyawan, catatan?}`, JSON `{ok: true}` on success, 403 for non-admin/non-staff_maintenance, 422 on validation failure. Consumed by Task 4's view.

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `tests/Feature/Maintenance/ControlStrokeDiesTest.php`:

```php
<?php
namespace Tests\Feature\Maintenance;

use App\Models\DiesPreventiveLog;
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

    private function fixtures(): array
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Maintenance', 'is_aktif' => true]);
        $mesin    = MasterMesin::create(['id_mesin' => 'CD-01'.uniqid(), 'nama_mesin' => 'CD Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part     = MasterPart::create(['part_no' => 'CD-P'.uniqid(), 'part_name' => 'CD Part', 'customer' => 'Toyota', 'tanggal_instalasi' => '2026-01-01']);
        $proses   = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);

        return compact('admin', 'shift', 'karyawan', 'mesin', 'part', 'proses');
    }

    private function produksi(array $f, string $tanggal, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $f['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty,
            'created_by' => $f['admin']->id,
        ]);
    }

    public function test_admin_can_view_control_stroke_dies_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('maintenance.control-stroke-dies'));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-dies');
        $response->assertViewHas('karyawanList');
    }

    public function test_staff_produksi_cannot_access_control_stroke_dies(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->get(route('maintenance.control-stroke-dies'));

        $response->assertForbidden();
    }

    public function test_data_endpoint_returns_current_cycle_rows_and_summary(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 31000); // over threshold -> overdue

        $response = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.data'));

        $response->assertOk();
        $response->assertJsonPath('summary.total_dies', 1);
        $response->assertJsonPath('summary.overdue', 1);
        $response->assertJsonPath('summary.warning', 0);
        $response->assertJsonFragment(['kode_mesin' => $f['part']->part_no, 'current_stroke' => 31000, 'status' => 'overdue', 'threshold' => 30000]);
    }

    public function test_history_endpoint_returns_logs_for_requested_dies_only(): void
    {
        $f = $this->fixtures();
        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'catatan' => 'Ganti dies', 'created_by' => $f['admin']->id,
        ]);

        $otherPart   = MasterPart::create(['part_no' => 'OTHER'.uniqid(), 'part_name' => 'Other', 'customer' => 'Honda']);
        $otherProses = MasterPartProses::create(['id_part' => $otherPart->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        DiesPreventiveLog::create([
            'id_part_proses' => $otherProses->id, 'tanggal_preventive' => '2026-05-02',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 12000, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.history', $f['proses']->id));

        $response->assertOk();
        $response->assertJsonCount(1, 'logs');
        $response->assertJsonFragment(['tanggal_preventive' => '2026-05-01', 'pic' => 'Teknisi A', 'stroke_at_preventive' => 30500, 'catatan' => 'Ganti dies']);
    }

    public function test_admin_can_store_preventive_and_it_resets_the_running_cycle(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 30500);

        $response = $this->actingAs($f['admin'])->postJson(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'catatan'            => 'Preventive rutin',
        ]);

        $response->assertOk();
        $response->assertJsonPath('ok', true);

        $log = DiesPreventiveLog::first();
        $this->assertSame(30500, $log->stroke_at_preventive);
        $this->assertSame($f['admin']->id, $log->created_by);

        $dataResponse = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.data'));
        $dataResponse->assertJsonFragment(['current_stroke' => 0, 'status' => 'ok']);
    }

    public function test_staff_maintenance_can_store_preventive(): void
    {
        $f     = $this->fixtures();
        $staff = User::factory()->create(['role' => 'staff_maintenance']);

        $response = $this->actingAs($staff)->postJson(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertOk();
    }

    public function test_manager_cannot_store_preventive(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->postJson(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertForbidden();
    }

    public function test_store_preventive_rejects_future_date(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->postJson(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => now()->addDay()->toDateString(),
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('tanggal_preventive');
    }

    public function test_store_preventive_rejects_unknown_karyawan(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->postJson(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => 999999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('id_karyawan');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: FAIL — route `maintenance.control-stroke-dies.history` not defined, `computeStroke()` no longer exists on the controller's current code, `id_karyawan` column doesn't exist yet on `dies_preventive_log` before this task's controller wiring (it exists from Task 1, but the controller doesn't use it yet).

- [ ] **Step 3: Rewrite the controller**

Replace the full contents of `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`:

```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;

class ControlStrokeDiesController extends Controller
{
    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.control-stroke-dies', [
            'partList'     => MasterPart::orderBy('part_no')->get(),
            'customers'    => MasterPart::whereNotNull('customer')->where('customer', '!=', '')->distinct()->orderBy('customer')->pluck('customer'),
            'karyawanList' => MasterKaryawan::aktif()->where('departemen', 'Maintenance')->orderBy('nama_karyawan')->get(),
        ]);
    }

    public function data(Request $request)
    {
        $partId   = (int) $request->input('part_id', 0) ?: null;
        $customer = trim((string) $request->input('customer', '')) ?: null;
        $rows     = $this->svc->computeCurrentCycle($partId, $customer);

        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'total_dies' => count($rows),
                'overdue'    => collect($rows)->where('status', 'overdue')->count(),
                'warning'    => collect($rows)->where('status', 'warning')->count(),
            ],
        ]);
    }

    public function history(MasterPartProses $partProses)
    {
        $logs = $partProses->preventiveLogs()
            ->with(['karyawan:id,nama_karyawan', 'createdBy:id,name'])
            ->orderByDesc('tanggal_preventive')
            ->get();

        return response()->json([
            'logs' => $logs->map(fn ($l) => [
                'tanggal_preventive'   => $l->tanggal_preventive->format('Y-m-d'),
                'pic'                  => $l->karyawan?->nama_karyawan,
                'stroke_at_preventive' => $l->stroke_at_preventive,
                'catatan'              => $l->catatan,
                'input_by'             => $l->createdBy?->name,
            ]),
        ]);
    }

    public function storePreventive(MasterPartProses $partProses, Request $request)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        $validated = $request->validate([
            'tanggal_preventive' => 'required|date|before_or_equal:today',
            'id_karyawan'        => 'required|exists:master_karyawan,id',
            'catatan'            => 'nullable|string|max:1000',
        ]);

        $currentStroke = $this->svc->currentStrokeFor($partProses);

        DiesPreventiveLog::create([
            'id_part_proses'       => $partProses->id,
            'tanggal_preventive'   => $validated['tanggal_preventive'],
            'id_karyawan'          => $validated['id_karyawan'],
            'stroke_at_preventive' => $currentStroke,
            'catatan'              => $validated['catatan'] ?? null,
            'created_by'           => auth()->id(),
        ]);

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 4: Add the two new routes**

In `routes/web.php`, find the existing lines (added in the prior Control Stroke plan):

```php
        Route::get('availability/control-stroke-dies',       [ControlStrokeDiesController::class, 'index'])->name('control-stroke-dies');
        Route::get('availability/control-stroke-dies/data',   [ControlStrokeDiesController::class, 'data'])->name('control-stroke-dies.data');
```

Add these two lines directly after them (same `role:admin,staff_maintenance,manager` group — `storePreventive()` self-gates the stricter 2-role check inside the method body, per the plan's Global Constraints):

```php
        Route::get('availability/control-stroke-dies/{partProses}/history', [ControlStrokeDiesController::class, 'history'])->name('control-stroke-dies.history');
        Route::post('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'storePreventive'])->name('control-stroke-dies.preventive');
```

No new `use` import is needed — `ControlStrokeDiesController` is already imported for the two existing routes above.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS (9/9)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: PASS (view-dependent assertions in this file will pass since the view already exists from the prior Control Stroke plan; the view's date-filter JS referencing removed behavior is addressed in Task 4, but Blade doesn't fail PHPUnit for JS-only mismatches — full suite should be green here).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeDiesController.php routes/web.php tests/Feature/Maintenance/ControlStrokeDiesTest.php
git commit -m "feat(maintenance): add preventive history/store endpoints, switch data() to running-cycle stroke"
```

---

### Task 4: Rewrite `control-stroke-dies.blade.php` — progress bar, status badges, preventive + history modals

**Files:**
- Modify: `resources/views/maintenance/control-stroke-dies.blade.php`

**Interfaces:**
- Consumes: view data from `ControlStrokeDiesController::index()` (Task 3) — `$partList`, `$customers`, `$karyawanList` (Eloquent collection of `MasterKaryawan`, each with `id`, `nama_karyawan`). Consumes JSON from `route('maintenance.control-stroke-dies.data')` — `{rows: [{id_part_proses, kode_mesin, nama_mesin, nama_proses, current_stroke, threshold, pct, status, last_preventive_date, last_preventive_pic}], summary: {total_dies, overdue, warning}}`. Consumes JSON from `route('maintenance.control-stroke-dies.history', id)` — `{logs: [{tanggal_preventive, pic, stroke_at_preventive, catatan, input_by}]}`. Posts to `route('maintenance.control-stroke-dies.preventive', id)` with `{tanggal_preventive, id_karyawan, catatan}`.
- Produces: nothing consumed by later tasks (this is the final task).

- [ ] **Step 1: Replace the full contents of the view**

Replace `resources/views/maintenance/control-stroke-dies.blade.php`:

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
            <div class="col-md-4">
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
            <div class="col-md-4">
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
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="erp-panel h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Dies Dipantau</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalDies">0</span></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100"><div class="card-body py-3">
            <div class="text-muted small">Segera Preventive</div>
            <div style="font-size:1.6rem;font-weight:700;color:#b91c1c"><span id="kpiOverdue">0</span></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100"><div class="card-body py-3">
            <div class="text-muted small">Mendekati Batas</div>
            <div style="font-size:1.6rem;font-weight:700;color:#b45309"><span id="kpiWarning">0</span></div>
        </div></div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Dies</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Part No</th><th>Nama Dies</th><th>Proses</th>
                        <th style="min-width:220px">Stroke Saat Ini</th>
                        <th>Status</th><th>Preventive Terakhir</th><th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="7" class="text-center text-muted py-3">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- Modal: Input Preventive --}}
<div class="modal fade" id="preventiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Input Preventive — <span id="pvDiesLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="preventiveForm">
                <div class="modal-body">
                    <div class="alert alert-warning py-2 small" id="pvResetPreview"></div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal Preventive <span class="text-danger">*</span></label>
                        <input type="date" id="pv_tanggal" name="tanggal_preventive" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">PIC / Petugas <span class="text-danger">*</span></label>
                        <select id="pv_karyawan" name="id_karyawan" class="form-select" required>
                            <option value="">-- Pilih Petugas --</option>
                            @foreach($karyawanList as $k)
                                <option value="{{ $k->id }}">{{ $k->nama_karyawan }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea id="pv_catatan" name="catatan" class="form-control" rows="3" placeholder="Opsional"></textarea>
                    </div>
                    <div id="pvError" class="text-danger small" style="display:none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan Preventive</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal: History --}}
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Riwayat Preventive — <span id="hDiesLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="erp-table">
                        <thead>
                            <tr><th>Tanggal</th><th>PIC</th><th class="text-end">Stroke Tercapai</th><th>Catatan</th><th>Diinput Oleh</th></tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr><td colspan="5" class="text-center text-muted py-3">Memuat...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taCustomer = @json($customers->map(fn($c) => ['v' => $c, 't' => $c]));
const taPart = @json($partList->map(fn($m) => ['v' => $m->id, 't' => $m->part_no.' – '.$m->part_name]));
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const canInputPreventive = {{ in_array(auth()->user()->role, ['admin', 'staff_maintenance']) ? 'true' : 'false' }};

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

const STATUS_LABEL = { ok: 'OK', warning: 'Perlu Perhatian', overdue: 'SEGERA PREVENTIVE' };
const STATUS_BADGE = { ok: 'bg-success', warning: 'bg-warning text-dark', overdue: 'bg-danger' };
const STATUS_BAR   = { ok: 'bg-success', warning: 'bg-warning', overdue: 'bg-danger' };

let currentRows = [];
let preventiveModal, historyModal;

async function loadData() {
    const params = new URLSearchParams({
        part_id:  document.getElementById('part_id').value,
        customer: document.getElementById('customer').value,
    });
    const res  = await fetch(`{{ route('maintenance.control-stroke-dies.data') }}?${params}`);
    const data = await res.json();
    currentRows = data.rows || [];

    document.getElementById('kpiTotalDies').textContent = data.summary?.total_dies ?? 0;
    document.getElementById('kpiOverdue').textContent   = data.summary?.overdue ?? 0;
    document.getElementById('kpiWarning').textContent   = data.summary?.warning ?? 0;

    const tbody = document.getElementById('strokeTableBody');
    if (!currentRows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = currentRows.map(r => {
        const pct        = Math.min(r.pct, 100);
        const rowClass   = r.status === 'overdue' ? 'table-danger' : '';
        const lastPrev   = r.last_preventive_date ? `${r.last_preventive_date} — ${r.last_preventive_pic ?? '—'}` : 'Belum pernah';
        const disabledAttr = canInputPreventive ? '' : 'disabled title="Hanya Maintenance"';
        return `<tr class="${rowClass}">
            <td>${r.kode_mesin}</td><td>${r.nama_mesin}</td><td>${r.nama_proses}</td>
            <td>
                <div class="progress" style="height:20px">
                    <div class="progress-bar ${STATUS_BAR[r.status]}" style="width:${pct}%">
                        ${Number(r.current_stroke).toLocaleString()} / ${Number(r.threshold).toLocaleString()} (${r.pct}%)
                    </div>
                </div>
            </td>
            <td><span class="badge ${STATUS_BADGE[r.status]}">${STATUS_LABEL[r.status]}</span></td>
            <td>${lastPrev}</td>
            <td class="text-end">
                <button type="button" class="btn btn-primary btn-sm" ${disabledAttr} onclick="openPreventiveModal(${r.id_part_proses})"><i class="bi bi-tools"></i></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openHistoryModal(${r.id_part_proses})"><i class="bi bi-clock-history"></i></button>
            </td>
        </tr>`;
    }).join('');
}

function openPreventiveModal(idPartProses) {
    const row = currentRows.find(r => r.id_part_proses === idPartProses);
    if (!row) return;
    document.getElementById('preventiveForm').dataset.idPartProses = idPartProses;
    document.getElementById('pvDiesLabel').textContent = `${row.kode_mesin} — ${row.nama_proses}`;
    document.getElementById('pvResetPreview').textContent = `Stroke akan direset dari ${Number(row.current_stroke).toLocaleString()} ke 0.`;
    document.getElementById('pv_tanggal').value    = new Date().toISOString().slice(0, 10);
    document.getElementById('pv_tanggal').max      = new Date().toISOString().slice(0, 10);
    document.getElementById('pv_karyawan').value   = '';
    document.getElementById('pv_catatan').value    = '';
    document.getElementById('pvError').style.display = 'none';
    preventiveModal.show();
}

async function openHistoryModal(idPartProses) {
    const row = currentRows.find(r => r.id_part_proses === idPartProses);
    document.getElementById('hDiesLabel').textContent = row ? `${row.kode_mesin} — ${row.nama_proses}` : '';
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Memuat...</td></tr>';
    historyModal.show();

    const url = `{{ url('maintenance/availability/control-stroke-dies') }}/${idPartProses}/history`;
    const res  = await fetch(url);
    const data = await res.json();
    const logs = data.logs || [];

    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat preventive.</td></tr>';
        return;
    }
    tbody.innerHTML = logs.map(l => `<tr>
        <td>${l.tanggal_preventive}</td><td>${l.pic ?? '—'}</td>
        <td class="text-end">${Number(l.stroke_at_preventive).toLocaleString()}</td>
        <td>${l.catatan ?? '—'}</td><td>${l.input_by ?? '—'}</td>
    </tr>`).join('');
}

document.getElementById('preventiveForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const idPartProses = e.target.dataset.idPartProses;
    const errorEl = document.getElementById('pvError');
    errorEl.style.display = 'none';

    const url = `{{ url('maintenance/availability/control-stroke-dies') }}/${idPartProses}/preventive`;
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        body: JSON.stringify({
            tanggal_preventive: document.getElementById('pv_tanggal').value,
            id_karyawan:        document.getElementById('pv_karyawan').value,
            catatan:            document.getElementById('pv_catatan').value,
        }),
    });

    if (res.ok) {
        preventiveModal.hide();
        loadData();
        return;
    }
    const err = await res.json().catch(() => ({}));
    errorEl.textContent = err.message || 'Gagal menyimpan preventive.';
    errorEl.style.display = 'block';
});

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });

document.addEventListener('DOMContentLoaded', () => {
    preventiveModal = new bootstrap.Modal(document.getElementById('preventiveModal'));
    historyModal    = new bootstrap.Modal(document.getElementById('historyModal'));
    loadData();
});
</script>
@endpush
```

- [ ] **Step 2: Run the Task 3 feature test to verify the page still renders correctly**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS (9/9) — unchanged, since these are HTTP/JSON assertions, not JS/DOM assertions.

- [ ] **Step 3: Run the full suite**

Run: `php artisan test`
Expected: PASS (all tests, including Tasks 1-3's new tests and every pre-existing test).

- [ ] **Step 4: Manually verify in browser**

Log in as `admin`, visit `/maintenance/availability/control-stroke-dies`. Confirm:
- No date filter fields are present; Customer/Part No filters and "Tampilkan" work.
- Summary cards show correct counts.
- A dies row's progress bar width and color match its `pct`/`status` (create a test `TransaksiProduksi` via tinker or the input-produksi UI to push a real dies over 30,000 if none exist yet).
- Clicking "Preventive" opens the modal, shows the correct reset-preview number, and submitting it closes the modal, reloads the table, and the row's stroke drops to 0.
- Clicking "History" opens the modal and shows the just-created preventive entry.
- Log in as `manager`: the "Preventive" button is visibly disabled with a tooltip; "History" still works.
- Log in as `staff_maintenance`: "Preventive" button is enabled and submitting works.

- [ ] **Step 5: Commit**

```bash
git add resources/views/maintenance/control-stroke-dies.blade.php
git commit -m "feat(maintenance): rewrite Control Stroke Dies view with preventive workflow UI"
```

---

### Task 5: Final whole-branch review

**Files:** none (verification only)

**Interfaces:** none

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests PASS, including every test added in Tasks 1-3.

- [ ] **Step 2: Confirm Control Stroke Mesin is untouched**

Run: `git diff main --stat` (or the appropriate base branch)
Expected: no changes to `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`, `app/Services/MaintenanceDashboardService.php`, or `resources/views/maintenance/control-stroke-mesin.blade.php`.

- [ ] **Step 3: Manually smoke-test the full preventive cycle end-to-end**

Using a browser, log in as `admin`:
1. Find or create a dies with stroke ≥ 30,000 (overdue, red).
2. Open History — confirm it's empty ("Belum ada riwayat").
3. Click Preventive, fill the form, submit.
4. Confirm the row now shows `current_stroke: 0`, status `ok`, and "Preventive Terakhir" shows today's date and the chosen PIC.
5. Open History again — confirm the new entry appears with the correct `stroke_at_preventive` snapshot (the value it had BEFORE the reset, not 0).
6. Log in as `manager` and confirm the Preventive button is disabled but History still works.

- [ ] **Step 4: Review diff for scope creep**

Run: `git diff main --stat`
Expected: only the files listed in "File Structure" above are touched.
