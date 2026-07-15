# Mesin Preventive Maintenance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mirror the Dies preventive-maintenance dashboard onto Control Stroke Mesin: each machine tracks a running stroke counter since its last preventive (or since install), compared against a 300,000-stroke threshold, with an input-preventive page that resets the counter and a per-machine history page.

**Architecture:** New `mesin_preventive_log` table + model records every preventive event. `MaintenanceDashboardService` gains `STROKE_THRESHOLD`, `currentStrokeFor()` (single-machine running count) and `computeCurrentCycle()` (all-machines list), replacing the now-dead `computeStroke()`. `ControlStrokeMesinController` gains `createPreventive()`/`storePreventive()`/`history()` alongside rewired `index()`/`data()`. Three full pages, **no modals**. Control Stroke Dies is untouched except a shared-partial rename.

**Tech Stack:** Laravel 13 / PHP, Eloquent, Blade, Alpine.js (`taField` typeahead), Bootstrap 5, vanilla JS `fetch`, PHPUnit + RefreshDatabase.

## Global Constraints

- Threshold is a single constant `MaintenanceDashboardService::STROKE_THRESHOLD = 300000`, same for every machine. This is deliberately 10× the Dies threshold (30,000) because a machine's stroke count is the sum of every part it runs.
- Status bands: `pct < 80` → `ok`, `80 <= pct < 100` → `warning`, `pct >= 100` → `overdue`.
- `current_stroke` for a machine = `SUM(qty_aktual)` from `TransaksiProduksi` where `id_mesin` matches AND `tanggal_produksi > cycleStart`, where `cycleStart` = the machine's latest `mesin_preventive_log.tanggal_preventive`, falling back to `MasterMesin.tanggal_instalasi`, falling back to no lower bound (all-time) if both are null. Comparison is strictly `>`.
- **Listing scope: ALL machines** — no `whereHas('transaksiProduksi')` filter. This deliberately differs from Dies (which hides no-production rows because there are 386 of them); machines are few and an idle machine at 0 stroke is still useful information.
- Sort order: `overdue` rows first, then `warning`, then `ok`; within the same status, higher `pct` first.
- `stroke_at_preventive` is always computed server-side via `currentStrokeFor()` at submission time — never trust a client-supplied value.
- Only `admin` and `staff_maintenance` may open or submit the preventive form; `manager` gets 403 there but may still view `index`, `data`, and `history`.
- No date-range filter (`date_from`/`date_to`/`bulan`) anywhere on this page. Filters are Tipe Mesin + Mesin only.
- **No modals.** All three screens are full pages.
- No new migrations touch `master_mesin` or `transaksi_produksi` columns.
- Do NOT touch `MasterMesin.kapasitas_max_stroke` — it is a strokes/hour throughput rate, a different concept from the cumulative counter.
- Control Stroke Dies behaviour must not change; the only permitted touch is updating its two `@include` paths after the shared partial is renamed.

## Environment Notes (read before writing tests)

- ~~**`MasterMesin` has no `$casts`**, so `tanggal_instalasi` is a plain `'Y-m-d'` string, NOT a Carbon instance (unlike `MasterPart`, which casts it to `date`). It is safe to pass straight into a SQL comparison. Never call `->format()` on it without `Carbon::parse()` first.~~
  **NO LONGER TRUE as of commit b528e12 (2026-07-15):** `MasterMesin` now has `$casts = ['tanggal_instalasi' => 'date']`, so it IS a Carbon instance — added to fix a separate bug where editing a machine wiped its install date. `currentStrokeFor()` remains correct (Carbon binds as `'Y-m-d 00:00:00'`; strict `>` against a DATE column still excludes boundary-date production), guarded by `test_produksi_exactly_on_preventive_date_belongs_to_the_old_cycle`. Kept struck-through as a record of the constraint at planning time.
- **SQLite test-DB quirk:** `master_karyawan.departemen` still has a stale CHECK constraint allowing only `'Produksi'`/`'QC'` (the migration that widens it to include `'Maintenance'` is MySQL-only). In test fixtures always use `'departemen' => 'Produksi'`. No test asserts on that value, so this is harmless. This is pre-existing and out of scope.
- `users.role` already allows `'staff_maintenance'` (migration `2026_07_15_000002`), so `User::factory()->create(['role' => 'staff_maintenance'])` works.

---

## File Structure

- Create `database/migrations/2026_07_15_000003_create_mesin_preventive_log_table.php`.
- Modify `.github/workflows/deploy.yml:99-102` — add the new migration to the targeted-migrate step.
- Create `app/Models/MesinPreventiveLog.php`.
- Modify `app/Models/MasterMesin.php` — add `preventiveLogs()` relation.
- Modify `app/Services/MaintenanceDashboardService.php` — add `STROKE_THRESHOLD`, `currentStrokeFor()`, `computeCurrentCycle()`; remove `computeStroke()`.
- Modify `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php` — rewrite.
- Modify `routes/web.php` — add 3 routes after the existing `control-stroke-mesin.data` route.
- Modify `resources/views/maintenance/control-stroke-mesin.blade.php` — full rewrite.
- Create `resources/views/maintenance/control-stroke-mesin-preventive.blade.php`.
- Create `resources/views/maintenance/control-stroke-mesin-history.blade.php`.
- Rename `resources/views/maintenance/partials/dies-stroke-summary.blade.php` → `resources/views/maintenance/partials/stroke-summary.blade.php`; update the two `@include`s in `control-stroke-dies-preventive.blade.php` and `control-stroke-dies-history.blade.php`.
- Create `tests/Unit/MesinPreventiveLogTest.php`.
- Delete `tests/Unit/MaintenanceDashboardServiceStrokeTest.php`; create `tests/Unit/MaintenanceDashboardServiceCurrentCycleTest.php`.
- Modify `tests/Feature/Maintenance/ControlStrokeMesinTest.php` — rewrite.

---

### Task 1: `mesin_preventive_log` migration + model + relation + deploy wiring

