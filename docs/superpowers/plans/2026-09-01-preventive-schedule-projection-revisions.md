# Preventive Schedule Projection & Revisions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mengubah Schedule Preventive Maintenance menjadi scheduler prediktif berbasis laju stroke 30 hari, rotasi hari, backlog persisten, dan revisi dokumen immutable.

**Architecture:** Pertahankan `PreventiveSchedule` sebagai identitas dokumen per bulan/jenis, lalu tambahkan `PreventiveScheduleRevision` sebagai snapshot immutable dan kaitkan item ke revision. Pisahkan kalkulasi proyeksi batch, allocator slot murni, orchestration revisi, PDF, dan notification agar setiap unit dapat diuji sendiri; `PreventiveScheduleService` tetap menjadi façade kompatibilitas untuk command dan controller.

**Tech Stack:** Laravel 13, PHP 8.4-compatible syntax, MySQL production, SQLite in-memory tests, Eloquent, Carbon, Cache locks, Dompdf, Bootstrap 5.3.3, Alpine.js 3.14.1.

**Spec:** `docs/superpowers/specs/2026-09-01-preventive-schedule-projection-revisions-design.md`

## Global Constraints

- App menyimpan waktu dalam UTC; semua tanggal operasional dan tampilan bulan memakai `Asia/Jakarta` pada boundary service/tampilan. Jangan mengubah `APP_TIMEZONE`.
- Current stroke dan threshold existing tidak boleh berubah; fitur ini hanya mengonsumsi hasil domain tersebut.
- Stroke fisik selalu `qty_aktual + COALESCE(qty_ng, 0)`.
- Laju memakai 30 hari kalender selesai (`T-30` sampai `T-1`) dan denominator tetap 30.
- Mesin: 1 slot/minggu, Senin-Kamis. Dies: 5 slot/minggu, Senin-Jumat, maksimal 1 dies/hari.
- Minggu dan `config('hari_libur')` tidak boleh menjadi tanggal PLAN.
- Backlog tidak boleh hilang diam-diam; revisi aktif lama tidak boleh berubah bila generate gagal.
- Semua model baru/diubah mengikuti SoftDeletes.
- Seluruh schema baru harus memakai `Schema::hasTable/hasColumn` guard selama rollout kode-sebelum-migration.
- Setiap migration baru wajib didaftarkan di `.github/workflows/deploy.yml` pada PR yang sama.
- Jalankan `./scripts/check-migration-coverage.sh`, full test suite, dan Pint sebelum selesai.
- Jangan memverifikasi dengan membuat transaksi PREVENTIVE percobaan di production.
- Implementation branch/worktree harus dimulai dari `origin/main` terbaru; checkout pembuat plan saat ini divergen dan tidak boleh dijadikan base tanpa rekonsiliasi eksplisit.

---

## File Structure

**Create**

- `config/preventive_schedule.php` — kapasitas, weekday, window, horizon, lock TTL.
- `app/Models/PreventiveScheduleRevision.php` — revision immutable dan relasi item/PDF.
- `app/Services/PreventiveProjectionService.php` — current metrics + laju batch + kandidat.
- `app/Services/PreventiveScheduleAllocator.php` — slot/rotasi/alokasi murni.
- `app/Services/PreventiveScheduleRevisionService.php` — preview, hash, aktivasi, carry backlog, lock.
- `app/Exceptions/StalePreventiveSchedulePreview.php` — preview tidak lagi cocok dengan state aktif.
- `app/Exceptions/NoPreventiveScheduleChanges.php` — hasil kalkulasi identik dengan revisi aktif.
- `app/Services/PreventiveSchedulePdfService.php` — PDF resmi private dan PDF monitoring live.
- `app/Services/PreventiveScheduleNotificationService.php` — notifikasi ringkasan dan resolve.
- `app/Http/Controllers/Maintenance/PreventiveScheduleController.php` — preview, activate, PDF, history.
- `resources/views/maintenance/preventive-schedule-preview.blade.php` — diff revisi.
- `resources/views/maintenance/preventive-schedule-history.blade.php` — riwayat revisi.
- `database/migrations/2026_09_01_000001_create_preventive_schedule_revisions_table.php`.
- `database/migrations/2026_09_01_000002_expand_preventive_schedule_items_for_projection.php`.
- `database/migrations/2026_09_01_000003_backfill_preventive_schedule_revision_zero.php`.
- `database/migrations/2026_09_01_000004_add_active_revision_to_preventive_schedules.php`.
- Focused tests named in each task.

**Modify**

- `app/Models/PreventiveSchedule.php`, `app/Models/PreventiveScheduleItem.php` — revision-aware relations/casts/SoftDeletes.
- `app/Models/MasterMesin.php`, `app/Models/MasterPartProses.php` — revision item relations remain compatible.
- `app/Services/PreventiveScheduleService.php` — façade legacy to revision workflow.
- `app/Console/Commands/JadwalkanPreventive.php` — create-only cron semantics per jenis.
- `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php` and `ControlStrokeDiesController.php` — schema-safe banner/KPI integration; preventive completion hook.
- `app/Services/NotificationService.php`, `app/Http/Controllers/NotificationController.php` — schedule notification type.
- `resources/views/maintenance/preventive-schedule-pdf.blade.php` — official/live mode, projection, status, multiple ACT dates.
- `resources/views/maintenance/control-stroke-mesin.blade.php`, `control-stroke-dies.blade.php` — CTA/KPI/banner.
- `routes/web.php`, `.github/workflows/deploy.yml`, existing preventive tests.

