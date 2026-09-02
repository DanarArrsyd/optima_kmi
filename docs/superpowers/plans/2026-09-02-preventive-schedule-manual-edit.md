# Schedule Preventive — Edit Manual Tanggal PLAN Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Admin/staff_maintenance bisa geser tanggal PLAN preventive satu item secara manual lewat grid kalender HTML baru di halaman Control Stroke, item itu terkunci dari regenerate algoritma berikutnya, dan bisa direset balik ke otomatis kapan saja.

**Architecture:** Migrasi ALTER tambah 3 kolom lock-tracking ke `preventive_schedule_items`. `PreventiveScheduleService::generate()` diubah 2 tempat (skip delete item locked, exclude target locked dari eligible biar nggak dobel). Endpoint PATCH/reset baru di kedua controller Control Stroke, digated role sama persis `scheduleGenerate()` existing. View baru: partial grid kalender HTML (adaptasi tabel PDF existing) di-include ke halaman Control Stroke mesin & dies, ditempeli komponen Alpine.js buat interaksi klik-edit-cell.

**Tech Stack:** Laravel 13, MySQL/SQLite, Alpine.js 3.14.1 (komponen bersama pola `taField`), PHPUnit.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-02-preventive-schedule-manual-edit-design.md`.
- Migrasi baru WAJIB didaftarkan `--path=` baru di `.github/workflows/deploy.yml` — PR yang sama.
- PDF (`preventive-schedule-pdf.blade.php`, `schedulePdf()` di kedua controller) **NOL berubah** — tetap filter `whereNotNull('tanggal_rencana')`, tetap nol tau soal locked/nggak.
- Kapasitas mingguan **NOL ditegakin** buat edit manual (item locked bisa numpuk lebih dari kapasitas nominal di minggu yang sama — disengaja).
- Otorisasi endpoint baru: route middleware `role:admin,staff_maintenance,manager` (pola sama grup write existing) + `abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403)` di controller (exclude `manager`, pola sama persis `scheduleGenerate()`).
- 15 test lama `PreventiveScheduleServiceTest` (12 fase 1 + 3 fase 2) WAJIB tetap lolos tanpa diubah — kolom baru default `false`/`null`, jadi `generate()` yang diubah harus no-op kalau nol ada item locked.
- Semua kolom baru dipakai `Schema::hasColumn` guard di `generate()` kalau ada resiko dipakai sebelum migrate jalan (pola sama fix `d9e1164` fase 2).
- `Schema::hasColumn('preventive_schedule_items', 'is_manual_override')` — guard yang sama juga WAJIB di controller endpoint baru (kalau kolom belum ada, endpoint balikin 503 dulu daripada SQL error mentah — window rsync→migrate).

---

### Task 1: Migrasi kolom lock + model

**Files:**
- Create: `database/migrations/2026_09_03_000001_add_manual_override_columns_to_preventive_schedule_items_table.php`
- Modify: `app/Models/PreventiveScheduleItem.php`
- Modify: `.github/workflows/deploy.yml` (baris `--path=` baru)
- Test: `tests/Feature/PreventiveScheduleManualOverrideMigrationTest.php`

**Interfaces:**
- Produces: kolom `preventive_schedule_items.is_manual_override` (bool, default false), `.overridden_by` (nullable FK `users.id`), `.overridden_at` (nullable timestamp) — dipakai Task 2 & 3.
- Produces: `PreventiveScheduleItem::$fillable` + `is_manual_override`, `overridden_by`, `overridden_at`; `$casts` + `'is_manual_override' => 'boolean'`, `'overridden_at' => 'datetime'`; relasi `overriddenBy(): BelongsTo` → `User`; trait `LogsActivity` (pola sama `MasterLosstime`).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreventiveScheduleManualOverrideMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_lock_manual_override_ada(): void
    {
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'is_manual_override'));
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'overridden_by'));
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'overridden_at'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PreventiveScheduleManualOverrideMigrationTest`
Expected: FAIL — kolom belum ada.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->boolean('is_manual_override')->default(false)->after('backlog_since');
            $table->foreignId('overridden_by')->nullable()->after('is_manual_override')->constrained('users');
            $table->timestamp('overridden_at')->nullable()->after('overridden_by');
        });
    }

    public function down(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['is_manual_override', 'overridden_at']);
        });
    }
};
```

- [ ] **Step 4: Update model**

Replace `app/Models/PreventiveScheduleItem.php` isinya:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PreventiveScheduleItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'schedule_id', 'id_mesin', 'id_part_proses',
        'stroke_snapshot', 'pct_snapshot', 'threshold_snapshot',
        'tanggal_rencana', 'minggu_ke', 'backlog_since',
        'is_manual_override', 'overridden_by', 'overridden_at',
    ];

    protected $casts = [
        'tanggal_rencana' => 'date:Y-m-d',
        'pct_snapshot' => 'float',
        'backlog_since' => 'date:Y-m-d',
        'is_manual_override' => 'boolean',
        'overridden_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tanggal_rencana', 'minggu_ke', 'is_manual_override', 'backlog_since'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('preventive_schedule_item');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PreventiveSchedule::class, 'schedule_id');
    }

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PreventiveScheduleManualOverrideMigrationTest`
Expected: PASS.

- [ ] **Step 6: Daftarkan migrasi di deploy.yml**

Edit `.github/workflows/deploy.yml`, tambah baris setelah `2026_09_02_000001_add_backlog_columns_to_preventive_schedule_items_table.php"` (pindah tanda kutip penutup):