**Files:**
- Create: `database/migrations/2026_07_15_000003_create_mesin_preventive_log_table.php`
- Create: `app/Models/MesinPreventiveLog.php`
- Modify: `app/Models/MasterMesin.php`
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Unit/MesinPreventiveLogTest.php`

**Interfaces:**
- Consumes: `master_mesin.id`, `master_karyawan.id`, `users.id` (existing tables/PKs).
- Produces: `App\Models\MesinPreventiveLog` with fillable `['id_mesin', 'tanggal_preventive', 'id_karyawan', 'stroke_at_preventive', 'catatan', 'created_by']`, cast `'tanggal_preventive' => 'date:Y-m-d'`, relations `mesin(): BelongsTo`, `karyawan(): BelongsTo`, `createdBy(): BelongsTo`. `MasterMesin::preventiveLogs(): HasMany` ordered `orderByDesc('tanggal_preventive')`. Consumed by Task 2 (service) and Task 3 (controller).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MesinPreventiveLogTest.php`:

```php
<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MesinPreventiveLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MesinPreventiveLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create([
            'id_mesin' => 'MPL-01', 'nama_mesin' => 'MPL Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        return compact('user', 'karyawan', 'mesin');
    }

    public function test_can_create_log_with_all_fields_and_relations_resolve(): void
    {
        $f = $this->fixtures();

        $log = MesinPreventiveLog::create([
            'id_mesin'             => $f['mesin']->id,
            'tanggal_preventive'   => '2026-07-01',
            'id_karyawan'          => $f['karyawan']->id,
            'stroke_at_preventive' => 301500,
            'catatan'              => 'Ganti bearing',
            'created_by'           => $f['user']->id,
        ]);

        $this->assertSame('2026-07-01', $log->fresh()->tanggal_preventive->format('Y-m-d'));
        $this->assertSame(301500, $log->fresh()->stroke_at_preventive);
        $this->assertSame($f['mesin']->id, $log->mesin->id);
        $this->assertSame('Teknisi A', $log->karyawan->nama_karyawan);
        $this->assertSame($f['user']->id, $log->createdBy->id);
    }

    public function test_master_mesin_preventive_logs_relation_orders_newest_first(): void
    {
        $f = $this->fixtures();

        MesinPreventiveLog::create([
            'id_mesin' => $f['mesin']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 300100, 'created_by' => $f['user']->id,
        ]);
        MesinPreventiveLog::create([
            'id_mesin' => $f['mesin']->id, 'tanggal_preventive' => '2026-07-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 300200, 'created_by' => $f['user']->id,
        ]);

        $logs = $f['mesin']->fresh()->preventiveLogs;

        $this->assertCount(2, $logs);
        $this->assertSame('2026-07-01', $logs->first()->tanggal_preventive->format('Y-m-d'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Unit/MesinPreventiveLogTest.php`
Expected: FAIL — `Class "App\Models\MesinPreventiveLog" not found`.

- [ ] **Step 3: Create the migration**

Create `database/migrations/2026_07_15_000003_create_mesin_preventive_log_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesin_preventive_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_mesin')->constrained('master_mesin');
            $table->date('tanggal_preventive');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->integer('stroke_at_preventive');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index('id_mesin');
            $table->index('tanggal_preventive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesin_preventive_log');
    }
};
```

- [ ] **Step 4: Create the model**

Create `app/Models/MesinPreventiveLog.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MesinPreventiveLog extends Model
{
    use SoftDeletes;

    protected $table = 'mesin_preventive_log';

    protected $fillable = [
        'id_mesin', 'tanggal_preventive', 'id_karyawan',
        'stroke_at_preventive', 'catatan', 'created_by',
    ];

    protected $casts = [
        'tanggal_preventive' => 'date:Y-m-d',
    ];

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
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

- [ ] **Step 5: Add the relation to `MasterMesin`**

In `app/Models/MasterMesin.php`, add this method after `breakdownMesin()`:

```php
    public function preventiveLogs(): HasMany
    {
        return $this->hasMany(MesinPreventiveLog::class, 'id_mesin')
                    ->orderByDesc('tanggal_preventive');
    }
```

(The `HasMany` import already exists at the top of this file — no new `use` statement needed.)

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Unit/MesinPreventiveLogTest.php`
Expected: PASS (2/2)

- [ ] **Step 7: Wire the migration into the deploy pipeline**

Production runs migrations by explicit `--path=` (schema drift — see the `CATATAN` comment above the step). Without this, the table is never created on prod and every page in this feature 500s.

In `.github/workflows/deploy.yml`, find the `Migrate (targeted) di server` step and add one more `--path=` line to the SAME command, keeping the three existing paths:

```yaml
      - name: Migrate (targeted) di server
        run: |
          ssh -p "${{ secrets.SSH_PORT }}" -i ~/.ssh/deploy_key -o StrictHostKeyChecking=accept-new \
            "${{ secrets.SSH_USERNAME }}@${{ secrets.SSH_HOST }}" \
            "cd ${{ env.REMOTE_PATH }} && ${{ env.REMOTE_PHP }} artisan migrate --force \
              --path=database/migrations/2026_07_12_000001_change_tipe_mesin_to_string_on_master_mesin.php \
              --path=database/migrations/2026_07_15_000001_create_dies_preventive_log_table.php \
              --path=database/migrations/2026_07_15_000002_add_staff_maintenance_to_users_role_enum.php \
              --path=database/migrations/2026_07_15_000003_create_mesin_preventive_log_table.php"
```

Verify the filename matches the migration you created exactly — a single-character mismatch makes `--path=` silently match zero migrations.

- [ ] **Step 8: Run the full suite**