---

### Task 1: Add Revision Schema, Backfill Rev. 00, and Update Models

**Files:**
- Create: `database/migrations/2026_09_01_000001_create_preventive_schedule_revisions_table.php`
- Create: `database/migrations/2026_09_01_000002_expand_preventive_schedule_items_for_projection.php`
- Create: `database/migrations/2026_09_01_000003_backfill_preventive_schedule_revision_zero.php`
- Create: `database/migrations/2026_09_01_000004_add_active_revision_to_preventive_schedules.php`
- Create: `app/Models/PreventiveScheduleRevision.php`
- Modify: `app/Models/PreventiveSchedule.php`
- Modify: `app/Models/PreventiveScheduleItem.php`
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Feature/Maintenance/PreventiveScheduleRevisionMigrationTest.php`

**Interfaces:**
- Produces: `PreventiveSchedule::revisions(): HasMany`, `::activeRevision(): BelongsTo`, `PreventiveScheduleRevision::items(): HasMany`, `PreventiveScheduleItem::revision(): BelongsTo`.
- Compatibility: legacy `preventive_schedule_items.schedule_id`, `stroke_snapshot`, and `tanggal_rencana` remain during this rollout; new code dual-writes/falls back until a later cleanup PR.

- [ ] **Step 1: Write migration/backfill tests**

Create a feature test that inserts a legacy schedule/item after `RefreshDatabase`, invokes a dedicated assertion helper, and verifies Rev. 00 shape. Because test migrations already run before the test, verify the backfilled contract using a fixture shaped like the migration result and separately assert all columns exist:

```php
public function test_revision_schema_and_legacy_contract_exist(): void
{
    $this->assertTrue(Schema::hasTable('preventive_schedule_revisions'));
    foreach (['active_revision_id', 'deleted_at'] as $column) {
        $this->assertTrue(Schema::hasColumn('preventive_schedules', $column));
    }
    foreach (['revision_id', 'daily_rate_snapshot', 'projected_overdue_at',
        'planned_date', 'allocation_status', 'backlog_since', 'deleted_at'] as $column) {
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', $column));
    }
}

public function test_existing_schedule_is_backfilled_as_active_revision_zero(): void
{
    $schedule = PreventiveSchedule::create([
        'bulan' => '2026-08-01',
        'jenis' => 'mesin',
        'nomor_dokumen' => 'PM-MESIN/1/VIII/2026',
        'generated_at' => now(),
    ]);
    $revision = PreventiveScheduleRevision::create([
        'schedule_id' => $schedule->id,
        'revision_no' => 0,
        'calculation_hash' => hash('sha256', 'legacy-'.$schedule->id),
        'config_snapshot' => ['source' => 'legacy'],
        'generated_at' => $schedule->generated_at,
        'generated_by' => $schedule->generated_by,
    ]);
    $schedule->update(['active_revision_id' => $revision->id]);

    $this->assertSame(0, $schedule->fresh()->activeRevision->revision_no);
}
```

Also add a migration integration test that loads a small SQL fixture into a database stopped at the old two schedule migrations, runs the four new migration paths, and asserts legacy items now have `revision_id`, `planned_date = tanggal_rencana`, identity snapshots, and `allocation_status = scheduled`. Use Artisan's `migrate --path` with a temporary SQLite database; never call production DB.

- [ ] **Step 2: Run the focused test and verify RED**

Run:

```bash
php artisan test --filter=PreventiveScheduleRevisionMigrationTest
```

Expected: FAIL because revision table/model/columns do not exist.

- [ ] **Step 3: Create additive migrations**

Migration `000001` creates `preventive_schedule_revisions` with:

```php
Schema::create('preventive_schedule_revisions', function (Blueprint $table) {
    $table->id();
    $table->foreignId('schedule_id')->constrained('preventive_schedules')->cascadeOnDelete();
    $table->unsignedInteger('revision_no');
    $table->char('calculation_hash', 64);
    $table->json('config_snapshot');
    $table->dateTime('generated_at')->nullable();
    $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
    $table->string('revision_reason', 500)->nullable();
    $table->unsignedInteger('scheduled_count')->default(0);
    $table->unsignedInteger('late_count')->default(0);
    $table->unsignedInteger('backlog_count')->default(0);
    $table->unsignedInteger('backlog_urgent_count')->default(0);
    $table->string('official_pdf_path')->nullable();
    $table->char('official_pdf_sha256', 64)->nullable();
    $table->unsignedBigInteger('official_pdf_size')->nullable();
    $table->dateTime('official_pdf_issued_at')->nullable();
    $table->timestamps();
    $table->softDeletes();
    $table->unique(['schedule_id', 'revision_no']);
});
```

Migration `000002` adds nullable compatibility columns to items, adds `deleted_at` to schedules/items, and makes legacy `tanggal_rencana` nullable:

```php
$table->foreignId('revision_id')->nullable()->after('schedule_id')->index();
$table->string('asset_type', 10)->nullable();
$table->string('asset_code')->nullable();
$table->string('asset_name')->nullable();
$table->unsignedBigInteger('process_id_snapshot')->nullable();
$table->string('process_name_snapshot')->nullable();
$table->integer('current_stroke_snapshot')->nullable();
$table->integer('stroke_30_days_snapshot')->nullable();
$table->decimal('daily_rate_snapshot', 14, 4)->nullable();
$table->date('projected_overdue_at')->nullable();
$table->date('planned_date')->nullable();
$table->string('allocation_status', 24)->nullable()->index();
$table->json('eligibility_reason')->nullable();
$table->date('backlog_since')->nullable();
$table->unsignedInteger('priority_rank')->nullable();
$table->softDeletes();
```

Use guarded `Schema::hasColumn` checks. Change `tanggal_rencana` to nullable only when present. Add `deleted_at` to `preventive_schedules` only when absent.

Migration `000003` uses `DB::table()` only, never application models. For each legacy schedule without a revision, insert Rev. 00, backfill its items, and derive identity from `master_mesin` or `master_part_proses` + `master_part`. Use `generated_at ?? updated_at`, `config_snapshot = {"source":"legacy","version":0}`, and deterministic `hash('sha256', 'legacy|'.$schedule->id.'|'.$schedule->updated_at)`.

Migration `000004` adds nullable indexed `active_revision_id`, then points every schedule at revision 0. Do not add the circular FK in this rollout; a later constraint migration may add it after production backfill proof.

- [ ] **Step 4: Create revision-aware models**

`PreventiveScheduleRevision` must cast JSON/dates, use SoftDeletes, expose `schedule()` and ordered `items()`:

```php
public function items(): HasMany
{
    return $this->hasMany(PreventiveScheduleItem::class, 'revision_id')
        ->orderByRaw('planned_date IS NULL')
        ->orderBy('planned_date')
        ->orderBy('priority_rank');
}
```

`PreventiveSchedule` uses SoftDeletes and adds:

```php
public function revisions(): HasMany
{
    return $this->hasMany(PreventiveScheduleRevision::class, 'schedule_id')
        ->orderByDesc('revision_no');
}

