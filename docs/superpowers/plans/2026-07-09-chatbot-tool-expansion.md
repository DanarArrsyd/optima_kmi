# Chatbot Tool Expansion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add 6 new `ChatbotTools` methods (root-cause raw-list tools for dies/mesin breakdown, breakdown ranking, dandori rekap, total produksi), harden the system prompt against over-inference on raw-list data, swap the Gemini model to `gemini-2.5-flash-lite`, and add the ability to delete a chat session.

**Architecture:** All new tool methods live in `app/Services/ChatbotTools.php` alongside the 6 existing ones, following the identical pattern: parse `YYYY-MM` input via the existing `monthRange()`/`monthSpan()` private helpers, run one Eloquent query (or reuse an existing dashboard service's `compute()`), return either a data array or `['error' => "..."]` — never throw. Two tools (`getProblemDies`, `getProblemMesin`) return raw free-text lists capped at 150 rows because the underlying fields are operator-typed and not safe to `GROUP BY` exactly. Delete-session reuses `ChatConversation`'s existing `SoftDeletes`.

**Tech Stack:** Laravel 13 / Eloquent, PHPUnit (`RefreshDatabase`), Alpine.js (chatbot UI), Gemini/Groq HTTP APIs.

## Global Constraints

- All new tools return `{'error': "..."}` on invalid input or no matching data — never throw (spec: Global Constraints)
- All new tools use the existing `monthRange()`/`monthSpan()` private helpers on `ChatbotTools` for `YYYY-MM` parsing — no new date format (spec: Global Constraints)
- `getProblemDies`/`getProblemMesin` cap results at 150 rows ordered by breakdown date descending; if truncated, include a `note` key: `"Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap."` (spec: Global Constraints)
- No changes to `GeminiChatbotService`'s or `GroqChatbotService`'s request/response dispatch loop (spec: Global Constraints)
- System prompt addition must be applied identically (character-for-character) to the `SYSTEM_PROMPT` const in both `GeminiChatbotService` and `GroqChatbotService` (spec: Context note — trait extraction not yet landed)

---

## Task 1: `getProblemDies` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `MasterPart` (existing model, `whereRaw('LOWER(TRIM(part_no)) = ?', ...)` lookup pattern already used by `getMtbfMttrDies`), `TransaksiBreakdownDies` (existing model, fields `problem_dies`, `penyebab_breakdown`, `tindakan_perbaikan`, `tanggal_breakdown`, relation `partProses.part`), `ChatbotTools::monthSpan(string $bulanMulai, string $bulanAkhir): array` (existing private helper, returns `[Carbon $from, Carbon $to, ?string $error]`)
- Produces: `ChatbotTools::getProblemDies(string $bulanMulai, string $bulanAkhir, ?string $partNo = null): array`, used later only by the schema registration in this same task (no cross-task dependency)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php` (add `use App\Models\MasterPartProses;` — already imported; no new imports needed since `MasterPart`, `MasterPartProses`, `TransaksiBreakdownDies` are already imported):

```php
    public function test_get_problem_dies_returns_raw_list_with_part_filter(): void
    {
        $part = MasterPart::create(['part_no' => 'PD-01', 'part_name' => 'Frame', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $otherPart = MasterPart::create(['part_no' => 'PD-02', 'part_name' => 'Cover', 'customer' => 'Honda']);
        $otherProses = MasterPartProses::create([
            'id_part' => $otherPart->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-P1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '11:00',
            'problem_dies' => 'Motor overheat', 'penyebab_breakdown' => 'Bearing aus', 'tindakan_perbaikan' => 'Ganti bearing',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 2.0, 'total_downtime' => 3.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-P2', 'id_part_proses' => $otherProses->id,
            'tanggal_breakdown' => '2026-07-06', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-06', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-06', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Baut kendor', 'penyebab_breakdown' => 'Vibrasi tinggi', 'tindakan_perbaikan' => 'Kencangkan baut',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07', 'PD-01');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('Motor overheat', $result['data'][0]['problem']);
        $this->assertSame('Bearing aus', $result['data'][0]['penyebab']);
        $this->assertSame('Ganti bearing', $result['data'][0]['tindakan']);
        $this->assertSame('Frame', $result['data'][0]['part']);
    }

    public function test_get_problem_dies_without_part_filter_returns_all(): void
    {
        $part = MasterPart::create(['part_no' => 'PD-03', 'part_name' => 'Bracket', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-P3', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Sensor error', 'penyebab_breakdown' => 'Kabel putus', 'tindakan_perbaikan' => 'Ganti kabel',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
    }

    public function test_get_problem_dies_returns_error_for_unknown_part(): void
    {
        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07', 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_problem_dies_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getProblemDies('2020-01', '2020-01');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_problem_dies_truncates_at_150_rows_with_note(): void
    {
        $part = MasterPart::create(['part_no' => 'PD-04', 'part_name' => 'Panel', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        for ($i = 1; $i <= 151; $i++) {
            TransaksiBreakdownDies::create([
                'no_lkd' => "LKD-BULK-{$i}", 'id_part_proses' => $proses->id,
                'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => "Problem {$i}", 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(151, $result['total_record']);
        $this->assertCount(150, $result['data']);
        $this->assertArrayHasKey('note', $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_problem_dies`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getProblemDies()"

- [ ] **Step 3: Implement `getProblemDies`**

Add to `app/Services/ChatbotTools.php`, directly after `getTotalBreakdownMesin` (before `produksiSummary`):

```php
    /** Daftar mentah problem/penyebab/tindakan breakdown dies dalam rentang bulan (opsional filter per part). */
    public function getProblemDies(string $bulanMulai, string $bulanAkhir, ?string $partNo = null): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $prosesIds = null;
        if ($partNo !== null) {
            $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
            if (!$part) {
                return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }

        $query = TransaksiBreakdownDies::with('partProses.part')
            ->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()])
            ->when($prosesIds !== null, fn ($q) => $q->whereIn('id_part_proses', $prosesIds))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            $label = $partNo ? " untuk part \"{$partNo}\"" : '';
            return ['error' => "Tidak ada data breakdown dies{$label} di periode {$bulanMulai} s/d {$bulanAkhir}."];
        }

        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'part'     => $r->partProses?->part?->part_name ?? '—',
            'problem'  => $r->problem_dies,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        $result = [
            'bulan_mulai'   => $bulanMulai,
            'bulan_akhir'   => $bulanAkhir,
            'total_record'  => $total,
            'data'          => $rows,
        ];

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }
```

Add `use App\Models\MasterPartProses;` and `use App\Models\TransaksiBreakdownDies;` to the top imports of `app/Services/ChatbotTools.php` (check first — `TransaksiBreakdownDies` may not be imported yet; `MasterMesin`, `MasterPart`, `TransaksiProduksi`, `TransaksiQc` already are).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_problem_dies`
Expected: PASS (5 tests)

- [ ] **Step 5: Add schema entry**

In `app/Services/ChatbotTools.php`, inside `schemaDefinitions()`, append after the `getTotalBreakdownMesin` entry:

```php
            [
                'name' => 'getProblemDies',
                'description' => 'Ambil daftar mentah problem, penyebab, dan tindakan perbaikan breakdown dies dalam rentang bulan. Dipakai untuk pertanyaan root-cause seperti "problem apa yang sering muncul", "penyebabnya apa", "perbaikan yang cocok apa" — kamu HARUS merangkum sendiri dari data yang dikembalikan, jangan menambah pola yang tidak ada di teks.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                        'partNo' => ['type' => 'string', 'description' => 'Opsional, nomor part/dies spesifik. Kosongkan untuk semua dies.'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS (all tests, existing + new)

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getProblemDies tool for dies root-cause questions"
```

---

## Task 2: `getProblemMesin` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `MasterMesin` (existing model, `whereRaw('LOWER(TRIM(id_mesin)) = ?', ...)` lookup pattern already used by `getMtbfMttrMesin`), `TransaksiBreakdownMesin` (existing model, fields `problem_mesin`, `penyebab_breakdown`, `tindakan_perbaikan`, `tanggal_breakdown`, relation `mesin`), `ChatbotTools::monthSpan()` (Task 1)
- Produces: `ChatbotTools::getProblemMesin(string $bulanMulai, string $bulanAkhir, ?string $idMesin = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`:

```php
    public function test_get_problem_mesin_returns_raw_list_with_mesin_filter(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'PM-01', 'nama_mesin' => 'Press A', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $otherMesin = MasterMesin::create([
            'id_mesin' => 'PM-02', 'nama_mesin' => 'Press B', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-P1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Motor macet', 'penyebab_breakdown' => 'Oli habis', 'tindakan_perbaikan' => 'Isi oli',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-P2', 'id_mesin' => $otherMesin->id,
            'tanggal_breakdown' => '2026-07-06', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-06', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-06', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Panel error', 'penyebab_breakdown' => 'Korsleting', 'tindakan_perbaikan' => 'Ganti panel',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemMesin('2026-07', '2026-07', 'PM-01');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
        $this->assertSame('Motor macet', $result['data'][0]['problem']);
        $this->assertSame('Oli habis', $result['data'][0]['penyebab']);
        $this->assertSame('Isi oli', $result['data'][0]['tindakan']);
        $this->assertSame('Press A', $result['data'][0]['mesin']);
    }

    public function test_get_problem_mesin_returns_error_for_unknown_mesin(): void
    {
        $result = (new ChatbotTools())->getProblemMesin('2026-07', '2026-07', 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_problem_mesin_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getProblemMesin('2020-01', '2020-01');
        $this->assertArrayHasKey('error', $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_problem_mesin`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getProblemMesin()"

- [ ] **Step 3: Implement `getProblemMesin`**

Add to `app/Services/ChatbotTools.php`, directly after `getProblemDies`:

```php
    /** Daftar mentah problem/penyebab/tindakan breakdown mesin dalam rentang bulan (opsional filter per mesin). */
    public function getProblemMesin(string $bulanMulai, string $bulanAkhir, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }

        $query = TransaksiBreakdownMesin::with('mesin')
            ->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()])
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            return ['error' => "Tidak ada data breakdown mesin{$label} di periode {$bulanMulai} s/d {$bulanAkhir}."];
        }

        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'mesin'    => $r->mesin?->nama_mesin ?? '—',
            'problem'  => $r->problem_mesin,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        $result = [
            'bulan_mulai'  => $bulanMulai,
            'bulan_akhir'  => $bulanAkhir,
            'total_record' => $total,
            'data'         => $rows,
        ];

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }
```

Add `use App\Models\TransaksiBreakdownMesin;` to imports if not already present.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_problem_mesin`
Expected: PASS (3 tests)

- [ ] **Step 5: Add schema entry**

Append to `schemaDefinitions()` after the `getProblemDies` entry:

```php
            [
                'name' => 'getProblemMesin',
                'description' => 'Sama seperti getProblemDies tapi untuk mesin — problem/penyebab/tindakan breakdown mesin.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getProblemMesin tool for mesin root-cause questions"
```

---

## Task 3: `getRankingBreakdownMesin` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `MaintenanceDashboardService::compute(Carbon $from, Carbon $to, ?int $mesinId = null): array` (existing service, already injected as `$this->mesinDashboard` in `ChatbotTools`'s constructor — returns one row per machine with keys `nama_mesin`, `kode_mesin`, `count`, `availability_pct` when `$mesinId` is null), `ChatbotTools::monthSpan()` (Task 1)
- Produces: `ChatbotTools::getRankingBreakdownMesin(string $bulanMulai, string $bulanAkhir): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`:

```php
    public function test_get_ranking_breakdown_mesin_sorts_by_count_desc(): void
    {
        $mesinLow = MasterMesin::create([
            'id_mesin' => 'RM-01', 'nama_mesin' => 'Press Low', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesinHigh = MasterMesin::create([
            'id_mesin' => 'RM-02', 'nama_mesin' => 'Press High', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-R1', 'id_mesin' => $mesinLow->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        foreach (['LKM-R2', 'LKM-R3'] as $i => $noLkm) {
            TransaksiBreakdownMesin::create([
                'no_lkm' => $noLkm, 'id_mesin' => $mesinHigh->id,
                'tanggal_breakdown' => '2026-07-0'.($i + 6), 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-0'.($i + 6), 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-0'.($i + 6), 'jam_selesai_perbaikan' => '10:00',
                'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getRankingBreakdownMesin('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Press High', $result['ranking'][0]['mesin']);
        $this->assertSame(2, $result['ranking'][0]['jumlah_breakdown']);
        $this->assertSame('Press Low', $result['ranking'][1]['mesin']);
        $this->assertSame(1, $result['ranking'][1]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_mesin_returns_error_when_no_breakdowns(): void
    {
        MasterMesin::create([
            'id_mesin' => 'RM-03', 'nama_mesin' => 'Press Idle', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownMesin('2026-07', '2026-07');

        $this->assertArrayHasKey('error', $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_ranking_breakdown_mesin`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getRankingBreakdownMesin()"

- [ ] **Step 3: Implement `getRankingBreakdownMesin`**

Add to `app/Services/ChatbotTools.php`, directly after `getProblemMesin`:

```php
    /** Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. */
    public function getRankingBreakdownMesin(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = collect($this->mesinDashboard->compute($from, $to))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada kejadian breakdown mesin di periode {$bulanMulai} s/d {$bulanAkhir}."];
        }

        return [
            'bulan_mulai' => $bulanMulai,
            'bulan_akhir' => $bulanAkhir,
            'ranking'     => $rows->map(fn ($r) => [
                'mesin'            => $r['nama_mesin'],
                'kode_mesin'       => $r['kode_mesin'],
                'jumlah_breakdown' => $r['count'],
                'availability_pct' => $r['availability_pct'],
            ])->toArray(),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_ranking_breakdown_mesin`
Expected: PASS (2 tests)

- [ ] **Step 5: Add schema entry**

Append to `schemaDefinitions()` after the `getProblemMesin` entry:

```php
            [
                'name' => 'getRankingBreakdownMesin',
                'description' => 'Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. Dipakai untuk pertanyaan seperti "mesin apa aja yang sering rusak".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getRankingBreakdownMesin tool"
```

---

## Task 4: `getRankingBreakdownDies` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `MaintenanceDiesDashboardService::compute(Carbon $from, Carbon $to, ?int $partId = null, ?string $customer = null): array` (existing service, already injected as `$this->diesDashboard` — returns one row per part/dies with keys `nama_proses`, `count`, `mttr_jam`, `mtbf_jam`, `availability_pct`, plus part identity confusingly stored under the mesin-dashboard-style keys `kode_mesin` (actually `part_no`) and `nama_mesin` (actually `part_name`) — verified by reading `app/Services/MaintenanceDiesDashboardService.php:60-63`, which builds each row as `['id_mesin' => $pr->id, 'kode_mesin' => $p?->part_no, 'nama_mesin' => $p?->part_name, ...]`. Use those exact keys as-is; do not rename them in this task's output mapping beyond what's shown in Step 3.)
- Produces: `ChatbotTools::getRankingBreakdownDies(string $bulanMulai, string $bulanAkhir): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php` (adjust the assertion key in this test to match whatever key Step 1 found — e.g. if the compute() row uses `part_no`, assert on `$result['ranking'][0]['part']` being populated from that field; do not leave the key unresolved):

```php
    public function test_get_ranking_breakdown_dies_sorts_by_count_desc(): void
    {
        $partLow = MasterPart::create(['part_no' => 'RD-01', 'part_name' => 'Low Part', 'customer' => 'Toyota']);
        $prosesLow = MasterPartProses::create([
            'id_part' => $partLow->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $partHigh = MasterPart::create(['part_no' => 'RD-02', 'part_name' => 'High Part', 'customer' => 'Honda']);
        $prosesHigh = MasterPartProses::create([
            'id_part' => $partHigh->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-R1', 'id_part_proses' => $prosesLow->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        foreach (['LKD-R2', 'LKD-R3'] as $i => $noLkd) {
            TransaksiBreakdownDies::create([
                'no_lkd' => $noLkd, 'id_part_proses' => $prosesHigh->id,
                'tanggal_breakdown' => '2026-07-0'.($i + 6), 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-0'.($i + 6), 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-0'.($i + 6), 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getRankingBreakdownDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['ranking'][0]['jumlah_breakdown']);
        $this->assertSame(1, $result['ranking'][1]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_dies_returns_error_when_no_breakdowns(): void
    {
        $part = MasterPart::create(['part_no' => 'RD-03', 'part_name' => 'Idle Part', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownDies('2026-07', '2026-07');

        $this->assertArrayHasKey('error', $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_ranking_breakdown_dies`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getRankingBreakdownDies()"

- [ ] **Step 3: Implement `getRankingBreakdownDies`**

Add to `app/Services/ChatbotTools.php`, directly after `getRankingBreakdownMesin`:

```php
    /** Ranking dies/part berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. */
    public function getRankingBreakdownDies(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = collect($this->diesDashboard->compute($from, $to))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada kejadian breakdown dies di periode {$bulanMulai} s/d {$bulanAkhir}."];
        }

        return [
            'bulan_mulai' => $bulanMulai,
            'bulan_akhir' => $bulanAkhir,
            'ranking'     => $rows->map(fn ($r) => [
                'part'             => $r['nama_mesin'],
                'part_no'          => $r['kode_mesin'],
                'nama_proses'      => $r['nama_proses'],
                'jumlah_breakdown' => $r['count'],
            ])->toArray(),
        ];
    }
```

Note: `$r['nama_mesin']`/`$r['kode_mesin']` are the actual part name/part_no despite the mesin-flavored key names — that's how `MaintenanceDiesDashboardService::compute()` names its row keys (see Interfaces above). Not a typo.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_ranking_breakdown_dies`
Expected: PASS (2 tests)

- [ ] **Step 5: Add schema entry**

Append to `schemaDefinitions()` after the `getRankingBreakdownMesin` entry:

```php
            [
                'name' => 'getRankingBreakdownDies',
                'description' => 'Ranking dies/part berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. Dipakai untuk pertanyaan seperti "dies apa aja yang sering rusak".',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getRankingBreakdownDies tool"
```

---

## Task 5: `getRekapDandori` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `MasterMesin` (existing model, same lookup pattern as Task 2), `TransaksiDandoriRincian` (existing model, fields `tanggal`, `id_mesin`, `id_losstime`, `actual_menit`, `over_menit`, relation `losstime` → `MasterLosstime` with field `jenis_losstime`), `ChatbotTools::monthSpan()` (Task 1)
- Produces: `ChatbotTools::getRekapDandori(string $bulanMulai, string $bulanAkhir, ?string $idMesin = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php` (add `use App\Models\MasterLosstime;` and `use App\Models\TransaksiDandoriRincian;` and `use App\Models\MasterShift;` if not already imported — `MasterShift` is already imported):

```php
    public function test_get_rekap_dandori_groups_by_jenis_losstime_sorted_by_over_menit(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'DD-01', 'nama_mesin' => 'Press D1', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DD-P1', 'part_name' => 'Dandori Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $lossSetting = MasterLosstime::create([
            'kode' => 'LS-SET', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);
        $lossTrial = MasterLosstime::create([
            'kode' => 'LS-TRI', 'jenis_losstime' => 'Trial QC', 'std_menit_default' => 15, 'std_menit_progressive' => 15,
        ]);

        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $lossSetting->id,
            'actual_menit' => 50, 'std_menit' => 30, 'over_menit' => 20,
        ]);
        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-06', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $lossTrial->id,
            'actual_menit' => 20, 'std_menit' => 15, 'over_menit' => 5,
        ]);

        $result = (new ChatbotTools())->getRekapDandori('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_kejadian']);
        $this->assertSame(25, $result['total_over_menit']);
        $this->assertSame('Setting Dies', $result['top_jenis_losstime'][0]['jenis_losstime']);
        $this->assertSame(20, $result['top_jenis_losstime'][0]['total_over_menit']);
        $this->assertSame('Trial QC', $result['top_jenis_losstime'][1]['jenis_losstime']);
    }

    public function test_get_rekap_dandori_filters_by_mesin(): void
    {
        $mesin1 = MasterMesin::create([
            'id_mesin' => 'DD-02', 'nama_mesin' => 'Press D2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesin2 = MasterMesin::create([
            'id_mesin' => 'DD-03', 'nama_mesin' => 'Press D3', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DD-P2', 'part_name' => 'Dandori Part 2', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-SET2', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);

        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin1->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 50, 'std_menit' => 30, 'over_menit' => 20,
        ]);
        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-06', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin2->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 60, 'std_menit' => 30, 'over_menit' => 30,
        ]);

        $result = (new ChatbotTools())->getRekapDandori('2026-07', '2026-07', 'DD-02');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_kejadian']);
        $this->assertSame(20, $result['total_over_menit']);
    }

    public function test_get_rekap_dandori_returns_error_for_unknown_mesin(): void
    {
        $result = (new ChatbotTools())->getRekapDandori('2026-07', '2026-07', 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_rekap_dandori_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getRekapDandori('2020-01', '2020-01');
        $this->assertArrayHasKey('error', $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_rekap_dandori`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getRekapDandori()"

- [ ] **Step 3: Implement `getRekapDandori`**

Add to `app/Services/ChatbotTools.php`, directly after `getRankingBreakdownDies`:

```php
    /** Rekap kejadian dandori dikelompokkan per jenis losstime dalam rentang bulan (opsional filter per mesin). */
    public function getRekapDandori(string $bulanMulai, string $bulanAkhir, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }

        $records = TransaksiDandoriRincian::with('losstime')
            ->whereBetween('tanggal', [$from->toDateString(), $to->toDateString()])
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->get();

        if ($records->isEmpty()) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            return ['error' => "Tidak ada data dandori{$label} di periode {$bulanMulai} s/d {$bulanAkhir}."];
        }

        $groups = $records->groupBy(fn ($r) => $r->losstime?->jenis_losstime ?? 'Tidak diketahui')
            ->map(fn ($rows, $jenis) => [
                'jenis_losstime'    => $jenis,
                'jumlah_kejadian'   => $rows->count(),
                'total_actual_menit'=> (int) $rows->sum('actual_menit'),
                'total_over_menit'  => (int) $rows->sum('over_menit'),
            ])
            ->sortByDesc('total_over_menit')
            ->values();

        return [
            'bulan_mulai'        => $bulanMulai,
            'bulan_akhir'        => $bulanAkhir,
            'total_kejadian'     => $records->count(),
            'total_over_menit'   => (int) $records->sum('over_menit'),
            'top_jenis_losstime' => $groups->toArray(),
        ];
    }
```

Add `use App\Models\TransaksiDandoriRincian;` to imports if not already present.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_rekap_dandori`
Expected: PASS (4 tests)

- [ ] **Step 5: Add schema entry**

Append to `schemaDefinitions()` after the `getRankingBreakdownDies` entry:

```php
            [
                'name' => 'getRekapDandori',
                'description' => 'Rekap kejadian dandori (ganti setup/part) dikelompokkan per jenis losstime, termasuk total menit over standar, dalam rentang bulan. Opsional filter per mesin.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getRekapDandori tool"
```

---

## Task 6: `getTotalProduksi` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `TransaksiProduksi` (existing model, fields `tanggal_produksi`, `qty_aktual`, `sph_aktual`), `ChatbotTools::monthSpan()` (Task 1)
- Produces: `ChatbotTools::getTotalProduksi(string $bulanMulai, string $bulanAkhir): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`:

```php
    public function test_get_total_produksi_sums_across_month_range(): void
    {
        $part = MasterPart::create(['part_no' => 'TP-01', 'part_name' => 'Total Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'TP-M1', 'nama_mesin' => 'Press TP', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $user = User::factory()->create();

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-05-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 3000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getTotalProduksi('2026-05', '2026-06');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(7000, $result['total_qty_aktual']);
    }

    public function test_get_total_produksi_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getTotalProduksi('2020-01', '2020-02');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_produksi_returns_error_for_invalid_month_format(): void
    {
        $result = (new ChatbotTools())->getTotalProduksi('bulan-salah', '2026-07');
        $this->assertArrayHasKey('error', $result);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_total_produksi`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getTotalProduksi()"

- [ ] **Step 3: Implement `getTotalProduksi`**

Add to `app/Services/ChatbotTools.php`, directly after `getRekapDandori`:

```php
    /** Total qty aktual & rata-rata SPH produksi untuk SEMUA proses dalam rentang bulan. */
    public function getTotalProduksi(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = TransaksiProduksi::whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])->get();

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada data produksi di periode {$bulanMulai} s/d {$bulanAkhir}."];
        }

        return [
            'bulan_mulai'      => $bulanMulai,
            'bulan_akhir'      => $bulanAkhir,
            'total_qty_aktual' => (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'   => (int) round($rows->avg(fn ($r) => $r->sph_aktual)),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_total_produksi`
Expected: PASS (3 tests)

- [ ] **Step 5: Add schema entry**

Append to `schemaDefinitions()` after the `getRekapDandori` entry:

```php
            [
                'name' => 'getTotalProduksi',
                'description' => 'Total qty aktual produksi (dalam Pcs) dan rata-rata SPH untuk SEMUA proses dalam rentang bulan (bisa multi-bulan). Beda dengan bandingkanProduksi yang membandingkan 2 periode terpisah — ini untuk total gabungan satu rentang.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS (all tests, including the 5 pre-existing ones and all added in Tasks 1-6)

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getTotalProduksi tool"
```

---

## Task 7: System prompt hardening

**Files:**
- Modify: `app/Services/GeminiChatbotService.php:13-18`
- Modify: `app/Services/GroqChatbotService.php:13-18`

**Interfaces:**
- Consumes: nothing new
- Produces: nothing new (text-only constant change, no new callable surface)

- [ ] **Step 1: Update `GeminiChatbotService::SYSTEM_PROMPT`**

In `app/Services/GeminiChatbotService.php`, replace:

```php
    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
```

with:

```php
    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Kalau tool mengembalikan daftar data mentah (misalnya list problem/penyebab/tindakan), rangkum HANYA pola yang benar-benar muncul berulang di data tersebut — jangan menyimpulkan penyebab teknis atau solusi yang tidak tertulis literal di data.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
```

- [ ] **Step 2: Update `GroqChatbotService::SYSTEM_PROMPT`**

Apply the exact same replacement (identical added line) in `app/Services/GroqChatbotService.php`.

- [ ] **Step 3: Verify both constants are character-for-character identical**

Run: `diff <(sed -n '/SYSTEM_PROMPT = <<</,/TEXT;/p' app/Services/GeminiChatbotService.php) <(sed -n '/SYSTEM_PROMPT = <<</,/TEXT;/p' app/Services/GroqChatbotService.php)`
Expected: no output (files identical in that range)

- [ ] **Step 4: Run existing provider test suites to confirm no regressions**

Run: `php artisan test --filter=GeminiChatbotServiceTest && php artisan test --filter=GroqChatbotServiceTest && php artisan test --filter=ChatbotControllerTest`
Expected: PASS (these tests don't assert on prompt text directly, only on tool-calling behavior — per spec, no new test needed for this change)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeminiChatbotService.php app/Services/GroqChatbotService.php
git commit -m "feat(chatbot): harden system prompt against over-inference on raw-list tool data"
```

---

## Task 8: Gemini model swap

**Files:**
- Modify: `config/services.php:40`
- Modify: `.env` (local dev, not committed)

**Interfaces:**
- Consumes: nothing new
- Produces: nothing new (config default value change only)

- [ ] **Step 1: Update the config default**

In `config/services.php`, change:

```php
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
```

to:

```php
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash-lite'),
```

- [ ] **Step 2: Update local `.env`**

In the project's `.env` file (not committed — verify with `git status .env` that it's gitignored before editing), set or add:

```
GEMINI_MODEL=gemini-2.5-flash-lite
```

- [ ] **Step 3: Clear config cache and verify**

Run: `php artisan config:clear && php artisan tinker --execute="echo config('services.gemini.model');"`
Expected output: `gemini-2.5-flash-lite`

- [ ] **Step 4: Run chatbot test suites to confirm no regressions**

Run: `php artisan test --filter=ChatbotControllerTest && php artisan test --filter=GeminiChatbotServiceTest`
Expected: PASS (these tests fake the HTTP call and don't assert on the model name in the URL, so behavior is unaffected)

- [ ] **Step 5: Commit**

```bash
git add config/services.php
git commit -m "feat(chatbot): default Gemini model to gemini-2.5-flash-lite"
```

Note: `.env` is not committed — remember to set `GEMINI_MODEL=gemini-2.5-flash-lite` on the hosting `.env` during deploy (see plan-level deploy note at the end of this document).

---

## Task 9: Delete chat session

**Files:**
- Modify: `app/Http/Controllers/ChatbotController.php`
- Modify: `routes/web.php:187-194`
- Modify: `resources/views/chatbot/index.blade.php`
- Test: `tests/Feature/ChatbotControllerTest.php`

**Interfaces:**
- Consumes: `ChatConversation` (existing model, already has `SoftDeletes`)
- Produces: `ChatbotController::destroy(Request $request, ChatConversation $conversation): JsonResponse`, route named `chatbot.conversations.destroy`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ChatbotControllerTest.php`:

```php
    public function test_admin_can_delete_own_conversation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin->id]);

        $response = $this->actingAs($admin)->deleteJson(route('chatbot.conversations.destroy', $conversation));

        $response->assertOk()->assertJson(['deleted' => true]);
        $this->assertSoftDeleted('chat_conversations', ['id' => $conversation->id]);
    }

    public function test_deleting_conversation_excludes_it_from_conversations_list(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin->id]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'halo']);

        $this->actingAs($admin)->deleteJson(route('chatbot.conversations.destroy', $conversation))->assertOk();

        $response = $this->actingAs($admin)->getJson(route('chatbot.conversations'));
        $this->assertSame([], $response->json());
    }

    public function test_cannot_delete_another_users_conversation(): void
    {
        $admin1 = User::factory()->create(['role' => 'admin']);
        $admin2 = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin1->id]);

        $response = $this->actingAs($admin2)->deleteJson(route('chatbot.conversations.destroy', $conversation));

        $response->assertNotFound();
        $this->assertDatabaseHas('chat_conversations', ['id' => $conversation->id, 'deleted_at' => null]);
    }

    public function test_staff_produksi_cannot_delete_conversation(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin->id]);

        $this->actingAs($staff)->deleteJson(route('chatbot.conversations.destroy', $conversation))->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_admin_can_delete_own_conversation`
Expected: FAIL — route `chatbot.conversations.destroy` not defined

- [ ] **Step 3: Add the route**

In `routes/web.php`, inside the existing `chatbot.` group (after the `conversations.show` route), add:

```php
        Route::delete('conversations/{conversation}', [\App\Http\Controllers\ChatbotController::class, 'destroy'])->name('conversations.destroy');
```

- [ ] **Step 4: Implement `ChatbotController::destroy`**

In `app/Http/Controllers/ChatbotController.php`, add after the `show` method:

```php
    public function destroy(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 404);

        $conversation->delete();

        return response()->json(['deleted' => true]);
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS (all tests, existing + 4 new)

- [ ] **Step 6: Add the delete button to the sidebar UI**

In `resources/views/chatbot/index.blade.php`, replace the conversation list template:

```blade
            <template x-for="c in conversations" :key="c.id">
                <button type="button" @click="selectConversation(c.id)"
                        :style="'width:100%;text-align:left;border:none;background:' + (c.id === activeId ? '#f3f4f6' : 'transparent') + ';padding:10px 12px;border-radius:6px;font-size:0.82rem;color:#111827;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis'"
                        x-text="c.title"></button>
            </template>
```

with:

```blade
            <template x-for="c in conversations" :key="c.id">
                <div :style="'display:flex;align-items:center;gap:4px;border-radius:6px;margin-bottom:2px;background:' + (c.id === activeId ? '#f3f4f6' : 'transparent')">
                    <button type="button" @click="selectConversation(c.id)"
                            style="flex:1;min-width:0;text-align:left;border:none;background:transparent;padding:10px 4px 10px 12px;font-size:0.82rem;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"
                            x-text="c.title"></button>
                    <button type="button" @click.stop="deleteConversation(c.id)"
                            title="Hapus percakapan"
                            style="flex-shrink:0;border:none;background:transparent;color:#9ca3af;padding:6px 10px 6px 4px">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </template>
```

- [ ] **Step 7: Add the `deleteConversation` method to the Alpine component**

In the `<script>` block of `resources/views/chatbot/index.blade.php`, add a new method to the object returned by `chatbotPage()`, after `selectConversation`:

```javascript
        async deleteConversation(id) {
            if (!confirm('Hapus percakapan ini?')) return;

            try {
                const res = await fetch(`/chatbot/conversations/${id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);

                this.conversations = this.conversations.filter(c => c.id !== id);

                if (this.activeId === id) {
                    this.newChat();
                }
            } catch (e) {
                // Silently handle fetch failure; item stays in the list
            }
        },
```

- [ ] **Step 8: Manually verify in browser**

Run: `php artisan serve` (or use existing dev server), log in as admin, visit `/chatbot`, create at least 2 conversations (send a message in each via "Chat Baru"), then click the trash icon on one. Confirm: the clicked conversation disappears from the sidebar immediately, and if it was the open one, the chat pane resets to the greeting message. Refresh the page and confirm the deleted conversation does not reappear.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/ChatbotController.php routes/web.php resources/views/chatbot/index.blade.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): add delete chat session"
```

---

## Final Verification

- [ ] **Run the full test suite**

Run: `php artisan test`
Expected: PASS, 0 failures

- [ ] **Manually smoke-test the new tools end-to-end via the chatbot UI**

Log in as admin, open `/chatbot`, and ask (adjust part/mesin codes and month to match real seeded/production data):
- "Problem apa yang sering muncul pada dies bulan Juli 2026?" → expect a summary grounded in `getProblemDies` raw data, not fabricated specifics
- Follow up: "Penyebabnya rata-rata karena apa?" → expect the model to reference the same tool result already in conversation history (no new tool call needed, or a natural re-use)
- "Mesin apa aja yang sering rusak bulan Juli 2026?" → expect `getRankingBreakdownMesin` results
- "Data produksi bulan Mei-Juni 2026 berapa Pcs?" → expect `getTotalProduksi` results
- Ask something with zero matching data (e.g. a future month with no records) → expect an honest "tidak ada data" answer, not an invented number

## Deploy Note (hosting, FTP-only, no SSH)

Modified across all tasks: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`, `app/Http/Controllers/ChatbotController.php`, `routes/web.php`, `resources/views/chatbot/index.blade.php`, `config/services.php`. **Requires a `.env` change on the host** (`GEMINI_MODEL=gemini-2.5-flash-lite`) — no migrations needed. Standard upload + the usual `public/diag.php` cache-clear step (OPcache + config cache) required for the config/PHP changes to take effect.