Run: `php artisan test`
Expected: PASS (all tests — this task is purely additive).

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_07_15_000003_create_mesin_preventive_log_table.php app/Models/MesinPreventiveLog.php app/Models/MasterMesin.php .github/workflows/deploy.yml tests/Unit/MesinPreventiveLogTest.php
git commit -m "feat(maintenance): add mesin_preventive_log table and model"
```

---

### Task 2: `MaintenanceDashboardService` — `currentStrokeFor()` + `computeCurrentCycle()`

**Files:**
- Modify: `app/Services/MaintenanceDashboardService.php`
- Delete: `tests/Unit/MaintenanceDashboardServiceStrokeTest.php`
- Create: `tests/Unit/MaintenanceDashboardServiceCurrentCycleTest.php`

**Interfaces:**
- Consumes: `App\Models\MesinPreventiveLog` (Task 1), `App\Models\MasterMesin::preventiveLogs()` (Task 1), `App\Models\TransaksiProduksi`.
- Produces:
  - `MaintenanceDashboardService::STROKE_THRESHOLD` (public const, `int`, value `300000`).
  - `MaintenanceDashboardService::currentStrokeFor(MasterMesin $m): int` — used by Task 3's `createPreventive()`, `storePreventive()`, and `history()`.
  - `MaintenanceDashboardService::computeCurrentCycle(?int $mesinId = null, ?string $tipeMesin = null): array` — each row: `['id_mesin' => int, 'kode_mesin' => string, 'nama_mesin' => string, 'tipe_mesin' => ?string, 'current_stroke' => int, 'threshold' => int, 'pct' => float, 'status' => string, 'last_preventive_date' => ?string, 'last_preventive_pic' => ?string]`. Used by Task 3's `data()`.
  - `computeStroke()` is REMOVED from this file.
- Leaves untouched: `compute()` (the MTTR/MTBF/Availability method) — do not modify it.

- [ ] **Step 1: Delete the superseded test file**

```bash
rm tests/Unit/MaintenanceDashboardServiceStrokeTest.php
```

This file tests `computeStroke()`, which this task removes.

- [ ] **Step 2: Write the failing tests**

Create `tests/Unit/MaintenanceDashboardServiceCurrentCycleTest.php`:

```php
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
        $user     = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part     = MasterPart::create(['part_no' => 'CC-P'.uniqid(), 'part_name' => 'CC Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create([
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

        $svc = new MaintenanceDashboardService();
        $this->assertSame(150, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_current_stroke_is_all_time_when_no_preventive_and_no_instalasi(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin(null);
        $this->produksi($f, $m, '2020-01-01', 40);
        $this->produksi($f, $m, '2026-01-01', 60);

        $svc = new MaintenanceDashboardService();
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

        $svc = new MaintenanceDashboardService();
        $this->assertSame(800, $svc->currentStrokeFor($m->fresh()));
    }

    public function test_current_stroke_uses_latest_preventive_not_earliest(): void
    {
        $f = $this->fixtures();
        $m = $this->mesin('2026-01-01');
        $this->preventive($f, $m, '2026-02-01', 100000);
        $this->preventive($f, $m, '2026-04-01', 200000);
        $this->produksi($f, $m, '2026-03-01', 5000); // di antara dua preventive, dikecualikan
        $this->produksi($f, $m, '2026-05-01', 700);  // setelah yang terakhir, dihitung

        $svc = new MaintenanceDashboardService();
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

        $svc  = new MaintenanceDashboardService();
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

        $svc  = new MaintenanceDashboardService();
        $rows = $svc->computeCurrentCycle();

        $this->assertSame('overdue', $rows[0]['status']);
        $this->assertSame('warning', $rows[1]['status']);
        $this->assertSame('ok', $rows[2]['status']);
    }

    public function test_mesin_without_any_transaksi_still_appears_with_zero_stroke(): void
    {
        $this->fixtures();
        $m = $this->mesin('2026-01-01'); // tidak ada TransaksiProduksi sama sekali

        $svc  = new MaintenanceDashboardService();
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

        $svc  = new MaintenanceDashboardService();
        $rows = collect($svc->computeCurrentCycle())->keyBy('id_mesin');

        $this->assertSame(0, $rows[$m->id]['current_stroke']);
        $this->assertSame('2026-03-01', $rows[$m->id]['last_preventive_date']);
        $this->assertSame('Teknisi A', $rows[$m->id]['last_preventive_pic']);
    }

    public function test_mesin_id_filter_narrows_to_single_mesin(): void
    {
        $f  = $this->fixtures();
        $m1 = $this->mesin('2026-01-01');
        $m2 = $this->mesin('2026-01-01');
        $this->produksi($f, $m1, '2026-02-01', 100);
        $this->produksi($f, $m2, '2026-02-01', 999);

        $svc  = new MaintenanceDashboardService();
        $rows = $svc->computeCurrentCycle($m1->id);

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['current_stroke']);
    }

    public function test_tipe_mesin_filter_narrows_result(): void
    {
        $f  = $this->fixtures();
        $m1 = $this->mesin('2026-01-01', 'PROGRESSIVE');
        $m2 = $this->mesin('2026-01-01', 'STAMPING');
        $this->produksi($f, $m1, '2026-02-01', 100);
        $this->produksi($f, $m2, '2026-02-01', 200);

        $svc  = new MaintenanceDashboardService();
        $rows = $svc->computeCurrentCycle(null, 'STAMPING');

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['current_stroke']);
        $this->assertSame('STAMPING', $rows[0]['tipe_mesin']);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test tests/Unit/MaintenanceDashboardServiceCurrentCycleTest.php`
Expected: FAIL — `Call to undefined method App\Services\MaintenanceDashboardService::currentStrokeFor()`.

- [ ] **Step 4: Implement the service changes**

In `app/Services/MaintenanceDashboardService.php`:

1. Add `use App\Models\MesinPreventiveLog;` to the imports.
2. Remove the entire `computeStroke()` method.
3. Add the constant as the first member of the class (directly under `class MaintenanceDashboardService {`):

```php
    /** Target preventive mesin (stroke). Sengaja 10× threshold dies: stroke mesin
        adalah gabungan semua part yang dikerjakan mesin itu. */
    public const STROKE_THRESHOLD = 300000;
```

4. Add these two methods after `compute()`:

```php
    public function currentStrokeFor(MasterMesin $m): int
    {
        $lastPreventive = MesinPreventiveLog::where('id_mesin', $m->id)->max('tanggal_preventive');
        $cycleStart     = $lastPreventive ?? $m->tanggal_instalasi;

        return (int) TransaksiProduksi::query()
            ->where('id_mesin', $m->id)
            ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
            ->sum('qty_aktual');
    }

    public function computeCurrentCycle(?int $mesinId = null, ?string $tipeMesin = null): array
    {
        $hasTipe = Schema::hasColumn('master_mesin', 'tipe_mesin');

        $mesins = MasterMesin::with('preventiveLogs.karyawan')
            ->when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
            ->orderBy('id_mesin')->get();

        $rows = [];
        foreach ($mesins as $m) {
            $currentStroke = $this->currentStrokeFor($m);
            $pct           = round($currentStroke / self::STROKE_THRESHOLD * 100, 1);
            $status        = $pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok');
            $lastLog       = $m->preventiveLogs->first();

            $rows[] = [
                'id_mesin'             => $m->id,
                'kode_mesin'           => $m->id_mesin,
                'nama_mesin'           => $m->nama_mesin,
                'tipe_mesin'           => $m->tipe_mesin,
                'current_stroke'       => $currentStroke,
                'threshold'            => self::STROKE_THRESHOLD,
                'pct'                  => $pct,
                'status'               => $status,
                'last_preventive_date' => $lastLog?->tanggal_preventive?->format('Y-m-d'),
                'last_preventive_pic'  => $lastLog?->karyawan?->nama_karyawan,
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

Note: there is deliberately NO `whereHas('transaksiProduksi')` filter — every machine is listed (see Global Constraints).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/MaintenanceDashboardServiceCurrentCycleTest.php`
Expected: PASS (11/11)

- [ ] **Step 6: Run the full suite**

Run: `php artisan test`
Expected: FAIL — `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php` still calls the removed `computeStroke()`, so `tests/Feature/Maintenance/ControlStrokeMesinTest.php` errors. This is expected; Task 3 fixes the controller. Confirm the ONLY failures are in `ControlStrokeMesinTest.php` — if any other test file fails, stop and investigate before committing.

- [ ] **Step 7: Commit**

```bash
git add app/Services/MaintenanceDashboardService.php tests/Unit/MaintenanceDashboardServiceCurrentCycleTest.php
git rm tests/Unit/MaintenanceDashboardServiceStrokeTest.php
git commit -m "feat(maintenance): replace computeStroke() with running-cycle computeCurrentCycle() on mesin service"
```

---

### Task 3: `ControlStrokeMesinController` rewrite + routes

**Files:**
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Maintenance/ControlStrokeMesinTest.php` (full rewrite)

**Interfaces:**
- Consumes: `MaintenanceDashboardService::computeCurrentCycle()`, `::currentStrokeFor()`, `::STROKE_THRESHOLD` (Task 2), `App\Models\MesinPreventiveLog` (Task 1), `App\Models\MasterKaryawan::aktif()` scope (existing), `App\Models\MasterMesin::TIPE_MESIN` const (existing).
- Produces:
  - `GET maintenance.control-stroke-mesin` (unchanged name/path) — view gets `mesinList` + `tipeList`.
  - `GET maintenance.control-stroke-mesin.data` (unchanged name/path) — JSON `{rows: [...9-field rows from Task 2...], summary: {total_mesin: int, overdue: int, warning: int}}`. No longer accepts `date_from`/`date_to`/`bulan`.
  - `GET maintenance.control-stroke-mesin.history` (NEW) — `{mesin}` param, renders view `maintenance.control-stroke-mesin-history` with `mesin`, `logs`, `currentStroke`, `threshold`.
  - `GET maintenance.control-stroke-mesin.preventive.create` (NEW) — `{mesin}` param, renders view `maintenance.control-stroke-mesin-preventive` with `mesin`, `currentStroke`, `threshold`, `karyawanList`. 403 for non-admin/non-staff_maintenance.
  - `POST maintenance.control-stroke-mesin.preventive` (NEW) — `{mesin}` param, body `{tanggal_preventive, id_karyawan, catatan?}`, redirects to index with `success` flash. 403 for manager.
  Views are created in Tasks 4-5.

- [ ] **Step 1: Write the failing tests**

Replace the full contents of `tests/Feature/Maintenance/ControlStrokeMesinTest.php`:

```php
<?php
namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\MesinPreventiveLog;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlStrokeMesinTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create([
            'id_mesin' => 'CS-01', 'nama_mesin' => 'CS Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01',
        ]);
        $part     = MasterPart::create(['part_no' => 'CS-P1', 'part_name' => 'CS Part', 'customer' => 'Toyota']);
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

    public function test_data_endpoint_returns_current_cycle_rows_and_summary(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 310000); // di atas threshold -> overdue

        $response = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-mesin.data'));

        $response->assertOk();
        $response->assertJsonPath('summary.total_mesin', 1);
        $response->assertJsonPath('summary.overdue', 1);
        $response->assertJsonPath('summary.warning', 0);
        $response->assertJsonFragment(['kode_mesin' => 'CS-01', 'current_stroke' => 310000, 'status' => 'overdue', 'threshold' => 300000]);
    }

    public function test_admin_can_view_preventive_form_page(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 250000);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-mesin.preventive.create', $f['mesin']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin-preventive');
        $response->assertViewHas('currentStroke', 250000);
        $response->assertViewHas('threshold', 300000);
        $response->assertViewHas('karyawanList');
    }

    public function test_manager_cannot_view_preventive_form_page(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->get(route('maintenance.control-stroke-mesin.preventive.create', $f['mesin']->id));

        $response->assertForbidden();
    }

    public function test_history_page_shows_logs_for_requested_mesin_only(): void
    {
        $f = $this->fixtures();
        MesinPreventiveLog::create([
            'id_mesin' => $f['mesin']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 300500,
            'catatan' => 'Ganti bearing', 'created_by' => $f['admin']->id,
        ]);

        $otherMesin = MasterMesin::create([
            'id_mesin' => 'OTHER-01', 'nama_mesin' => 'Other', 'line' => '2',
            'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100,
        ]);
        MesinPreventiveLog::create([
            'id_mesin' => $otherMesin->id, 'tanggal_preventive' => '2026-05-02',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 12000, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-mesin.history', $f['mesin']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin-history');
        $response->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->stroke_at_preventive === 300500);
        $response->assertSee('Ganti bearing');
        $response->assertSee('Teknisi A');
        $response->assertDontSee('12000'); // log mesin lain tidak ikut terbawa
    }

    public function test_manager_can_view_history_page(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->get(route('maintenance.control-stroke-mesin.history', $f['mesin']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin-history');
    }

    public function test_admin_can_store_preventive_and_it_resets_the_running_cycle(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 300500);

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'catatan'            => 'Preventive rutin',
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-mesin'));
        $response->assertSessionHas('success');

        $log = MesinPreventiveLog::first();
        $this->assertSame(300500, $log->stroke_at_preventive);
        $this->assertSame($f['admin']->id, $log->created_by);

        $dataResponse = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-mesin.data'));
        $dataResponse->assertJsonFragment(['current_stroke' => 0, 'status' => 'ok']);
    }

    public function test_staff_maintenance_can_store_preventive(): void
    {
        $f     = $this->fixtures();
        $staff = User::factory()->create(['role' => 'staff_maintenance']);

        $response = $this->actingAs($staff)->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-mesin'));
        $this->assertDatabaseCount('mesin_preventive_log', 1);
    }

    public function test_manager_cannot_store_preventive(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('mesin_preventive_log', 0);
    }

    public function test_store_preventive_rejects_future_date(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => now()->addDay()->toDateString(),
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertSessionHasErrors('tanggal_preventive');
        $this->assertDatabaseCount('mesin_preventive_log', 0);
    }

    public function test_store_preventive_rejects_unknown_karyawan(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => 999999,
        ]);

        $response->assertSessionHasErrors('id_karyawan');
        $this->assertDatabaseCount('mesin_preventive_log', 0);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: FAIL — routes `maintenance.control-stroke-mesin.history` / `.preventive.create` / `.preventive` not defined, and `computeStroke()` no longer exists on the service the current controller calls.

- [ ] **Step 3: Rewrite the controller**

Replace the full contents of `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`:

```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MesinPreventiveLog;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;

class ControlStrokeMesinController extends Controller
{
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
        $mesinId   = (int) $request->input('mesin_id', 0) ?: null;
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows      = $this->svc->computeCurrentCycle($mesinId, $tipeMesin);

        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'total_mesin' => count($rows),
                'overdue'     => collect($rows)->where('status', 'overdue')->count(),
                'warning'     => collect($rows)->where('status', 'warning')->count(),
            ],
        ]);
    }

    public function createPreventive(MasterMesin $mesin)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        return view('maintenance.control-stroke-mesin-preventive', [
            'mesin'         => $mesin,
            'currentStroke' => $this->svc->currentStrokeFor($mesin),
            'threshold'     => MaintenanceDashboardService::STROKE_THRESHOLD,
            'karyawanList'  => MasterKaryawan::aktif()->where('departemen', 'Maintenance')->orderBy('nama_karyawan')->get(),
        ]);
    }

    public function storePreventive(MasterMesin $mesin, Request $request)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        $validated = $request->validate([
            'tanggal_preventive' => 'required|date|before_or_equal:today',
            'id_karyawan'        => 'required|exists:master_karyawan,id',
            'catatan'            => 'nullable|string|max:1000',
        ]);

        $currentStroke = $this->svc->currentStrokeFor($mesin);

        MesinPreventiveLog::create([
            'id_mesin'             => $mesin->id,
            'tanggal_preventive'   => $validated['tanggal_preventive'],
            'id_karyawan'          => $validated['id_karyawan'],
            'stroke_at_preventive' => $currentStroke,
            'catatan'              => $validated['catatan'] ?? null,
            'created_by'           => auth()->id(),
        ]);

        return redirect()->route('maintenance.control-stroke-mesin')->with(
            'success',
            "Preventive {$mesin->id_mesin} — {$mesin->nama_mesin} tersimpan. Stroke direset dari "
            . number_format($currentStroke, 0, ',', '.') . ' ke 0.'
        );
    }

    public function history(MasterMesin $mesin)
    {
        return view('maintenance.control-stroke-mesin-history', [
            'mesin'         => $mesin,
            'logs'          => $mesin->preventiveLogs()
                ->with(['karyawan:id,nama_karyawan', 'createdBy:id,name'])
                ->orderByDesc('tanggal_preventive')
                ->get(),
            'currentStroke' => $this->svc->currentStrokeFor($mesin),
            'threshold'     => MaintenanceDashboardService::STROKE_THRESHOLD,
        ]);
    }
}
```

Note the `use App\Http\Controllers\Maintenance\Concerns\ResolvesMaintenanceRange;` import and the `use ResolvesMaintenanceRange;` trait line are both GONE — there is no date filter any more.

- [ ] **Step 4: Add the three new routes**

In `routes/web.php`, find these existing lines:

```php
        Route::get('availability/control-stroke-mesin',      [ControlStrokeMesinController::class, 'index'])->name('control-stroke-mesin');
        Route::get('availability/control-stroke-mesin/data',  [ControlStrokeMesinController::class, 'data'])->name('control-stroke-mesin.data');