public function activeRevision(): BelongsTo
{
    return $this->belongsTo(PreventiveScheduleRevision::class, 'active_revision_id');
}
```

`PreventiveScheduleItem` uses SoftDeletes, adds new fields/casts, and retains legacy casts. `planned_date`, `projected_overdue_at`, and `backlog_since` cast to `date:Y-m-d`; rate and pct cast to float; reasons cast to array.

- [ ] **Step 5: Register all four migrations in deploy workflow**

Append exact chronological `--path` entries to `.github/workflows/deploy.yml`, moving the closing quote to the final new line.

- [ ] **Step 6: Run schema tests and migration coverage**

```bash
php artisan test --filter=PreventiveScheduleRevisionMigrationTest
./scripts/check-migration-coverage.sh
```

Expected: PASS; all four migrations reported covered.

- [ ] **Step 7: Commit schema slice**

```bash
git add database/migrations/2026_09_01_00000*_preventive* app/Models/PreventiveSchedule.php app/Models/PreventiveScheduleRevision.php app/Models/PreventiveScheduleItem.php .github/workflows/deploy.yml tests/Feature/Maintenance/PreventiveScheduleRevisionMigrationTest.php
git commit -m "feat(preventive-schedule): add revision schema and legacy backfill"
```

---

### Task 2: Add Config and Batch Projection Service

**Files:**
- Create: `config/preventive_schedule.php`
- Create: `app/Services/PreventiveProjectionService.php`
- Test: `tests/Unit/PreventiveProjectionServiceTest.php`

**Interfaces:**
- Produces: `PreventiveProjectionService::candidates(string $jenis, CarbonInterface $tanggalGenerate): Collection<int,array<string,mixed>>`.
- Candidate keys: `asset_type`, `asset_id`, `id_mesin`, `id_part_proses`, identity snapshots, `current_stroke`, `threshold`, `pct`, `stroke_30_days`, `daily_rate`, `projected_overdue_at`, `eligibility_reason`, `backlog_since`.
- Throws `InvalidArgumentException` for unknown `jenis`.

- [ ] **Step 1: Write failing projection tests**

Cover these exact cases with factories/helpers already used by current cycle tests:

```php
public function test_window_uses_thirty_completed_calendar_days_and_excludes_today(): void
{
    Carbon::setTestNow('2026-09-01 08:00:00');
    $asset = $this->mesinWithProduction([
        '2026-08-02' => [100, 5],
        '2026-08-31' => [200, 10],
        '2026-09-01' => [999999, 0],
        '2026-08-01' => [777777, 0],
    ]);

    $row = app(PreventiveProjectionService::class)
        ->candidates('mesin', now()->timezone('Asia/Jakarta'))
        ->firstWhere('asset_id', $asset->id);

    $this->assertSame(315, $row['stroke_30_days']);
    $this->assertSame(10.5, $row['daily_rate']);
}