```
              --path=database/migrations/2026_09_02_000001_add_backlog_columns_to_preventive_schedule_items_table.php \
              --path=database/migrations/2026_09_03_000001_add_manual_override_columns_to_preventive_schedule_items_table.php"
```

- [ ] **Step 7: Verifikasi gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: lolos.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_03_000001_add_manual_override_columns_to_preventive_schedule_items_table.php \
  app/Models/PreventiveScheduleItem.php .github/workflows/deploy.yml \
  tests/Feature/PreventiveScheduleManualOverrideMigrationTest.php
git commit -m "feat(preventive-schedule): kolom lock manual override (is_manual_override/overridden_by/overridden_at)"
```

---

### Task 2: `generate()` — skip item locked, exclude dari eligible

**Files:**
- Modify: `app/Services/PreventiveScheduleService.php`
- Test: `tests/Unit/PreventiveScheduleServiceTest.php` (tambah di akhir, JANGAN ubah 15 test yang ada)

**Interfaces:**
- Consumes: `PreventiveScheduleItem::is_manual_override` (Task 1).
- Produces: `PreventiveScheduleService::HARI_DIIZINKAN` const jadi diakses via method publik baru `hariDiizinkan(string $jenis): array` (dipakai Task 3 buat validasi tanggal endpoint) — const-nya sendiri TETAP `private`, cuma nambah 1 method publik kecil.

- [ ] **Step 1: Write the failing tests**

Tambah di akhir `class PreventiveScheduleServiceTest` (setelah test terakhir, sebelum `}` penutup class):

```php
    public function test_item_locked_manual_selamat_dari_regenerate(): void
    {
        $mesin = $this->mesinDenganStroke('LK1', 305000);
        app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $item = \App\Models\PreventiveScheduleItem::where('id_mesin', $mesin->id)->first();
        $item->update([
            'tanggal_rencana' => '2026-08-04',
            'minggu_ke' => 1,
            'is_manual_override' => true,
            'backlog_since' => null,
        ]);

        Carbon::setTestNow('2026-08-25');
        $schedule2 = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $itemSetelah = $schedule2->items->where('id_mesin', $mesin->id)->first();
        $this->assertNotNull($itemSetelah, 'item locked harus tetap ada setelah regenerate');
        $this->assertSame('2026-08-04', $itemSetelah->tanggal_rencana->toDateString(), 'tanggal locked nol boleh ketimpa algoritma');
        $this->assertTrue($itemSetelah->is_manual_override);
        $this->assertSame(1, $schedule2->items->where('id_mesin', $mesin->id)->count(), 'nol boleh dobel — 1 locked + 1 hasil algoritma buat target sama');
    }

    public function test_item_locked_nol_masuk_backlog_walau_masih_eligible(): void
    {
        $mesin = $this->mesinDenganStroke('LK2', 305000);
        app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $item = \App\Models\PreventiveScheduleItem::where('id_mesin', $mesin->id)->first();
        $item->update([
            'tanggal_rencana' => '2026-08-04',
            'minggu_ke' => 1,
            'is_manual_override' => true,
            'backlog_since' => null,
        ]);

        $schedule2 = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $itemSetelah = $schedule2->items->where('id_mesin', $mesin->id)->first();

        $this->assertNotNull($itemSetelah->tanggal_rencana, 'item locked nol boleh jadi backlog — dia udah punya tanggal manual');
        $this->assertNull($itemSetelah->backlog_since);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PreventiveScheduleServiceTest`
Expected: 2 test baru FAIL (`generate()` masih hapus SEMUA item termasuk locked — assertion "item locked harus tetap ada" gagal). 15 test lama tetap PASS.

- [ ] **Step 3: Implement**

Di `app/Services/PreventiveScheduleService.php`, tambah method publik baru setelah `private const HARI_WINDOW_LAJU`:

```php
    /** @return array<int> Hari (isoWeekday) yang diizinkan buat tanggal PLAN, per jenis. */
    public function hariDiizinkan(string $jenis): array
    {
        return self::HARI_DIIZINKAN[$jenis] ?? [];
    }
```

Ubah baris delete di `generate()` — dari:

```php
            $schedule->items()->delete();
```

jadi:

```php
            $schedule->items()->where('is_manual_override', false)->delete();
```

Tambah exclude target locked dari `$eligible`, TEPAT setelah baris `$eligible = $this->eligibleItems($jenis, $bulan);`:

```php
            $eligible = $this->eligibleItems($jenis, $bulan);
            $idTargetLocked = $schedule->items()
                ->where('is_manual_override', true)
                ->pluck($jenis === 'mesin' ? 'id_mesin' : 'id_part_proses')
                ->all();
            if ($idTargetLocked !== []) {
                $kolomIdEligible = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';
                $eligible = $eligible->reject(fn ($row) => in_array($row[$kolomIdEligible], $idTargetLocked, true))->values();
            }
```

Catatan: `$schedule->items()->where('is_manual_override', true)->pluck(...)` query INI TERJADI SETELAH `$schedule->items()->where('is_manual_override', false)->delete()` di atasnya — item locked belum kehapus (filter delete cuma nyasar `false`), jadi query pluck ini tetap dapat baris locked yang masih ada. Urutan di file: delete dulu (baris `$schedule->items()->where(...)->delete();`), baru compute `$eligible` + filter locked setelahnya — urutan ini SUDAH BENAR di file existing (delete sebelum eligibleItems), jangan diubah urutannya.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PreventiveScheduleServiceTest`
Expected: 17 test (15 lama + 2 baru) PASS.

- [ ] **Step 5: Full regression**

Run: `php artisan test`
Expected: semua lolos, nol regresi.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PreventiveScheduleService.php tests/Unit/PreventiveScheduleServiceTest.php
git commit -m "feat(preventive-schedule): generate() skip item locked manual, exclude dari eligible biar nol dobel"
```

---

### Task 3: Endpoint PATCH + reset (mesin & dies)

**Files:**
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Maintenance/PreventiveScheduleManualEditTest.php`

**Interfaces:**
- Consumes: `PreventiveScheduleService::hariDiizinkan(string $jenis): array` (Task 2), `PreventiveScheduleItem` kolom lock (Task 1), `KalenderKerjaService::hariKerja(Carbon): bool` (existing).
- Produces: route `maintenance.control-stroke-mesin.schedule-item.update` (PATCH), `maintenance.control-stroke-mesin.schedule-item.reset` (POST), `maintenance.control-stroke-dies.schedule-item.update`, `maintenance.control-stroke-dies.schedule-item.reset` — dipakai Task 5 (frontend).

- [ ] **Step 1: Write the failing tests**

Buat `tests/Feature/Maintenance/PreventiveScheduleManualEditTest.php`:

```php
<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveScheduleItem;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleManualEditTest extends TestCase
{
    use RefreshDatabase;

    private function setupMesinEligible(string $kode, int $stroke): MasterMesin
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Op', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => $kode, 'nama_mesin' => "Press {$kode}", 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => "P-{$kode}", 'part_name' => "Part {$kode}", 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => $stroke, 'actual_cycle_time' => 10, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        return $mesin;
    }

    public function test_admin_bisa_patch_tanggal_valid(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME1', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertOk();

        $item->refresh();
        $this->assertSame('2026-08-11', $item->tanggal_rencana->toDateString());
        $this->assertTrue($item->is_manual_override);
        $this->assertSame($admin->id, $item->overridden_by);
        $this->assertNotNull($item->overridden_at);
    }

    public function test_patch_tanggal_hari_libur_ditolak_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME2', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        // 2026-08-01 = Sabtu (bukan hari kerja).
        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-01'])
            ->assertStatus(422);
    }

    public function test_patch_tanggal_jumat_ditolak_buat_mesin_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME3', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        // 2026-08-07 = Jumat, mesin cuma Senin-Kamis.
        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-07'])
            ->assertStatus(422);
    }

    public function test_patch_bulan_lain_ditolak_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME4', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-09-03'])
            ->assertStatus(422);
    }

    public function test_patch_item_dies_lewat_route_mesin_ditolak_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'PD1', 'part_name' => 'Part D1', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        $itemDies = PreventiveScheduleItem::create([
            'schedule_id' => \App\Models\PreventiveSchedule::create(['bulan' => '2026-08-01', 'jenis' => 'dies', 'nomor_dokumen' => 'X'])->id,
            'id_part_proses' => $proses->id, 'stroke_snapshot' => 1, 'pct_snapshot' => 100, 'threshold_snapshot' => 1,
            'tanggal_rencana' => '2026-08-04', 'minggu_ke' => 1,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $itemDies), ['tanggal_rencana' => '2026-08-05'])
            ->assertStatus(422);
    }

    public function test_patch_baris_backlog_keluar_dari_backlog(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $item = PreventiveScheduleItem::create([
            'schedule_id' => \App\Models\PreventiveSchedule::create(['bulan' => '2026-08-01', 'jenis' => 'mesin', 'nomor_dokumen' => 'X'])->id,
            'id_mesin' => $this->setupMesinEligible('ME5', 100)->id,
            'stroke_snapshot' => 1, 'pct_snapshot' => 100, 'threshold_snapshot' => 1,
            'tanggal_rencana' => null, 'minggu_ke' => null, 'backlog_since' => '2026-08-01',
        ]);

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertOk();

        $item->refresh();
        $this->assertSame('2026-08-11', $item->tanggal_rencana->toDateString());
        $this->assertNull($item->backlog_since, 'assign tanggal manual ke backlog harus keluarin dia dari backlog');
        $this->assertTrue($item->is_manual_override);
    }

    public function test_reset_balik_ke_backlog(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $item = PreventiveScheduleItem::create([
            'schedule_id' => \App\Models\PreventiveSchedule::create(['bulan' => '2026-08-01', 'jenis' => 'mesin', 'nomor_dokumen' => 'X'])->id,
            'id_mesin' => $this->setupMesinEligible('ME6', 100)->id,
            'stroke_snapshot' => 1, 'pct_snapshot' => 100, 'threshold_snapshot' => 1,
            'tanggal_rencana' => '2026-08-04', 'minggu_ke' => 1,
            'is_manual_override' => true, 'overridden_by' => $admin->id, 'overridden_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('maintenance.control-stroke-mesin.schedule-item.reset', $item))
            ->assertOk();

        $item->refresh();
        $this->assertFalse($item->is_manual_override);
        $this->assertNull($item->tanggal_rencana);
        $this->assertNull($item->minggu_ke);
        $this->assertNull($item->overridden_by);
        $this->assertSame('2026-08-20', $item->backlog_since->toDateString());
    }

    public function test_guest_tidak_boleh_patch_403(): void
    {
        Carbon::setTestNow('2026-08-20');
        $guest = User::factory()->create(['role' => 'guest']);
        $mesin = $this->setupMesinEligible('ME7', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($guest)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertStatus(403);
    }

    public function test_staff_produksi_tidak_boleh_patch_403(): void
    {
        Carbon::setTestNow('2026-08-20');
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $mesin = $this->setupMesinEligible('ME8', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($staff)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertStatus(403);
    }

    public function test_activitylog_kecatet_saat_patch(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME9', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PreventiveScheduleItem::class,
            'subject_id' => $item->id,
            'causer_id' => $admin->id,
            'log_name' => 'preventive_schedule_item',
        ]);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PreventiveScheduleManualEditTest`
Expected: FAIL — semua (route belum ada, `RouteNotFoundException`).

- [ ] **Step 3: Tambah routes**

Di `routes/web.php`, di dalam grup write existing (`role:admin,staff_maintenance,manager`, prefix `maintenance`, yang udah punya `schedule-generate` — lihat baris sebelum `Route::post('availability/control-stroke-mesin/schedule-generate', ...)`), tambah TEPAT setelah baris `schedule-generate` mesin & sebelum baris dies:

```php
        Route::post('availability/control-stroke-mesin/schedule-generate', [ControlStrokeMesinController::class, 'scheduleGenerate'])->name('control-stroke-mesin.schedule-generate');
        Route::patch('availability/control-stroke-mesin/schedule-item/{item}', [ControlStrokeMesinController::class, 'updateScheduleItem'])->name('control-stroke-mesin.schedule-item.update');
        Route::post('availability/control-stroke-mesin/schedule-item/{item}/reset', [ControlStrokeMesinController::class, 'resetScheduleItem'])->name('control-stroke-mesin.schedule-item.reset');

        Route::post('availability/control-stroke-dies/schedule-generate', [ControlStrokeDiesController::class, 'scheduleGenerate'])->name('control-stroke-dies.schedule-generate');
        Route::patch('availability/control-stroke-dies/schedule-item/{item}', [ControlStrokeDiesController::class, 'updateScheduleItem'])->name('control-stroke-dies.schedule-item.update');
        Route::post('availability/control-stroke-dies/schedule-item/{item}/reset', [ControlStrokeDiesController::class, 'resetScheduleItem'])->name('control-stroke-dies.schedule-item.reset');
```

`{item}` route-model-binds ke `PreventiveScheduleItem` (default Laravel resolver — nama parameter `item` cocok konvensi implicit binding, nol perlu binding kustom).

- [ ] **Step 4: Implement `updateScheduleItem`/`resetScheduleItem` — mesin**

Tambah ke `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`, setelah method `scheduleGenerate()`, sebelum penutup class `}`. Tambah `use App\Models\PreventiveScheduleItem;`, `use App\Services\KalenderKerjaService;`, `use Illuminate\Support\Carbon;` di atas kalau belum ada (cek dulu — controller ini belum import `Carbon`/`KalenderKerjaService`/`PreventiveScheduleItem`).

```php
    public function updateScheduleItem(PreventiveScheduleItem $item, Request $request, PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'mesin', 422, 'Item ini bukan jadwal mesin.');

        $validated = $request->validate(['tanggal_rencana' => 'required|date']);
        $tanggal = Carbon::parse($validated['tanggal_rencana']);
        $bulanSchedule = Carbon::parse($item->schedule->bulan);

        if (! $tanggal->isSameMonth($bulanSchedule)) {
            return response()->json(['message' => 'Tanggal harus dalam bulan yang sama dengan jadwal ini.'], 422);
        }
        if (! KalenderKerjaService::hariKerja($tanggal)) {
            return response()->json(['message' => 'Tanggal itu hari libur / bukan hari kerja.'], 422);
        }
        if (! in_array($tanggal->isoWeekday(), $preventiveSvc->hariDiizinkan('mesin'), true)) {
            return response()->json(['message' => 'Tanggal itu di luar hari yang diizinkan untuk mesin (Senin-Kamis).'], 422);
        }

        $mingguPertamaBulan = $bulanSchedule->copy()->startOfWeek(Carbon::MONDAY);
        $mingguKe = (int) $mingguPertamaBulan->diffInWeeks($tanggal->copy()->startOfWeek(Carbon::MONDAY)) + 1;

        $item->update([
            'tanggal_rencana' => $tanggal->toDateString(),
            'minggu_ke' => $mingguKe,
            'backlog_since' => null,
            'is_manual_override' => true,
            'overridden_by' => auth()->id(),
            'overridden_at' => now(),
        ]);

        return response()->json(['message' => 'Tanggal berhasil diubah.', 'tanggal_rencana' => $item->tanggal_rencana->toDateString()]);
    }

    public function resetScheduleItem(PreventiveScheduleItem $item)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'mesin', 422, 'Item ini bukan jadwal mesin.');

        $item->update([
            'is_manual_override' => false,
            'tanggal_rencana' => null,
            'minggu_ke' => null,
            'overridden_by' => null,
            'overridden_at' => null,
            'backlog_since' => now()->timezone('Asia/Jakarta')->toDateString(),
        ]);

        return response()->json(['message' => 'Item direset ke otomatis, masuk backlog sampai regenerate berikutnya.']);
    }
```

- [ ] **Step 5: Implement `updateScheduleItem`/`resetScheduleItem` — dies**

Tambah persis sama ke `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`, GANTI SEMUA validasi `'mesin'` jadi `'dies'`:

```php
    public function updateScheduleItem(PreventiveScheduleItem $item, Request $request, PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'dies', 422, 'Item ini bukan jadwal dies.');

        $validated = $request->validate(['tanggal_rencana' => 'required|date']);
        $tanggal = Carbon::parse($validated['tanggal_rencana']);
        $bulanSchedule = Carbon::parse($item->schedule->bulan);

        if (! $tanggal->isSameMonth($bulanSchedule)) {
            return response()->json(['message' => 'Tanggal harus dalam bulan yang sama dengan jadwal ini.'], 422);
        }
        if (! KalenderKerjaService::hariKerja($tanggal)) {
            return response()->json(['message' => 'Tanggal itu hari libur / bukan hari kerja.'], 422);
        }
        if (! in_array($tanggal->isoWeekday(), $preventiveSvc->hariDiizinkan('dies'), true)) {
            return response()->json(['message' => 'Tanggal itu di luar hari yang diizinkan untuk dies (Senin-Jumat).'], 422);
        }

        $mingguPertamaBulan = $bulanSchedule->copy()->startOfWeek(Carbon::MONDAY);
        $mingguKe = (int) $mingguPertamaBulan->diffInWeeks($tanggal->copy()->startOfWeek(Carbon::MONDAY)) + 1;

        $item->update([
            'tanggal_rencana' => $tanggal->toDateString(),
            'minggu_ke' => $mingguKe,
            'backlog_since' => null,
            'is_manual_override' => true,
            'overridden_by' => auth()->id(),
            'overridden_at' => now(),
        ]);

        return response()->json(['message' => 'Tanggal berhasil diubah.', 'tanggal_rencana' => $item->tanggal_rencana->toDateString()]);
    }

    public function resetScheduleItem(PreventiveScheduleItem $item)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(\Illuminate\Support\Facades\Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'dies', 422, 'Item ini bukan jadwal dies.');

        $item->update([
            'is_manual_override' => false,
            'tanggal_rencana' => null,
            'minggu_ke' => null,
            'overridden_by' => null,
            'overridden_at' => null,
            'backlog_since' => now()->timezone('Asia/Jakarta')->toDateString(),
        ]);

        return response()->json(['message' => 'Item direset ke otomatis, masuk backlog sampai regenerate berikutnya.']);
    }
```

Tambah `use App\Models\PreventiveScheduleItem;`, `use App\Services\KalenderKerjaService;`, `use App\Services\PreventiveScheduleService;` (cek dulu, `PreventiveScheduleService` mungkin belum di-import di controller dies — `scheduleGenerate()` cuma type-hint parameter, PHP nggak butuh `use` kalau dipakai full-qualified, tapi existing code kemungkinan udah import lewat parameter type-hint constructor injection existing method — pastikan `use App\Services\PreventiveScheduleService;` ada di atas file), `use Illuminate\Support\Carbon;`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=PreventiveScheduleManualEditTest`
Expected: semua 10 test PASS.

- [ ] **Step 7: Full regression**

Run: `php artisan test`
Expected: semua lolos.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeMesinController.php \
  app/Http/Controllers/Maintenance/ControlStrokeDiesController.php \
  routes/web.php tests/Feature/Maintenance/PreventiveScheduleManualEditTest.php
git commit -m "feat(preventive-schedule): endpoint PATCH/reset tanggal PLAN manual (mesin & dies)"
```

---

### Task 4: Grid kalender HTML di halaman Control Stroke

**Files:**
- Create: `resources/views/maintenance/partials/preventive-schedule-grid.blade.php`
- Modify: `resources/views/maintenance/control-stroke-mesin.blade.php`
- Modify: `resources/views/maintenance/control-stroke-dies.blade.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php` (method `index()`)
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php` (method `index()`)
- Test: `tests/Feature/Maintenance/PreventiveScheduleGridViewTest.php`

**Interfaces:**
- Consumes: `PreventiveSchedule`/`PreventiveScheduleItem` (Task 1-2), `KalenderKerjaService::hariKerja()`.
- Produces: partial `maintenance.partials.preventive-schedule-grid` menerima `$jenis`, `$schedule` (nullable), `$bulan` (Carbon) — dipakai Task 5 buat nempel Alpine.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Maintenance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleGridViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_control_stroke_mesin_tampilkan_pesan_belum_ada_jadwal(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin'))
            ->assertOk()
            ->assertSee('Belum ada jadwal');

        Carbon::setTestNow();
    }

    public function test_halaman_control_stroke_dies_tampilkan_pesan_belum_ada_jadwal(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-dies'))
            ->assertOk()
            ->assertSee('Belum ada jadwal');

        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PreventiveScheduleGridViewTest`
Expected: FAIL — halaman belum nampilin pesan itu sama sekali (partial belum ada).

- [ ] **Step 3: Buat partial grid**

Buat `resources/views/maintenance/partials/preventive-schedule-grid.blade.php`:

```php
@php
    $jumlahHari = $bulan->daysInMonth;
    $namaBulan = mb_strtoupper($bulan->locale('id')->translatedFormat('F Y'));
    $labelKode = $jenis === 'mesin' ? 'ID MESIN' : 'ID DIES';
    $labelNama = $jenis === 'mesin' ? 'NAMA MESIN' : 'NAMA PART/DIES';
@endphp

<div class="erp-panel mt-3">
    <div class="erp-panel-header d-flex justify-content-between align-items-center">
        <span>Jadwal Preventive Bulan Ini — {{ $namaBulan }}</span>
        @if ($schedule)
            <span class="text-muted small">{{ $schedule->nomor_dokumen }}</span>
        @endif
    </div>
    <div class="erp-panel-body" style="padding:0">
        @if (! $schedule)
            <p class="text-muted p-3 mb-0">Belum ada jadwal untuk {{ $namaBulan }}. Klik "Generate Ulang" di toolbar atas.</p>
        @else
            <div style="overflow-x:auto">
                <table class="erp-table psg-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>{{ $labelNama }}</th>
                            <th>{{ $labelKode }}</th>
                            @if ($jenis === 'dies')
                                <th>Proses</th>
                            @endif
                            <th>PLAN</th>
                            @for ($d = 1; $d <= $jumlahHari; $d++)
                                @php $tgl = $bulan->copy()->day($d); @endphp
                                <th class="{{ \App\Services\KalenderKerjaService::hariKerja($tgl) ? '' : 'psg-libur' }}">{{ $d }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedule->items as $i => $item)
                            @php
                                $target = $jenis === 'mesin' ? $item->mesin : $item->partProses;
                                $kode = $jenis === 'mesin' ? $target?->id_mesin : $target?->part?->part_no;
                                $nama = $jenis === 'mesin' ? $target?->nama_mesin : $target?->part?->part_name;
                            @endphp
                            <tr data-item-id="{{ $item->id }}" class="{{ $item->is_manual_override ? 'psg-locked' : '' }}">
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $nama }}</td>
                                <td>{{ $kode }}</td>
                                @if ($jenis === 'dies')
                                    <td>{{ $target?->id_proses }} &ndash; {{ $target?->nama_proses }}</td>
                                @endif
                                <td>PLAN
                                    @if ($item->is_manual_override)
                                        <i class="bi bi-lock-fill text-warning ms-1" title="Dikunci manual"></i>
                                    @endif
                                </td>
                                @for ($d = 1; $d <= $jumlahHari; $d++)
                                    @php
                                        $tgl = $bulan->copy()->day($d);
                                        $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl);
                                        $isPlan = $item->tanggal_rencana && $item->tanggal_rencana->day === $d;
                                    @endphp
                                    <td class="psg-cell {{ $isPlan ? 'psg-plan' : ($isLibur ? 'psg-libur' : '') }}"
                                        data-item-id="{{ $item->id }}" data-day="{{ $d }}"></td>
                                @endfor
                            </tr>
                        @endforeach
                        @if ($schedule->items->isEmpty())
                            <tr><td colspan="99" class="text-center text-muted py-3">Nol item eligible bulan ini.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
```

- [ ] **Step 4: Extend controller `index()` — mesin**

Ubah `index()` di `ControlStrokeMesinController.php`:

```php
    public function index()
    {
        $bulan = now()->timezone('Asia/Jakarta')->startOfMonth();
        $schedule = \App\Models\PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'mesin')
            ->with('items.mesin')
            ->first();

        // Opsi filter (mesin/tipe/line) dibangun di klien dari data yang dimuat — lihat
        // initFilters() di view. Controller cukup mengirim threshold.
        return view('maintenance.control-stroke-mesin', [
            'threshold' => MaintenanceDashboardService::STROKE_THRESHOLD,
            'jadwalSchedule' => $schedule,
            'jadwalBulan' => $bulan,
        ]);
    }
```

- [ ] **Step 5: Extend controller `index()` — dies**

Ubah `index()` di `ControlStrokeDiesController.php`:

```php
    public function index()
    {
        $bulan = now()->timezone('Asia/Jakarta')->startOfMonth();
        $schedule = \App\Models\PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'dies')
            ->with('items.partProses.part')
            ->first();

        // Opsi filter (part/customer/proses) dibangun di klien dari data yang dimuat — lihat
        // initFilters() di view. Controller cukup mengirim threshold.
        return view('maintenance.control-stroke-dies', [
            'threshold' => MaintenanceDiesDashboardService::STROKE_THRESHOLD,
            'jadwalSchedule' => $schedule,
            'jadwalBulan' => $bulan,
        ]);
    }
```

Catatan: query ini SENGAJA nol filter `whereNotNull('tanggal_rencana')` (beda dari `schedulePdf()`) — baris backlog IKUT tampil di grid (spec, biar bisa langsung di-assign tanggal).

- [ ] **Step 6: Include partial di kedua blade**

Di `control-stroke-mesin.blade.php`, tambah SETELAH `</div>{{-- /erp-panel --}}` yang nutup "Detail per Mesin" (baris `154` di file existing sebelum plan ini, cari `</div>` yang nutup panel "Detail per Mesin"), SEBELUM `</div>{{-- /erp-scroll --}}`:

```php
@include('maintenance.partials.preventive-schedule-grid', ['jenis' => 'mesin', 'schedule' => $jadwalSchedule, 'bulan' => $jadwalBulan])
```

Sama persis di `control-stroke-dies.blade.php` (cari struktur `erp-panel`/`erp-scroll` yang setara — file dies punya struktur sama dengan mesin), ganti `'jenis' => 'mesin'` jadi `'jenis' => 'dies'`.

Tambah CSS kecil di `<style>` block masing-masing blade (di dalam `@section('content')`, dekat style existing):

```css
.psg-table th, .psg-table td { text-align: center; white-space: nowrap; font-size: 11px; }
.psg-table td:nth-child(2) { text-align: left; white-space: normal; min-width: 160px; }
.psg-libur { background: #fca5a5; }
.psg-plan { background: #facc15; cursor: pointer; }
.psg-cell:not(.psg-libur) { cursor: pointer; }
.psg-cell:hover:not(.psg-libur) { outline: 2px solid #111827; outline-offset: -2px; }
.psg-locked td { background: #fef9c3; }
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=PreventiveScheduleGridViewTest`
Expected: PASS.

- [ ] **Step 8: Full regression**

Run: `php artisan test`
Expected: semua lolos (termasuk test `ControlStrokeMesinController`/`Dies` existing yang mungkin nge-tes `index()` — cek nggak ada assertion ketat soal view data yang sekarang nambah key baru).

- [ ] **Step 9: Commit**

```bash
git add resources/views/maintenance/partials/preventive-schedule-grid.blade.php \
  resources/views/maintenance/control-stroke-mesin.blade.php \
  resources/views/maintenance/control-stroke-dies.blade.php \
  app/Http/Controllers/Maintenance/ControlStrokeMesinController.php \
  app/Http/Controllers/Maintenance/ControlStrokeDiesController.php \
  tests/Feature/Maintenance/PreventiveScheduleGridViewTest.php
git commit -m "feat(preventive-schedule): grid kalender HTML jadwal di halaman Control Stroke (mesin & dies)"
```

---

### Task 5: Interaksi klik-edit (Alpine component) + verifikasi visual

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (definisi komponen `scheduleItemEdit`)
- Modify: `resources/views/maintenance/partials/preventive-schedule-grid.blade.php` (pasang `x-data`)
- Modify: `tests/Feature/Layout/KomponenBersamaTest.php` (tambah `scheduleItemEdit` ke daftar guarded)
- Test: manual browser verification (nol otomatis buat interaksi klik — dompdf/Blade nol bisa disimulasikan klik di PHPUnit)

**Interfaces:**
- Consumes: endpoint Task 3 (`schedule-item.update`/`.reset` route), data `hariDiizinkan` per jenis dari route/controller (Task 3's validasi server-side jadi rujukan — client cukup filter kasar, server tetap final gate).

- [ ] **Step 1: Tambah komponen ke daftar guarded**

Di `tests/Feature/Layout/KomponenBersamaTest.php`, tambah ke array `KOMPONEN`:

```php
        'function scheduleItemEdit(',
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=KomponenBersamaTest`
Expected: FAIL — `scheduleItemEdit(` belum ada di `layouts/app.blade.php`.

- [ ] **Step 3: Definisikan komponen di layout**

Di `resources/views/layouts/app.blade.php`, tambah SETELAH definisi `function taField(...)` (cari blok itu, tambah setelah closing `}` fungsi `taField`), function baru:

```js
/* ═══════════════════════════════════════════════════════════════════
   scheduleItemEdit() — klik cell PLAN di grid kalender preventive
   (Control Stroke Mesin/Dies), pilih tanggal baru dari daftar tanggal
   valid, PATCH ke server. Server tetap validasi ulang (defense-in-depth)
   — daftar tanggal di sini cuma buat UX (disable pilihan yang jelas
   invalid), bukan satu-satunya gerbang.
══════════════════════════════════════════════════════════════════ */
function scheduleItemEdit(itemId, tanggalAwal, isLocked, urlPatch, urlReset, validDates) {
    return {
        itemId, tanggal: tanggalAwal, isLocked, validDates,
        editing: false, saving: false, error: null,

        open() { this.editing = true; this.error = null; },
        cancel() { this.editing = false; this.error = null; },

        async simpan(tanggalBaru) {
            if (! tanggalBaru) return;
            this.saving = true; this.error = null;
            try {
                const res = await fetch(urlPatch, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ tanggal_rencana: tanggalBaru }),
                });
                const data = await res.json();
                if (! res.ok) {
                    this.error = data.message || 'Gagal simpan.';
                    this.saving = false;
                    return;
                }
                this.tanggal = data.tanggal_rencana;
                this.isLocked = true;
                this.editing = false;
                this.saving = false;
                window.location.reload();
            } catch (e) {
                this.error = 'Gagal koneksi ke server.';
                this.saving = false;
            }
        },

        async reset() {
            if (! confirm('Reset item ini ke otomatis? Tanggal manual akan hilang, item masuk backlog sampai regenerate berikutnya.')) return;
            this.saving = true;
            try {
                const res = await fetch(urlReset, {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                if (res.ok) {
                    window.location.reload();
                } else {
                    this.saving = false;
                    this.error = 'Gagal reset.';
                }
            } catch (e) {
                this.saving = false;
                this.error = 'Gagal koneksi ke server.';
            }
        },
    };
}
```

Catatan: `window.location.reload()` dipakai daripada update DOM in-place manual — grid punya banyak baris×kolom saling terkait (kapasitas nol ditegakin tapi urutan baris/nomor tetap harus konsisten kalau ada perubahan `minggu_ke`), reload penuh JAUH lebih simpel dan aman daripada re-render partial grid via JS. Trade-off sengaja: 1 request tambahan (GET halaman) demi konsistensi data, bukan micro-optimize UX kelip-kelip.

- [ ] **Step 4: Pasang `x-data` di partial grid**

Ubah `resources/views/maintenance/partials/preventive-schedule-grid.blade.php` — tambah `@php` di atas untuk hitung tanggal valid SEKALI per grid (bukan per item, sama buat semua baris):

```php
@php
    $tanggalValid = collect(range(1, $jumlahHari))
        ->map(fn ($d) => $bulan->copy()->day($d))
        ->filter(fn ($tgl) => \App\Services\KalenderKerjaService::hariKerja($tgl) && in_array($tgl->isoWeekday(), app(\App\Services\PreventiveScheduleService::class)->hariDiizinkan($jenis), true))
        ->map(fn ($tgl) => $tgl->toDateString())
        ->values();
@endphp
```

Taruh `@php` ini TEPAT SETELAH `@php` block yang udah ada di awal partial (gabung jadi satu block, atau block kedua — dua-duanya valid Blade).

Ganti baris `<tr data-item-id="{{ $item->id }}" class="...">` jadi:

```php
                            <tr x-data="scheduleItemEdit(
                                    {{ $item->id }},
                                    {{ $item->tanggal_rencana ? \Illuminate\Support\Js::from($item->tanggal_rencana->toDateString()) : 'null' }},
                                    {{ $item->is_manual_override ? 'true' : 'false' }},
                                    {{ \Illuminate\Support\Js::from(route("maintenance.control-stroke-{$jenis}.schedule-item.update", $item)) }},
                                    {{ \Illuminate\Support\Js::from(route("maintenance.control-stroke-{$jenis}.schedule-item.reset", $item)) }},
                                    {{ \Illuminate\Support\Js::from($tanggalValid) }}
                                )"
                                class="{{ $item->is_manual_override ? 'psg-locked' : '' }}">
```

Ganti kolom PLAN (`<td>PLAN ... </td>`) jadi:

```php
                                <td>
                                    PLAN
                                    <template x-if="isLocked">
                                        <i class="bi bi-lock-fill text-warning ms-1" title="Dikunci manual" @click="reset()" style="cursor:pointer"></i>
                                    </template>
                                </td>
```

Ganti loop cell tanggal (`@for ($d = 1; ...) ... @endfor` yang render `<td class="psg-cell ...">`) jadi:

```php
                                @for ($d = 1; $d <= $jumlahHari; $d++)
                                    @php
                                        $tgl = $bulan->copy()->day($d);
                                        $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl);
                                        $tglStr = $tgl->toDateString();
                                    @endphp
                                    <td class="psg-cell {{ $isLibur ? 'psg-libur' : '' }}"
                                        :class="tanggal === '{{ $tglStr }}' ? 'psg-plan' : ''"
                                        @if (! $isLibur) @click="open()" @endif>
                                        <template x-if="editing && tanggal !== '{{ $tglStr }}' && validDates.includes('{{ $tglStr }}')">
                                            <button type="button" class="btn btn-sm btn-outline-dark py-0 px-1" style="font-size:9px" @click.stop="simpan('{{ $tglStr }}')" :disabled="saving">→</button>
                                        </template>
                                    </td>
                                @endfor
```

Tambah baris error kecil setelah `</tr>` per item (baru, buat nampilin `error` kalau ada) — tambah SETELAH penutup `</tr>` di dalam `@foreach`:

```php
                            <tr x-show="error" x-cloak>
                                <td colspan="99" class="text-danger small py-1" x-text="error"></td>
                            </tr>
```

- [ ] **Step 5: Run test guard**

Run: `php artisan test --filter=KomponenBersamaTest`
Expected: PASS.

- [ ] **Step 6: Full regression**

Run: `php artisan test`
Expected: semua lolos.

- [ ] **Step 7: Verifikasi visual manual (browser)**

Jalanin dev server (`php artisan serve` atau setara), login admin, buka Control Stroke Mesin — konfirmasi:
- Grid kalender muncul di bawah tabel status stroke.
- Klik cell tanggal kosong (bukan hari libur) di baris item terjadwal → tombol "→" kecil muncul di cell-cell tanggal valid lain.
- Klik salah satu → reload, cell PLAN kuning pindah ke tanggal itu, ikon kunci muncul.
- Klik ikon kunci → confirm dialog → reload, item balik nol tanggal (backlog).
- Baris backlog (kalau ada) — cell klik-able, assign tanggal jalan sama.
- Login `staff_produksi` — cell nol bisa diklik / server 403 kalau maksa lewat request manual.

- [ ] **Step 8: Commit**

```bash
git add resources/views/layouts/app.blade.php \
  resources/views/maintenance/partials/preventive-schedule-grid.blade.php \
  tests/Feature/Layout/KomponenBersamaTest.php
git commit -m "feat(preventive-schedule): komponen Alpine scheduleItemEdit — klik cell edit tanggal PLAN"
```

---

### Task 6: Verifikasi akhir

**Files:** none

- [ ] **Step 1:** `php artisan test` — semua lolos.
- [ ] **Step 2:** `./scripts/check-migration-coverage.sh` — lolos.
- [ ] **Step 3:** `vendor/bin/pint app tests` — bersih.
- [ ] **Step 4:** PDF regresi — generate PDF mesin & dies (route `schedule-pdf`), pastikan TETAP identik visual (baris backlog tetap nol muncul, item locked tampil normal kayak item biasa — PDF nol tau soal lock).
- [ ] **Step 5:** Verifikasi visual browser (Task 5 Step 7) diulang sekali lagi di state akhir branch.