```

Add these three lines directly after them (same `role:admin,staff_maintenance,manager` group — `createPreventive`/`storePreventive` self-gate the stricter 2-role check inside their method bodies):

```php
        Route::get('availability/control-stroke-mesin/{mesin}/history', [ControlStrokeMesinController::class, 'history'])->name('control-stroke-mesin.history');
        Route::get('availability/control-stroke-mesin/{mesin}/preventive', [ControlStrokeMesinController::class, 'createPreventive'])->name('control-stroke-mesin.preventive.create');
        Route::post('availability/control-stroke-mesin/{mesin}/preventive', [ControlStrokeMesinController::class, 'storePreventive'])->name('control-stroke-mesin.preventive');
```

No new `use` import is needed — `ControlStrokeMesinController` is already imported for the two existing routes.

- [ ] **Step 5: Run tests**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: 9 of 12 PASS. Exactly these 3 FAIL with `View [maintenance.control-stroke-mesin-preventive] not found` / `View [maintenance.control-stroke-mesin-history] not found`:
- `test_admin_can_view_preventive_form_page`
- `test_history_page_shows_logs_for_requested_mesin_only`
- `test_manager_can_view_history_page`

(`test_manager_cannot_view_preventive_form_page` PASSES — it 403s before reaching the view. `test_admin_can_view_control_stroke_mesin_page` PASSES — that view still exists; it gets rewritten in Task 4.)

This is expected; Task 5 adds the two missing views. If a test fails for any OTHER reason, stop and investigate.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeMesinController.php routes/web.php tests/Feature/Maintenance/ControlStrokeMesinTest.php
git commit -m "feat(maintenance): add mesin preventive/history endpoints, switch data() to running-cycle stroke"
```