public function test_projection_uses_ceil_and_safety_net(): void
{
    $this->assertSame('2026-09-11', $this->project(200, 10, 300, '2026-09-01'));
    $this->assertSame('2026-09-01', $this->project(300, 0, 300, '2026-09-01'));
    $this->assertSame('2026-09-01', $this->project(240, 0, 300, '2026-09-01'));
    $this->assertNull($this->project(100, 0, 300, '2026-09-01'));
}
```

Also test: 79% projected before 8 October is eligible; 79% projected 9 October is excluded; 80% is eligible with zero rate; window crosses a preventive reset; mesin and dies queries include `qty_ng`; query count remains bounded as asset count grows.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=PreventiveProjectionServiceTest
```

Expected: FAIL because config/service do not exist.

- [ ] **Step 3: Create config**

Use the exact approved defaults plus `lock_seconds => 60` and `display_timezone => Asia/Jakarta`. Weekday values follow Carbon ISO weekday 1-7.

- [ ] **Step 4: Implement batch metrics**

Implement one candidate pipeline per type:

1. Load current-cycle rows through existing dashboard services for behavioral compatibility.
2. Extract all asset IDs.
3. Run one grouped production aggregate for the 30-day window per type:

```php
$rates = TransaksiProduksi::query()
    ->whereBetween('tanggal_produksi', [$start->toDateString(), $end->toDateString()])
    ->whereIn($idColumn, $assetIds)
    ->selectRaw("{$idColumn} AS asset_id")
    ->selectRaw('COALESCE(SUM(qty_aktual + COALESCE(qty_ng, 0)), 0) AS stroke_30_days')
    ->groupBy($idColumn)
    ->pluck('stroke_30_days', 'asset_id');
```

Do not query per asset. Resolve identity from current-cycle rows and eager-loaded master collections. Carry `backlog_since` by one batch query against the latest active revision items for matching assets, ignoring carry when a newer relevant preventive log exists.

Implement private pure methods `projectedDate(...)`, `cutoff(...)`, and `eligibilityReasons(...)`. Keep precise rate for calculation; round only display output.

- [ ] **Step 5: Run projection tests**

```bash
php artisan test --filter=PreventiveProjectionServiceTest
```

Expected: PASS, including bounded query-count assertion.

- [ ] **Step 6: Commit projection slice**

```bash
git add config/preventive_schedule.php app/Services/PreventiveProjectionService.php tests/Unit/PreventiveProjectionServiceTest.php
git commit -m "feat(preventive-schedule): calculate batch overdue projections"
```

---

### Task 3: Build Deterministic Rotating Slot Allocator

**Files:**
- Create: `app/Services/PreventiveScheduleAllocator.php`
- Test: `tests/Unit/PreventiveScheduleAllocatorTest.php`

**Interfaces:**
- Consumes candidate collection from Task 2.
- Produces `allocate(string $jenis, CarbonInterface $bulan, Collection $candidates): Collection` with `planned_date`, `allocation_status`, and `priority_rank` added.
- Contains no DB writes and no auth/time globals.

- [ ] **Step 1: Write failing pure allocator tests**

Use compact candidate arrays and fixed `config(['hari_libur' => [...]])`. Required tests:

```php
public function test_mesin_rotates_monday_through_thursday(): void
{
    // Juni 2026 dimulai Senin, jadi target canonical kelihatan tanpa fallback
    // minggu parsial pada minggu pertama.
    $rows = $this->allocateMachinesForFiveWeeks('2026-06-01');
    $this->assertSame([1, 2, 3, 4, 1], $rows->pluck('planned_date')->map->dayOfWeekIso->all());
}

public function test_future_deadline_uses_latest_safe_slot(): void
{
    $result = $this->allocator()->allocate('mesin', Carbon::parse('2026-09-01'), collect([
        $this->candidate(id: 1, projected: '2026-09-24'),
    ]))->first();

    $this->assertLessThanOrEqual('2026-09-24', $result['planned_date']);
    $this->assertSame('scheduled', $result['allocation_status']);
}

public function test_no_safe_slot_uses_first_late_slot_before_backlog(): void
{
    // Earlier priority consumes safe slot; second item still receives next capacity.
    $result = $this->allocateConflict()->firstWhere('asset_id', 2);
    $this->assertSame('late', $result['allocation_status']);
    $this->assertNotNull($result['planned_date']);
}
```

Also cover: holiday fallback within week; partial first/last week; no Saturday/Sunday; dies max one/day and five/week; capacity not moved between weeks; old urgent backlog priority; deterministic tie by asset ID; no duplicate asset; `backlog` vs `backlog_urgent` when all slots consumed.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=PreventiveScheduleAllocatorTest
```

Expected: FAIL because allocator does not exist.

- [ ] **Step 3: Implement slot construction and priority**

Create weekly buckets by ISO week. Build allowed dates, remove holidays, rotate canonical weekday order using zero-based relative week index, and truncate effective weekly capacity to valid unique dates.

Sort candidates with a tuple equivalent to:

```php
[
    $candidate['carried_backlog_urgent'] ? 0 : 1,
    $candidate['current_stroke'] >= $candidate['threshold'] ? 0 : 1,
    $candidate['projected_overdue_at']?->format('Y-m-d') ?? '9999-12-31',
    $candidate['backlog_since']?->format('Y-m-d') ?? '9999-12-31',
    -$candidate['pct'],
    $candidate['asset_id'],
]
```

For each candidate, choose latest free slot `<= deadline`; otherwise earliest free slot `> deadline`; otherwise backlog. A deadline at/before generate date always yields `late` when a slot exists. Backlog urgent applies when due/overdue or carried from an earlier month.

- [ ] **Step 4: Run allocator tests**

```bash
php artisan test --filter=PreventiveScheduleAllocatorTest
```

Expected: PASS.

- [ ] **Step 5: Commit allocator slice**

```bash
git add app/Services/PreventiveScheduleAllocator.php tests/Unit/PreventiveScheduleAllocatorTest.php
git commit -m "feat(preventive-schedule): allocate rotating predictive slots"
```

---

### Task 4: Implement Preview, Canonical Hash, Locking, and Atomic Revision Activation

**Files:**
- Create: `app/Services/PreventiveScheduleRevisionService.php`
- Create: `app/Exceptions/StalePreventiveSchedulePreview.php`
- Create: `app/Exceptions/NoPreventiveScheduleChanges.php`
- Modify: `app/Services/PreventiveScheduleService.php`
- Test: `tests/Unit/PreventiveScheduleRevisionServiceTest.php`

**Interfaces:**
- `preview(string $jenis, CarbonInterface $bulan): array` returns schedule/current revision, candidate items, canonical hash, counts, and diff.
- `activate(string $jenis, CarbonInterface $bulan, string $expectedHash, ?int $expectedActiveRevisionId, ?string $reason, int $actorId): PreventiveScheduleRevision`.
- `createInitialIfMissing(string $jenis, CarbonInterface $bulan, ?int $actorId = null): ?PreventiveScheduleRevision`; returns null if schedule already exists.
- Existing `PreventiveScheduleService::generate()` delegates to initial creation only until controllers migrate in Task 7.

- [ ] **Step 1: Write failing revision lifecycle tests**

Required assertions:

```php
public function test_identical_preview_does_not_create_revision(): void
{
    $service = app(PreventiveScheduleRevisionService::class);
    $first = $service->createInitialIfMissing('mesin', Carbon::parse('2026-09-01'), $this->admin->id);
    $preview = $service->preview('mesin', Carbon::parse('2026-09-01'));

    $this->expectExceptionMessage('Tidak ada perubahan');
    $service->activate('mesin', Carbon::parse('2026-09-01'), $preview['hash'], $first->id, 'klik ulang', $this->admin->id);
}

public function test_stale_preview_cannot_replace_active_revision(): void
{
    $this->expectException(StalePreventiveSchedulePreview::class);
    $this->service->activate('mesin', $this->month, 'old-hash', 999, 'revisi', $this->admin->id);
}
```

Also test: Rev. 00 reason may be null; Rev. 01 reason required; revision numbers increase; active pointer changes only after all items exist; exception during item persistence rolls back; hash ignores timestamp/actor but changes on rate/date/status/config; two lock contenders produce one revision; schedule/item legacy columns dual-written. Task 5 tightens this boundary so pointer activation occurs only after official PDF issuance succeeds.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=PreventiveScheduleRevisionServiceTest
```

Expected: FAIL because orchestration service/exceptions do not exist.

- [ ] **Step 3: Implement canonical preview and diff**

Normalize config and allocated items into arrays with sorted keys and stable item order, then hash JSON using `JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION`. Exclude actor, timestamp, reason, ACT live, and revision number.

Diff by stable key `mesin:{id}` or `dies:{id}` and expose exact categories: `added`, `removed`, `moved`, `status_changed`, `projection_changed`, `rate_changed`. Treat daily-rate changes below display precision as unchanged only if they do not alter canonical stored precision, projection, or allocation.

- [ ] **Step 4: Implement activation under lock**

Use `Cache::lock("preventive-schedule:{$jenis}:{$bulanKey}", config('preventive_schedule.lock_seconds', 60))->block(5, ...)`. Recompute preview inside lock. Reject expected active revision/hash mismatch.

Within `DB::transaction`:

1. `firstOrCreate` schedule.
2. `lockForUpdate` existing schedule row.
3. Determine next revision number.
4. Insert revision summary/config/hash.
5. Bulk insert item snapshots, dual-writing legacy columns.
6. Switch `active_revision_id` only after all item inserts succeed. This is the independently testable lifecycle boundary for this slice; Task 5 moves the switch behind successful official PDF issuance before any controller is wired to the new flow.

Expose a package-private callback boundary so Task 5 can issue PDF before final pointer update without duplicating revision creation.

- [ ] **Step 5: Run revision tests**

```bash
php artisan test --filter=PreventiveScheduleRevisionServiceTest
```

Expected: PASS.

- [ ] **Step 6: Commit revision slice**

```bash
git add app/Services/PreventiveScheduleRevisionService.php app/Services/PreventiveScheduleService.php app/Exceptions/StalePreventiveSchedulePreview.php app/Exceptions/NoPreventiveScheduleChanges.php tests/Unit/PreventiveScheduleRevisionServiceTest.php
git commit -m "feat(preventive-schedule): add atomic revision preview lifecycle"
```

---

### Task 5: Issue Immutable Official PDFs and Render Live Monitoring PDFs

**Files:**
- Create: `app/Services/PreventiveSchedulePdfService.php`
- Modify: `resources/views/maintenance/preventive-schedule-pdf.blade.php`
- Modify: `app/Services/PreventiveScheduleRevisionService.php`
- Test: `tests/Feature/Maintenance/PreventiveSchedulePdfTest.php`