---

### Task 4: Rewrite `control-stroke-mesin.blade.php` (monitoring table)

**Files:**
- Modify: `resources/views/maintenance/control-stroke-mesin.blade.php`

**Interfaces:**
- Consumes: view data from `ControlStrokeMesinController::index()` (Task 3) — `$mesinList` (collection of `MasterMesin`, each with `id`, `id_mesin`, `nama_mesin`), `$tipeList` (array of strings). JSON from `route('maintenance.control-stroke-mesin.data')` — `{rows: [{id_mesin, kode_mesin, nama_mesin, tipe_mesin, current_stroke, threshold, pct, status, last_preventive_date, last_preventive_pic}], summary: {total_mesin, overdue, warning}}`. Links to `maintenance.control-stroke-mesin.preventive.create` and `.history` (Task 3 routes; the pages themselves land in Task 5).
- Produces: nothing consumed by later tasks.

- [ ] **Step 1: Replace the full contents of the view**

```blade
@extends('layouts.app')
@section('title', 'Control Stroke Mesin')
@section('content')
<style>
    /* Kolom mengikuti panjang isinya; tabel geser horizontal lewat .table-responsive. */
    #strokeTable tbody td { white-space: nowrap; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Mesin</h4>
    <div class="text-muted small">Target preventive: <strong>300.000 stroke</strong> / mesin</div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Tipe Mesin</label>
                <select id="tipe_mesin" name="tipe_mesin" class="form-select form-select-sm">
                    <option value="">Semua Tipe</option>
                    @foreach($tipeList as $tipe)
                        <option value="{{ $tipe }}">{{ $tipe }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
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
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="erp-panel h-100" style="border-left:4px solid #6b7280">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="bi bi-cpu-fill" style="font-size:1.9rem;color:#6b7280"></i>
                <div>
                    <div class="text-muted small">Total Mesin Dipantau</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1"><span id="kpiTotalMesin">0</span></div>
                    <div class="text-muted" style="font-size:11px">seluruh mesin terdaftar</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100" style="border-left:4px solid #b91c1c">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="bi bi-exclamation-octagon-fill" style="font-size:1.9rem;color:#b91c1c"></i>
                <div>
                    <div class="text-muted small">Segera Preventive</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1;color:#b91c1c"><span id="kpiOverdue">0</span></div>
                    <div class="text-muted" style="font-size:11px">sudah lewat batas 300.000</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100" style="border-left:4px solid #b45309">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:1.9rem;color:#b45309"></i>
                <div>
                    <div class="text-muted small">Mendekati Batas</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1;color:#b45309"><span id="kpiWarning">0</span></div>
                    <div class="text-muted" style="font-size:11px">sudah &ge; 80% (240.000 stroke)</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table" id="strokeTable">
                <thead>
                    <tr>
                        <th>Mesin</th><th>Nama Mesin</th><th>Tipe</th>
                        <th style="min-width:240px">Stroke Saat Ini</th>
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
@endsection

@push('scripts')
<script>
const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin]));
const canInputPreventive = {{ in_array(auth()->user()->role, ['admin', 'staff_maintenance']) ? 'true' : 'false' }};
const baseUrl = `{{ url('maintenance/availability/control-stroke-mesin') }}`;

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmt(n) { return Number(n || 0).toLocaleString('id-ID'); }

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

// warna & label per status — dipakai badge, teks %, bar, dan tint baris
const STATUS = {
    ok:      { label: 'OK',                badge: 'bg-success',           bar: 'bg-success', icon: 'bi-check-circle-fill',         color: '#15803d', tint: '' },
    warning: { label: 'Perlu Perhatian',   badge: 'bg-warning text-dark', bar: 'bg-warning', icon: 'bi-exclamation-triangle-fill', color: '#b45309', tint: '#fffbeb' },
    overdue: { label: 'Segera Preventive', badge: 'bg-danger',            bar: 'bg-danger',  icon: 'bi-exclamation-octagon-fill',  color: '#b91c1c', tint: '#fef2f2' },
};

let currentRows = [];

async function loadData() {
    const params = new URLSearchParams({
        mesin_id:   document.getElementById('mesin_id').value,
        tipe_mesin: document.getElementById('tipe_mesin').value,
    });
    const tbody = document.getElementById('strokeTableBody');
    let data;
    try {
        const res = await fetch(`{{ route('maintenance.control-stroke-mesin.data') }}?${params}`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        data = await res.json();
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Gagal memuat data — coba lagi.</td></tr>';
        return;
    }
    currentRows = data.rows || [];

    document.getElementById('kpiTotalMesin').textContent = fmt(data.summary?.total_mesin ?? 0);
    document.getElementById('kpiOverdue').textContent    = fmt(data.summary?.overdue ?? 0);
    document.getElementById('kpiWarning').textContent    = fmt(data.summary?.warning ?? 0);

    if (!currentRows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = currentRows.map(r => {
        const st       = STATUS[r.status] || STATUS.ok;
        const barPct   = Math.min(r.pct, 100);
        const sisa     = r.threshold - r.current_stroke;
        const sisaTxt  = sisa >= 0
            ? `<span class="text-muted">sisa ${fmt(sisa)} stroke</span>`
            : `<span style="color:#b91c1c;font-weight:600">lewat ${fmt(-sisa)} stroke</span>`;
        const rowStyle = st.tint ? `style="background:${st.tint}"` : '';
        const lastPrev = r.last_preventive_date
            ? `${r.last_preventive_date} <span class="text-muted">· ${escHtml(r.last_preventive_pic) || '—'}</span>`
            : '<span class="text-muted">Belum pernah</span>';
        const prevBtn  = canInputPreventive
            ? `<a href="${baseUrl}/${r.id_mesin}/preventive" class="btn btn-primary btn-sm"><i class="bi bi-tools me-1"></i>Preventive</a>`
            : `<button type="button" class="btn btn-primary btn-sm" disabled title="Hanya Maintenance yang bisa input"><i class="bi bi-tools me-1"></i>Preventive</button>`;
        return `<tr ${rowStyle}>
            <td style="font-weight:600">${escHtml(r.kode_mesin)}</td>
            <td>${escHtml(r.nama_mesin)}</td>
            <td>${escHtml(r.tipe_mesin) || '—'}</td>
            <td>
                <div class="d-flex justify-content-between align-items-baseline mb-1">
                    <span><strong>${fmt(r.current_stroke)}</strong> <span class="text-muted">/ ${fmt(r.threshold)}</span></span>
                    <strong style="color:${st.color}">${r.pct}%</strong>
                </div>
                <div class="progress" style="height:8px;background:#e5e7eb;border-radius:4px">
                    <div class="progress-bar ${st.bar}" style="width:${barPct}%"></div>
                </div>
                <div style="font-size:11px;margin-top:3px">${sisaTxt}</div>
            </td>
            <td><span class="badge ${st.badge}"><i class="bi ${st.icon} me-1"></i>${st.label}</span></td>
            <td>${lastPrev}</td>
            <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                    ${prevBtn}
                    <a href="${baseUrl}/${r.id_mesin}/history" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Riwayat</a>
                </div>
            </td>
        </tr>`;
    }).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });

document.addEventListener('DOMContentLoaded', loadData);
</script>
@endpush
```