**Interfaces:**
- `issueOfficial(PreventiveScheduleRevision $revision): PreventiveScheduleRevision` stores private bytes/metadata and returns refreshed revision.
- `officialResponse(PreventiveScheduleRevision $revision): BinaryFileResponse` verifies path/checksum access target.
- `live(string $jenis, PreventiveScheduleRevision $revision): Dompdf` derives current ACT dates.
- Revision activation occurs only after official PDF path/hash/size exist.

- [ ] **Step 1: Write failing PDF immutability/live tests**

Use `Storage::fake('local')` and fixed clock. Assert:

```php
public function test_official_pdf_is_private_and_byte_identical_after_new_logs(): void
{
    $revision = $this->issueRevisionWithOneMachine();
    $bytesBefore = Storage::disk('local')->get($revision->official_pdf_path);

    MesinPreventiveLog::create([
        'id_mesin' => $this->mesin->id,
        'tanggal_preventive' => '2026-09-12',
        'id_karyawan' => $this->karyawan->id,
        'stroke_at_preventive' => 250000,
        'created_by' => $this->admin->id,
    ]);
    $bytesAfter = Storage::disk('local')->get($revision->official_pdf_path);

    $this->assertSame($bytesBefore, $bytesAfter);
    $this->assertSame(hash('sha256', $bytesBefore), $revision->official_pdf_sha256);
}

public function test_live_pdf_marks_all_actual_dates(): void
{
    $html = $this->renderLiveWithLogs(['2026-09-10', '2026-09-18']);
    $this->assertStringContainsString('data-act-day="10"', $html);
    $this->assertStringContainsString('data-act-day="18"', $html);
    $this->assertStringContainsString('MONITORING LIVE', $html);
}
```

Also test: official render failure leaves previous active pointer; official file path is not under `public/`; missing official file returns 404; identity snapshot renders after master soft-delete; backlog rows have no PLAN cell; dies process snapshot renders.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=PreventiveSchedulePdfTest
```

Expected: FAIL on missing PdfService/new modes.

- [ ] **Step 3: Refactor PDF view into explicit `official`/`live` modes**

Pass prepared view data only; do not call dashboard aggregation services inside Blade. Each item includes `actual_stroke` and `act_days` prepared in one batch by PdfService.

Add header fields: document number, `Rev. XX`, issued/generated actor/time WIB, projection/rate/status columns or compact annotations, and an unmistakable live banner. Official mode uses issuance-time ACT snapshots passed during render; live mode batch-loads all relevant logs and groups dates per asset.

- [ ] **Step 4: Store official PDF before active pointer switch**

Render bytes, write to a temporary private path, calculate SHA-256/size, then transactionally update revision metadata and `schedule.active_revision_id`. Move/copy to deterministic final path:

```text
preventive-schedules/{jenis}/{YYYY-MM}/{nomor-sanitized}-rev-{NN}.pdf
```

On any failure, delete temp/final partial files and keep prior active pointer.

- [ ] **Step 5: Run PDF tests**

```bash
php artisan test --filter=PreventiveSchedulePdfTest
```

Expected: PASS.

- [ ] **Step 6: Commit PDF slice**

```bash
git add app/Services/PreventiveSchedulePdfService.php app/Services/PreventiveScheduleRevisionService.php resources/views/maintenance/preventive-schedule-pdf.blade.php tests/Feature/Maintenance/PreventiveSchedulePdfTest.php
git commit -m "feat(preventive-schedule): issue immutable and live PDFs"
```

---

### Task 6: Add Revision Controller, Routes, Preview, History, and Schema-Safe Control Stroke UI

**Files:**
- Create: `app/Http/Controllers/Maintenance/PreventiveScheduleController.php`
- Create: `resources/views/maintenance/preventive-schedule-preview.blade.php`
- Create: `resources/views/maintenance/preventive-schedule-history.blade.php`
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
- Modify: `resources/views/maintenance/control-stroke-mesin.blade.php`
- Modify: `resources/views/maintenance/control-stroke-dies.blade.php`
- Test: `tests/Feature/Maintenance/PreventiveScheduleRevisionFlowTest.php`
- Test: `tests/Feature/Maintenance/PreventiveScheduleSchemaGuardTest.php`

**Interfaces:**
- Read routes preserve existing `.schedule-pdf` as live monitoring for backward compatibility.
- New named routes per type: `.schedule-preview`, `.schedule-activate`, `.schedule-history`, `.schedule-revision.pdf`.
- Write routes remain admin + staff_maintenance enforced both middleware group and controller authorization.

- [ ] **Step 1: Write failing authorization/flow/schema tests**

Cover:

- no schedule shows `Generate Jadwal`;
- existing schedule shows Rev banner and `Buat Revisi` warning;
- preview identical shows no-op and no activate button;
- preview changed shows diff and reason field;
- activate requires reason for Rev. > 0;
- guest/manager can view live/history/official but cannot preview/activate;
- staff_qc cannot read maintenance schedule;
- missing revision tables/columns does not 500 Control Stroke page and hides CTAs;
- stale preview returns validation-style redirect/message, not 500;
- lock contention returns friendly message.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter='PreventiveScheduleRevisionFlowTest|PreventiveScheduleSchemaGuardTest'
```

Expected: FAIL on routes/controller/UI.

- [ ] **Step 3: Implement focused controller and routes**

Controller methods:

```php
public function preview(string $jenis, Request $request): View|RedirectResponse;
public function activate(string $jenis, Request $request): RedirectResponse;
public function history(string $jenis): View;
public function officialPdf(string $jenis, PreventiveScheduleRevision $revision): BinaryFileResponse;
public function livePdf(string $jenis): Response;
```

Validate jenis against config and verify revision belongs to type before serving. Validate `expected_hash` as 64 hex chars, `expected_active_revision_id` nullable integer, and reason required/string/max:500 when schedule exists.

Keep explicit mesin/dies named routes rather than exposing arbitrary types. Route order must put static schedule paths before dynamic model-bound history paths where ambiguity is possible.

- [ ] **Step 4: Build preview/history pages**

Preview shows current metadata, counts, five diff sections, item status/projection/rate/PLAN, hidden expected hash/revision, required reason, and final destructive-looking confirmation copy without using disabled buttons as a state mechanism.

History lists revision number, actor/time WIB, reason, counts, hash prefix, checksum, active badge, and official download.

- [ ] **Step 5: Add schema-safe banner/KPI/CTA to both Control Stroke pages**

Controllers call a small read method only when all required tables/columns exist. Otherwise pass `scheduleFeatureReady=false`. Views show a non-error unavailable note and never query models directly.

KPI values come from active revision summary. Backlog links to preview/detail filtered by status. Existing Refresh button and page data endpoints remain unchanged.

- [ ] **Step 6: Run focused flow tests**

```bash
php artisan test --filter='PreventiveScheduleRevisionFlowTest|PreventiveScheduleSchemaGuardTest'
```

Expected: PASS.

- [ ] **Step 7: Commit UI/route slice**

```bash
git add app/Http/Controllers/Maintenance/PreventiveScheduleController.php app/Http/Controllers/Maintenance/ControlStrokeMesinController.php app/Http/Controllers/Maintenance/ControlStrokeDiesController.php resources/views/maintenance/preventive-schedule-preview.blade.php resources/views/maintenance/preventive-schedule-history.blade.php resources/views/maintenance/control-stroke-mesin.blade.php resources/views/maintenance/control-stroke-dies.blade.php routes/web.php tests/Feature/Maintenance/PreventiveScheduleRevisionFlowTest.php tests/Feature/Maintenance/PreventiveScheduleSchemaGuardTest.php
git commit -m "feat(preventive-schedule): add revision preview and history UI"
```

---

### Task 7: Add Summary Notifications and Completion Resolution

**Files:**
- Create: `app/Services/PreventiveScheduleNotificationService.php`
- Modify: `app/Services/PreventiveScheduleRevisionService.php`
- Modify: `app/Services/NotificationService.php`
- Modify: `app/Http/Controllers/NotificationController.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
- Test: `tests/Feature/Maintenance/PreventiveScheduleNotificationTest.php`

**Interfaces:**
- Notification type: `preventive_schedule_attention`.
- `notifiable_type`: `preventive_schedule_revision`; `notifiable_id`: revision ID.
- Data keys: `jenis`, `bulan`, `revision_no`, four summary counts, `resolved_at` nullable, URL filter target.
- `publishFor(PreventiveScheduleRevision $revision): void`; `resolveSuperseded(PreventiveScheduleRevision $newRevision): void`; `resolveCompletedForAsset(string $jenis, int $assetId): void`.

- [ ] **Step 1: Write failing notification tests**

Assert one notification per eligible recipient, not per asset; recipients are active users containing admin or staff_maintenance role; no notification when all items scheduled and safe; identical generate creates none; new revision resolves prior revision notification; recording preventive resolves the summary only after every problematic item has a relevant actual log; poll returns correct text, URL, and `resolved` independent of `read_at`.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=PreventiveScheduleNotificationTest
```

Expected: FAIL on missing notification service/type handling.

- [ ] **Step 3: Implement publish/dedupe/resolve**

Use one query to collect recipients:

```php
User::query()->where('is_aktif', true)
    ->where(fn ($q) => $q
        ->whereJsonContains('roles', 'admin')
        ->orWhereJsonContains('roles', 'staff_maintenance'))
    ->pluck('id');
```

Deduplicate by `type + user_id + notifiable_type + notifiable_id`. Store resolution in notification JSON data for this type; do not overload `read_at`.

Extend `NotificationController::buildText`, `buildUrl`, and resolved map with batch revision lookup/data resolution. Text example: `Schedule mesin Rev. 01: 3 terlambat, 2 belum dapat slot`.

Call publish only after revision activation succeeds. Hook preventive store success to `resolveCompletedForAsset` after log creation; never create test preventive data in production.

- [ ] **Step 4: Run notification tests**

```bash
php artisan test --filter=PreventiveScheduleNotificationTest
```

Expected: PASS.

- [ ] **Step 5: Commit notification slice**