- [ ] **Step 2: Run the feature test**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: unchanged from Task 3 — 9 pass, 3 still fail on the two missing views (Task 5). `test_admin_can_view_control_stroke_mesin_page` must still PASS.

- [ ] **Step 3: Commit**

```bash
git add resources/views/maintenance/control-stroke-mesin.blade.php
git commit -m "feat(maintenance): rewrite Control Stroke Mesin table with preventive status UI"
```

---

### Task 5: Shared partial rename + mesin preventive & history pages

**Files:**
- Rename: `resources/views/maintenance/partials/dies-stroke-summary.blade.php` → `resources/views/maintenance/partials/stroke-summary.blade.php`
- Modify: `resources/views/maintenance/control-stroke-dies-preventive.blade.php` (include path only)
- Modify: `resources/views/maintenance/control-stroke-dies-history.blade.php` (include path only)
- Create: `resources/views/maintenance/control-stroke-mesin-preventive.blade.php`
- Create: `resources/views/maintenance/control-stroke-mesin-history.blade.php`

**Interfaces:**
- Consumes: `ControlStrokeMesinController::createPreventive()` view data (Task 3) — `$mesin` (`MasterMesin`), `$currentStroke` (int), `$threshold` (int), `$karyawanList` (collection of `MasterKaryawan` with `id`, `nama_karyawan`). `::history()` view data — `$mesin`, `$logs` (collection of `MesinPreventiveLog` with `tanggal_preventive` Carbon, `stroke_at_preventive`, `catatan`, relations `karyawan`, `createdBy`), `$currentStroke`, `$threshold`. Posts to `maintenance.control-stroke-mesin.preventive`.
- Produces: partial `maintenance.partials.stroke-summary` requiring `$currentStroke` + `$threshold` — consumed by both mesin pages and both dies pages.

- [ ] **Step 1: Rename the shared partial**

```bash
git mv resources/views/maintenance/partials/dies-stroke-summary.blade.php resources/views/maintenance/partials/stroke-summary.blade.php
```

The partial's contents are already generic (it only needs `$currentStroke` and `$threshold`) — do NOT edit its body.

- [ ] **Step 2: Update the two Dies includes**

In `resources/views/maintenance/control-stroke-dies-preventive.blade.php`, change:

```blade
            @include('maintenance.partials.dies-stroke-summary', [
```

to:

```blade
            @include('maintenance.partials.stroke-summary', [
```

In `resources/views/maintenance/control-stroke-dies-history.blade.php`, change:

```blade
                    @include('maintenance.partials.dies-stroke-summary', [
```

to:

```blade
                    @include('maintenance.partials.stroke-summary', [
```

- [ ] **Step 3: Verify the Dies pages still render**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS (12/12). If any Dies test fails with `View [maintenance.partials.dies-stroke-summary] not found`, an include was missed — grep for it:
`grep -rn "dies-stroke-summary" resources/` must return nothing.

- [ ] **Step 4: Create the mesin preventive form page**

Create `resources/views/maintenance/control-stroke-mesin-preventive.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Input Preventive Mesin')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-tools"></i>Input Preventive Mesin</h1>
    <a href="{{ route('maintenance.control-stroke-mesin') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:750px">
    <div class="erp-panel-header">Form Input Preventive
        <span style="font-size:0.75rem;font-weight:400;color:#9ca3af;margin-left:8px">
            {{ $mesin->id_mesin }} · {{ $mesin->nama_mesin }} · Line {{ $mesin->line }}
        </span>
    </div>
    <div class="erp-panel-body">

        <div class="mb-3">
            @include('maintenance.partials.stroke-summary', [
                'currentStroke' => $currentStroke,
                'threshold'     => $threshold,
            ])
        </div>

        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" style="font-size:14px">
            <i class="bi bi-arrow-counterclockwise"></i>
            <span>Setelah disimpan, stroke mesin ini direset dari
                  <strong>{{ number_format($currentStroke, 0, ',', '.') }}</strong> ke <strong>0</strong>.</span>
        </div>

        <form method="POST" action="{{ route('maintenance.control-stroke-mesin.preventive', $mesin) }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Tanggal Preventive <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_preventive"
                           class="form-control @error('tanggal_preventive') is-invalid @enderror"
                           value="{{ old('tanggal_preventive', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}">
                    @error('tanggal_preventive')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">PIC / Petugas <span class="text-danger">*</span></label>
                    <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Pilih Petugas --</option>
                        @foreach($karyawanList as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @if($karyawanList->isEmpty())
                        <div class="form-text text-danger">
                            Belum ada karyawan departemen Maintenance yang aktif. Tambahkan dulu di Master Karyawan.
                        </div>
                    @endif
                </div>

                <div class="col-12">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" rows="3"
                              class="form-control @error('catatan') is-invalid @enderror"
                              placeholder="Opsional — mis. ganti bearing, cek clutch/brake">{{ old('catatan') }}</textarea>
                    @error('catatan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan &amp; Reset ke 0
                </button>
                <a href="{{ route('maintenance.control-stroke-mesin') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Create the mesin history page**

Create `resources/views/maintenance/control-stroke-mesin-history.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Riwayat Preventive Mesin')