```bash
git add app/Services/PreventiveScheduleNotificationService.php app/Services/PreventiveScheduleRevisionService.php app/Services/NotificationService.php app/Http/Controllers/NotificationController.php app/Http/Controllers/Maintenance/ControlStrokeMesinController.php app/Http/Controllers/Maintenance/ControlStrokeDiesController.php tests/Feature/Maintenance/PreventiveScheduleNotificationTest.php
git commit -m "feat(preventive-schedule): notify late and backlog revisions"
```

---

### Task 8: Make Monthly Command Create-Only and Failure-Isolated

**Files:**
- Modify: `app/Console/Commands/JadwalkanPreventive.php`
- Modify: `.github/workflows/preventive-schedule.yml` only if command output/timeout needs alignment; keep 08:00 WIB cron.
- Modify: `tests/Unit/JadwalkanPreventiveCommandTest.php`

**Interfaces:**
- Command still named `preventive:jadwalkan`.
- Calls `createInitialIfMissing()` separately for mesin and dies.
- Exit code is failure if either type fails, but successful type remains committed and output identifies each result.

- [ ] **Step 1: Extend command tests**

Test: first run creates Rev. 00 for both; second run creates no Rev. 01; existing mesin plus absent dies creates only dies; injected mesin failure still attempts dies and returns failure; command month uses WIB at UTC month boundary.

- [ ] **Step 2: Run RED**

```bash
php artisan test --filter=JadwalkanPreventiveCommandTest
```

Expected: at least duplicate/failure-isolation tests fail on current command.

- [ ] **Step 3: Refactor command loop**

Wrap each type in its own try/catch, collect failures, print `sudah ada — dilewati` for null return, and return `FAILURE` after both attempts when failures are non-empty. Log exception class/message/type, while console output remains safe and concise.

- [ ] **Step 4: Run command tests**

```bash
php artisan test --filter=JadwalkanPreventiveCommandTest
```

Expected: PASS.

- [ ] **Step 5: Commit cron slice**

```bash
git add app/Console/Commands/JadwalkanPreventive.php tests/Unit/JadwalkanPreventiveCommandTest.php .github/workflows/preventive-schedule.yml
git commit -m "fix(preventive-schedule): make monthly generation create-only"
```

---

### Task 9: Regression, Visual Verification, and Project Memory

**Files:**
- Modify: `tests/Feature/AuthorizationTest.php` if route inventory assertions require new routes.
- Modify: `AGENTS.md` Active Work section.
- Modify: `docs/superpowers/specs/2026-09-01-preventive-schedule-projection-revisions-design.md` only if implementation exposed an approved clarification; no silent scope changes.

**Interfaces:**
- No new production behavior; this task proves prior slices meet the spec.

- [ ] **Step 1: Run every focused preventive test together**

```bash
php artisan test --filter='PreventiveSchedule|JadwalkanPreventive'
```

Expected: PASS; no risky or skipped test.

- [ ] **Step 2: Run full suite**

```bash
php artisan test
```

Expected: PASS with zero regressions.

- [ ] **Step 3: Run migration coverage and PHP 8.4 compatibility checks**

```bash
./scripts/check-migration-coverage.sh
composer check-platform-reqs
```

Expected: all four migrations covered; dependencies compatible with production PHP constraints. Review code for PHP 8.5-only syntax even if local PHP is 8.5.

- [ ] **Step 4: Run Pint check, then fix only touched scope if needed**

```bash
vendor/bin/pint app tests --test
```

If it reports touched files, run `vendor/bin/pint app tests`, inspect diff, rerun focused/full tests, then commit formatting separately.

- [ ] **Step 5: Render visual PDF matrix locally**

Create test-only fixtures through factories in local/SQLite or a dedicated local DB, never production. Render official and live PDFs for:

- February 2027 (28 days);
- September 2026 (30 days);
- October 2026 (31 days);
- mesin/dies;
- holiday, late, backlog, backlog urgent, multiple ACT, deleted/renamed master snapshot.

Use the PDF skill/tooling during execution to render pages to images and inspect every page for clipping, wrapping, colors, process column, signature area, live banner, revision header, and pagination. Keep generated fixtures/artifacts outside tracked production paths.

- [ ] **Step 6: Update project memory**

Add an Active Work row describing predictive projection, revision lifecycle, config path, migrations, schema guards, official/live PDF distinction, and operational prohibition against production preventive probes. Remove or supersede the stale statement that schedule only filters warning/overdue by pct.

- [ ] **Step 7: Final diff review**

```bash
git status --short
git diff --stat origin/main...HEAD
git diff --check origin/main...HEAD
```

Verify no unrelated files, secrets, generated PDFs, temporary SQLite DBs, or vendor changes are included.

- [ ] **Step 8: Commit verification/memory changes**

```bash
git add AGENTS.md tests/Feature/AuthorizationTest.php
git commit -m "docs: record predictive preventive schedule workflow"
```

If `AuthorizationTest.php` did not change, omit it. Do not create an empty commit.

---

## Completion Gate

Work is complete only when:

- All nine task slices are implemented and reviewed.
- Full suite, migration coverage, and Pint pass on final diff.
- Official and live PDF matrices are visually inspected.
- Existing production data is backfilled to Rev. 00 without deletion.
- Old active revision survives injected activation/PDF failures.
- Repeated cron/manual no-op does not create duplicate revisions or notifications.
- No production PREVENTIVE transaction was created for verification.