@php
    $totalPrev = $logs->count();
    $lastPrev  = $logs->first();
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-clock-history"></i>Riwayat Preventive Mesin</h1>
    <a href="{{ route('maintenance.control-stroke-mesin') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="erp-panel h-100">
            <div class="erp-panel-header">Mesin</div>
            <div class="erp-panel-body">
                <div style="font-size:1.15rem;font-weight:700">{{ $mesin->id_mesin }}</div>
                <div class="text-muted">{{ $mesin->nama_mesin }}</div>
                <div class="mt-2 d-flex gap-4 flex-wrap" style="font-size:13px">
                    <div><span class="text-muted">Tipe:</span> <strong>{{ $mesin->tipe_mesin ?? '—' }}</strong></div>
                    <div><span class="text-muted">Line:</span> <strong>{{ $mesin->line ?? '—' }}</strong></div>
                </div>
                <div class="mt-3">
                    @include('maintenance.partials.stroke-summary', [
                        'currentStroke' => $currentStroke,
                        'threshold'     => $threshold,
                    ])
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="erp-panel h-100">
            <div class="erp-panel-header">Ringkasan Preventive</div>
            <div class="erp-panel-body">
                <div class="mb-3">
                    <div class="text-muted small">Total Preventive</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1">{{ $totalPrev }}</div>
                    <div class="text-muted" style="font-size:11px">kali sejak mesin dipakai</div>
                </div>
                <div>
                    <div class="text-muted small">Preventive Terakhir</div>
                    @if($lastPrev)
                        <div style="font-weight:700">{{ $lastPrev->tanggal_preventive->format('d M Y') }}</div>
                        <div class="text-muted" style="font-size:12px">
                            oleh {{ $lastPrev->karyawan?->nama_karyawan ?? '—' }} ·
                            di {{ number_format($lastPrev->stroke_at_preventive, 0, ',', '.') }} stroke
                        </div>
                    @else
                        <div class="text-muted">Belum pernah dipreventive</div>
                    @endif
                </div>
                @if(in_array(auth()->user()->role, ['admin', 'staff_maintenance']))
                    <a href="{{ route('maintenance.control-stroke-mesin.preventive.create', $mesin) }}"
                       class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-tools me-1"></i>Input Preventive Baru
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Riwayat Preventive</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Tanggal</th><th>PIC / Petugas</th>
                        <th class="text-end">Stroke Tercapai</th>
                        <th>Catatan</th><th>Diinput Oleh</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td style="white-space:nowrap;font-weight:600">{{ $log->tanggal_preventive->format('d M Y') }}</td>
                        <td style="white-space:nowrap">{{ $log->karyawan?->nama_karyawan ?? '—' }}</td>
                        <td class="text-end" style="white-space:nowrap">{{ number_format($log->stroke_at_preventive, 0, ',', '.') }}</td>
                        <td>{{ $log->catatan ?? '—' }}</td>
                        <td style="white-space:nowrap">{{ $log->createdBy?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <i class="bi bi-clock-history d-block mb-2" style="font-size:1.6rem;color:#9ca3af"></i>
                            Belum ada riwayat preventive untuk mesin ini.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Run the mesin feature tests — all should pass now**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeMesinTest.php`
Expected: PASS (12/12)

- [ ] **Step 7: Run the full suite**

Run: `php artisan test`
Expected: PASS (all tests).

- [ ] **Step 8: Commit**

```bash
git add resources/views/maintenance/
git commit -m "feat(maintenance): add mesin preventive + history pages, share stroke-summary partial"
```

---

### Task 6: Final whole-branch review

**Files:** none (verification only)

**Interfaces:** none

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test`
Expected: all tests PASS.

- [ ] **Step 2: Confirm no modals were introduced on this feature**

Run: `grep -rn "modal" resources/views/maintenance/control-stroke-mesin*.blade.php`
Expected: no output. All three mesin screens are full pages.

- [ ] **Step 3: Confirm the deploy pipeline runs the new migration**

Run: `grep -n "2026_07_15_000003" .github/workflows/deploy.yml`
Expected: one match inside the targeted-migrate step. Without it the table is never created on prod and every page 500s.

- [ ] **Step 4: Confirm the old partial name is fully gone**

Run: `grep -rn "dies-stroke-summary" resources/`
Expected: no output.

- [ ] **Step 5: Confirm Control Stroke Dies behaviour is unchanged**

Run: `git diff main --stat -- resources/views/maintenance/control-stroke-dies-preventive.blade.php resources/views/maintenance/control-stroke-dies-history.blade.php`
Expected: only the two `@include` path lines changed (1 insertion + 1 deletion per file). Any other Dies change is scope creep.

- [ ] **Step 6: Review diff for scope creep**

Run: `git diff main --stat`
Expected: only the files listed in "File Structure" above are touched. Confirm `MasterMesin.kapasitas_max_stroke` was not modified and no migration touches `master_mesin`/`transaksi_produksi` columns.
