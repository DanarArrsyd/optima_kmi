# Chatbot Pareto Ranking + No-Period-Required + Suggestion Chips Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the chatbot from asking the user for a period before answering frequency questions ("problem apa yang paling sering muncul"), and give it two new tools that compute an accurate all-time Pareto ranking instead of relying on a 150-row raw-list cap. Add clickable suggestion chips to the chat UI.

**Architecture:** A new `monthSpanOptional()` helper on `ChatbotTools` makes period parsing nullable; 6 existing tools switch to it and apply their date filter conditionally. Two new tools (`getTopProblemDies`, `getTopProblemMesin`) group records in PHP by normalized problem text and return count + percentage per group — an aggregation, not a raw dump, so it stays accurate and cheap at any data volume. System prompt gets one instruction telling the model never to ask for a period. The chat UI gets a row of suggestion chips visible only in the empty/greeting state.

**Tech Stack:** Laravel 13 / Eloquent, PHPUnit (`RefreshDatabase`), Alpine.js.

## Global Constraints

- `monthSpanOptional(?string $bulanMulai, ?string $bulanAkhir): array` returns `[?Carbon $from, ?Carbon $to, ?string $error]`: both null → `[null, null, null]` (no filter); exactly one null → error `"Kalau isi salah satu periode, isi juga yang satunya."`; both given → delegates to existing `monthSpan()` unchanged
- Exactly 6 existing tools change to optional period: `getProblemDies`, `getProblemMesin`, `getRankingBreakdownMesin`, `getRankingBreakdownDies`, `getRekapDandori`, `getTotalProduksi`. `getTotalBreakdownDies`, `getTotalBreakdownMesin`, `getMtbfMttrDies`, `getMtbfMttrMesin`, `getRekapNg`, `bandingkanProduksi` are **out of scope** — unchanged, still mandatory period
- `getTopProblemDies`/`getTopProblemMesin` group by `LOWER(TRIM(problem_dies))` / `LOWER(TRIM(problem_mesin))` — near-duplicate phrasing with real wording differences still counts as separate groups (accepted limitation, same as `getRekapDandori`)
- Top 10 groups sorted by count descending, each with `jumlah_kejadian`, `pct_dari_total` (rounded to 2 decimals), representative `problem` (original casing, most recent occurrence), `contoh_penyebab`/`contoh_tindakan` (also most recent occurrence)
- Suggestion chips render only while `messages.length === 1` (greeting-only state), no persistent chip row

---

## Task 1: `monthSpanOptional()` helper + retrofit `getProblemDies`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: existing `ChatbotTools::monthSpan(string $bulanMulai, string $bulanAkhir): array` (unchanged, returns `[Carbon $from, Carbon $to, ?string $error]` when both args are valid)
- Produces: `ChatbotTools::monthSpanOptional(?string $bulanMulai, ?string $bulanAkhir): array` (returns `[?Carbon $from, ?Carbon $to, ?string $error]`), consumed by Tasks 2-8; `ChatbotTools::getProblemDies(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $partNo = null): array` (signature change from the previous plan's `string $bulanMulai, string $bulanAkhir`)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, right after the existing `test_get_problem_dies_truncates_at_150_rows_with_note` test:

```php
    public function test_get_problem_dies_with_no_period_returns_all_time_data(): void
    {
        $part = MasterPart::create(['part_no' => 'PDN-01', 'part_name' => 'NoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-NP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Old problem', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-NP2', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Recent problem', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_record']);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame('semua periode', $result['bulan_akhir']);
    }

    public function test_get_problem_dies_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getProblemDies('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_problem_dies_with_both_periods_still_behaves_as_before(): void
    {
        $part = MasterPart::create(['part_no' => 'PDN-02', 'part_name' => 'BothPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-BP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('2026-07', $result['bulan_mulai']);
        $this->assertSame('2026-07', $result['bulan_akhir']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_problem_dies_with`
Expected: FAIL — `getProblemDies()` still requires `string $bulanMulai, string $bulanAkhir` (calling with 0 args or a single `null` arg is a TypeError under the current signature)

- [ ] **Step 3: Add `monthSpanOptional()` and retrofit `getProblemDies`**

In `app/Services/ChatbotTools.php`, add the new private helper directly after the existing `monthSpan()` method:

```php
    private function monthSpanOptional(?string $bulanMulai, ?string $bulanAkhir): array
    {
        if ($bulanMulai === null && $bulanAkhir === null) {
            return [null, null, null];
        }
        if ($bulanMulai === null || $bulanAkhir === null) {
            return [null, null, 'Kalau isi salah satu periode, isi juga yang satunya.'];
        }

        return $this->monthSpan($bulanMulai, $bulanAkhir);
    }
```

Replace the existing `getProblemDies` method entirely with:

```php
    /** Daftar mentah problem/penyebab/tindakan breakdown dies dalam rentang bulan opsional (opsional filter per part). */
    public function getProblemDies(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $partNo = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
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
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($prosesIds !== null, fn ($q) => $q->whereIn('id_part_proses', $prosesIds))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            $label = $partNo ? " untuk part \"{$partNo}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data breakdown dies{$label}{$periodLabel}."];
        }

        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'part'     => $r->partProses?->part?->part_name ?? '—',
            'problem'  => $r->problem_dies,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        $result = [
            'bulan_mulai'  => $bulanMulai ?? 'semua periode',
            'bulan_akhir'  => $bulanAkhir ?? 'semua periode',
            'total_record' => $total,
            'data'         => $rows,
        ];

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_problem_dies`
Expected: PASS (all `getProblemDies` tests, old + 3 new)

- [ ] **Step 5: Update the schema entry**

In `app/Services/ChatbotTools.php`, inside `schemaDefinitions()`, replace the `getProblemDies` entry's `parameters` block:

```php
            [
                'name' => 'getProblemDies',
                'description' => 'Ambil daftar mentah problem, penyebab, dan tindakan perbaikan breakdown dies dalam rentang bulan. Dipakai untuk pertanyaan root-cause seperti "problem apa yang sering muncul", "penyebabnya apa", "perbaikan yang cocok apa" — kamu HARUS merangkum sendiri dari data yang dikembalikan, jangan menambah pola yang tidak ada di teks. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'partNo' => ['type' => 'string', 'description' => 'Opsional, nomor part/dies spesifik. Kosongkan untuk semua dies.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS (all tests, existing + new)

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): make getProblemDies period optional, default all-time"
```

---

## Task 2: Retrofit `getProblemMesin`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1)
- Produces: `ChatbotTools::getProblemMesin(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, right after the `test_get_problem_mesin_returns_error_when_no_records` test:

```php
    public function test_get_problem_mesin_with_no_period_returns_all_time_data(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'PMN-01', 'nama_mesin' => 'Press NoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-NP1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Old problem', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemMesin();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
        $this->assertSame('semua periode', $result['bulan_mulai']);
    }

    public function test_get_problem_mesin_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getProblemMesin(null, '2026-07');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_problem_mesin_with`
Expected: FAIL — `getProblemMesin()` still requires both `string` args

- [ ] **Step 3: Retrofit `getProblemMesin`**

Replace the existing `getProblemMesin` method in `app/Services/ChatbotTools.php` entirely with:

```php
    /** Daftar mentah problem/penyebab/tindakan breakdown mesin dalam rentang bulan opsional (opsional filter per mesin). */
    public function getProblemMesin(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
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
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data breakdown mesin{$label}{$periodLabel}."];
        }

        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'mesin'    => $r->mesin?->nama_mesin ?? '—',
            'problem'  => $r->problem_mesin,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        $result = [
            'bulan_mulai'  => $bulanMulai ?? 'semua periode',
            'bulan_akhir'  => $bulanAkhir ?? 'semua periode',
            'total_record' => $total,
            'data'         => $rows,
        ];

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_problem_mesin`
Expected: PASS

- [ ] **Step 5: Update the schema entry**

Replace the `getProblemMesin` entry's `parameters` block in `schemaDefinitions()`:

```php
            [
                'name' => 'getProblemMesin',
                'description' => 'Sama seperti getProblemDies tapi untuk mesin — problem/penyebab/tindakan breakdown mesin. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): make getProblemMesin period optional, default all-time"
```

---

## Task 3: Retrofit `getRankingBreakdownMesin`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1), `MaintenanceDashboardService::compute(Carbon $from, Carbon $to, ?int $mesinId = null): array` (existing, already injected as `$this->mesinDashboard`)
- Produces: `ChatbotTools::getRankingBreakdownMesin(?string $bulanMulai = null, ?string $bulanAkhir = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, right after `test_get_ranking_breakdown_mesin_returns_error_when_no_breakdowns`:

```php
    public function test_get_ranking_breakdown_mesin_with_no_period_returns_all_time_data(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'RMN-01', 'nama_mesin' => 'Press RankNoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-RNP1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownMesin();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(1, $result['ranking'][0]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_mesin_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getRankingBreakdownMesin('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_ranking_breakdown_mesin_with`
Expected: FAIL — `getRankingBreakdownMesin()` still requires both `string` args

- [ ] **Step 3: Retrofit `getRankingBreakdownMesin`**

Replace the existing method in `app/Services/ChatbotTools.php` entirely with:

```php
    /** Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan opsional. */
    public function getRankingBreakdownMesin(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $computeFrom = $from ?? Carbon::createFromDate(2000, 1, 1)->startOfDay();
        $computeTo   = $to ?? Carbon::now()->endOfDay();

        $rows = collect($this->mesinDashboard->compute($computeFrom, $computeTo))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada kejadian breakdown mesin{$periodLabel}."];
        }

        return [
            'bulan_mulai' => $bulanMulai ?? 'semua periode',
            'bulan_akhir' => $bulanAkhir ?? 'semua periode',
            'ranking'     => $rows->map(fn ($r) => [
                'mesin'            => $r['nama_mesin'],
                'kode_mesin'       => $r['kode_mesin'],
                'jumlah_breakdown' => $r['count'],
                'availability_pct' => $r['availability_pct'],
            ])->toArray(),
        ];
    }
```

Note: `MaintenanceDashboardService::compute()` requires non-null `Carbon $from`/`$to` (no optional-date support of its own), so when the caller omits both periods this method substitutes a wide bound (`2000-01-01` to now) to approximate "all time" without changing `compute()`'s signature — no other tool in this codebase has data older than 2020, so this bound is safe in practice and keeps the change scoped to `ChatbotTools`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_ranking_breakdown_mesin`
Expected: PASS

- [ ] **Step 5: Update the schema entry**

Replace the `getRankingBreakdownMesin` entry's `parameters` block:

```php
            [
                'name' => 'getRankingBreakdownMesin',
                'description' => 'Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. Dipakai untuk pertanyaan seperti "mesin apa aja yang sering rusak". Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): make getRankingBreakdownMesin period optional, default all-time"
```

---

## Task 4: Retrofit `getRankingBreakdownDies`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1), `MaintenanceDiesDashboardService::compute(Carbon $from, Carbon $to, ?int $partId = null, ?string $customer = null): array` (existing, already injected as `$this->diesDashboard`)
- Produces: `ChatbotTools::getRankingBreakdownDies(?string $bulanMulai = null, ?string $bulanAkhir = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, right after `test_get_ranking_breakdown_dies_returns_error_when_no_breakdowns`:

```php
    public function test_get_ranking_breakdown_dies_with_no_period_returns_all_time_data(): void
    {
        $part = MasterPart::create(['part_no' => 'RDN-01', 'part_name' => 'RankNoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-RNP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(1, $result['ranking'][0]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_dies_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getRankingBreakdownDies(null, '2026-07');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_ranking_breakdown_dies_with`
Expected: FAIL — `getRankingBreakdownDies()` still requires both `string` args

- [ ] **Step 3: Retrofit `getRankingBreakdownDies`**

Replace the existing method in `app/Services/ChatbotTools.php` entirely with:

```php
    /** Ranking dies/part berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan opsional. */
    public function getRankingBreakdownDies(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $computeFrom = $from ?? Carbon::createFromDate(2000, 1, 1)->startOfDay();
        $computeTo   = $to ?? Carbon::now()->endOfDay();

        $rows = collect($this->diesDashboard->compute($computeFrom, $computeTo))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada kejadian breakdown dies{$periodLabel}."];
        }

        return [
            'bulan_mulai' => $bulanMulai ?? 'semua periode',
            'bulan_akhir' => $bulanAkhir ?? 'semua periode',
            'ranking'     => $rows->map(fn ($r) => [
                'part'             => $r['nama_mesin'],
                'part_no'          => $r['kode_mesin'],
                'nama_proses'      => $r['nama_proses'],
                'jumlah_breakdown' => $r['count'],
            ])->toArray(),
        ];
    }
```

Same "wide bound approximates all-time" note as Task 3 applies here — `MaintenanceDiesDashboardService::compute()` also requires non-null `Carbon` bounds.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_ranking_breakdown_dies`
Expected: PASS

- [ ] **Step 5: Update the schema entry**

Replace the `getRankingBreakdownDies` entry's `parameters` block:

```php
            [
                'name' => 'getRankingBreakdownDies',
                'description' => 'Ranking dies/part berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. Dipakai untuk pertanyaan seperti "dies apa aja yang sering rusak". Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): make getRankingBreakdownDies period optional, default all-time"
```

---

## Task 5: Retrofit `getRekapDandori`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1)
- Produces: `ChatbotTools::getRekapDandori(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, right after `test_get_rekap_dandori_returns_error_when_no_records`:

```php
    public function test_get_rekap_dandori_with_no_period_returns_all_time_data(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'DDN-01', 'nama_mesin' => 'Press DandoriNoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DDN-P1', 'part_name' => 'Dandori NoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-DDN', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);
        $user = User::factory()->create();
        TransaksiDandoriRincian::create([
            'tanggal' => '2020-01-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 50, 'std_menit' => 30, 'over_menit' => 20, 'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getRekapDandori();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(1, $result['total_kejadian']);
    }

    public function test_get_rekap_dandori_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getRekapDandori('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_rekap_dandori_with`
Expected: FAIL — `getRekapDandori()` still requires both `string` args

- [ ] **Step 3: Retrofit `getRekapDandori`**

Replace the existing method in `app/Services/ChatbotTools.php` entirely with:

```php
    /** Rekap kejadian dandori dikelompokkan per jenis losstime dalam rentang bulan opsional (opsional filter per mesin). */
    public function getRekapDandori(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
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
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->get();

        if ($records->isEmpty()) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data dandori{$label}{$periodLabel}."];
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
            'bulan_mulai'        => $bulanMulai ?? 'semua periode',
            'bulan_akhir'        => $bulanAkhir ?? 'semua periode',
            'total_kejadian'     => $records->count(),
            'total_over_menit'   => (int) $records->sum('over_menit'),
            'top_jenis_losstime' => $groups->toArray(),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_rekap_dandori`
Expected: PASS

- [ ] **Step 5: Update the schema entry**

Replace the `getRekapDandori` entry's `parameters` block:

```php
            [
                'name' => 'getRekapDandori',
                'description' => 'Rekap kejadian dandori (ganti setup/part) dikelompokkan per jenis losstime, termasuk total menit over standar, dalam rentang bulan. Opsional filter per mesin. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): make getRekapDandori period optional, default all-time"
```

---

## Task 6: Retrofit `getTotalProduksi`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1)
- Produces: `ChatbotTools::getTotalProduksi(?string $bulanMulai = null, ?string $bulanAkhir = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, right after `test_get_total_produksi_returns_error_for_invalid_month_format`:

```php
    public function test_get_total_produksi_with_no_period_returns_all_time_data(): void
    {
        $part = MasterPart::create(['part_no' => 'TPN-01', 'part_name' => 'TotalNoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'TPN-M1', 'nama_mesin' => 'Press TotalNoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $user = User::factory()->create();
        TransaksiProduksi::create([
            'tanggal_produksi' => '2020-05-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 2000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getTotalProduksi();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(2000, $result['total_qty_aktual']);
    }

    public function test_get_total_produksi_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getTotalProduksi('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_total_produksi_with`
Expected: FAIL — `getTotalProduksi()` still requires both `string` args

- [ ] **Step 3: Retrofit `getTotalProduksi`**

Replace the existing method in `app/Services/ChatbotTools.php` entirely with:

```php
    /** Total qty aktual & rata-rata SPH produksi untuk SEMUA proses dalam rentang bulan opsional. */
    public function getTotalProduksi(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = TransaksiProduksi::when($from && $to, fn ($q) => $q->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()]))
            ->get();

        if ($rows->isEmpty()) {
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data produksi{$periodLabel}."];
        }

        return [
            'bulan_mulai'      => $bulanMulai ?? 'semua periode',
            'bulan_akhir'      => $bulanAkhir ?? 'semua periode',
            'total_qty_aktual' => (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'   => (int) round($rows->avg(fn ($r) => $r->sph_aktual)),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_total_produksi`
Expected: PASS

- [ ] **Step 5: Update the schema entry**

Replace the `getTotalProduksi` entry's `parameters` block:

```php
            [
                'name' => 'getTotalProduksi',
                'description' => 'Total qty aktual produksi (dalam Pcs) dan rata-rata SPH untuk SEMUA proses dalam rentang bulan (bisa multi-bulan). Beda dengan bandingkanProduksi yang membandingkan 2 periode terpisah — ini untuk total gabungan satu rentang. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): make getTotalProduksi period optional, default all-time"
```

---

## Task 7: `getTopProblemDies` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1)
- Produces: `ChatbotTools::getTopProblemDies(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $partNo = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, at the end of the class (right before the final closing `}`):

```php
    public function test_get_top_problem_dies_groups_by_normalized_problem_text(): void
    {
        $part = MasterPart::create(['part_no' => 'TOP-D1', 'part_name' => 'Top Problem Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Motor overheat', 'penyebab_breakdown' => 'Bearing aus lama', 'tindakan_perbaikan' => 'Ganti bearing lama',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOP2', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => '  motor overheat  ', 'penyebab_breakdown' => 'Bearing aus baru', 'tindakan_perbaikan' => 'Ganti bearing baru',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOP3', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Baut kendor', 'penyebab_breakdown' => 'Vibrasi tinggi', 'tindakan_perbaikan' => 'Kencangkan baut',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(3, $result['total_kejadian']);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertCount(2, $result['top_problem']);
        $this->assertSame(2, $result['top_problem'][0]['jumlah_kejadian']);
        $this->assertSame('motor overheat', $result['top_problem'][0]['problem']);
        $this->assertSame('Bearing aus baru', $result['top_problem'][0]['contoh_penyebab']);
        $this->assertEqualsWithDelta(66.67, $result['top_problem'][0]['pct_dari_total'], 0.01);
        $this->assertSame(1, $result['top_problem'][1]['jumlah_kejadian']);
        $this->assertSame('Baut kendor', $result['top_problem'][1]['problem']);
    }

    public function test_get_top_problem_dies_filters_by_part(): void
    {
        $part1 = MasterPart::create(['part_no' => 'TOP-D2', 'part_name' => 'Filter Part A', 'customer' => 'Toyota']);
        $proses1 = MasterPartProses::create([
            'id_part' => $part1->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $part2 = MasterPart::create(['part_no' => 'TOP-D3', 'part_name' => 'Filter Part B', 'customer' => 'Honda']);
        $proses2 = MasterPartProses::create([
            'id_part' => $part2->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOPF1', 'id_part_proses' => $proses1->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Problem A', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOPF2', 'id_part_proses' => $proses2->id,
            'tanggal_breakdown' => '2026-07-02', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-02', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-02', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Problem B', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemDies(null, null, 'TOP-D2');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_kejadian']);
        $this->assertSame('Problem A', $result['top_problem'][0]['problem']);
    }

    public function test_get_top_problem_dies_returns_error_for_unknown_part(): void
    {
        $result = (new ChatbotTools())->getTopProblemDies(null, null, 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_dies_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getTopProblemDies();
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_dies_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getTopProblemDies('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_top_problem_dies_caps_at_ten_groups(): void
    {
        $part = MasterPart::create(['part_no' => 'TOP-D4', 'part_name' => 'Cap Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        for ($i = 1; $i <= 12; $i++) {
            TransaksiBreakdownDies::create([
                'no_lkd' => "LKD-CAP{$i}", 'id_part_proses' => $proses->id,
                'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => "Problem distinct {$i}", 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getTopProblemDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(10, $result['top_problem']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_top_problem_dies`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getTopProblemDies()"

- [ ] **Step 3: Implement `getTopProblemDies`**

Add to `app/Services/ChatbotTools.php`, directly after `getTotalProduksi`:

```php
    /** Ranking problem dies PALING SERING muncul (Pareto), dikelompokkan per teks problem, dalam rentang bulan opsional. */
    public function getTopProblemDies(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $partNo = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
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

        $records = TransaksiBreakdownDies::query()
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($prosesIds !== null, fn ($q) => $q->whereIn('id_part_proses', $prosesIds))
            ->orderByDesc('tanggal_breakdown')
            ->get(['problem_dies', 'penyebab_breakdown', 'tindakan_perbaikan', 'tanggal_breakdown']);

        if ($records->isEmpty()) {
            $label = $partNo ? " untuk part \"{$partNo}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data breakdown dies{$label}{$periodLabel}."];
        }

        $total = $records->count();

        $groups = $records
            ->groupBy(fn ($r) => strtolower(trim($r->problem_dies)))
            ->map(function ($rows) use ($total) {
                $latest = $rows->first();
                return [
                    'problem'         => $latest->problem_dies,
                    'jumlah_kejadian' => $rows->count(),
                    'pct_dari_total'  => round($rows->count() / $total * 100, 2),
                    'contoh_penyebab' => $latest->penyebab_breakdown,
                    'contoh_tindakan' => $latest->tindakan_perbaikan,
                ];
            })
            ->sortByDesc('jumlah_kejadian')
            ->values()
            ->take(10);

        return [
            'bulan_mulai'    => $bulanMulai ?? 'semua periode',
            'bulan_akhir'    => $bulanAkhir ?? 'semua periode',
            'total_kejadian' => $total,
            'top_problem'    => $groups->toArray(),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_top_problem_dies`
Expected: PASS (7 tests)

- [ ] **Step 5: Add the schema entry**

Append to `schemaDefinitions()` in `app/Services/ChatbotTools.php`, after the `getTotalProduksi` entry:

```php
            [
                'name' => 'getTopProblemDies',
                'description' => 'Ranking problem dies PALING SERING muncul (Pareto), dikelompokkan per teks problem, dalam rentang bulan opsional. Dipakai untuk pertanyaan seperti "problem dies apa yang paling sering muncul" — beda dengan getProblemDies yang mengembalikan daftar mentah per kejadian, tool ini sudah meranking berdasarkan frekuensi. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'partNo' => ['type' => 'string', 'description' => 'Opsional, nomor part/dies spesifik. Kosongkan untuk semua dies.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getTopProblemDies tool for Pareto ranking"
```

---

## Task 8: `getTopProblemMesin` tool

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `ChatbotTools::monthSpanOptional()` (Task 1)
- Produces: `ChatbotTools::getTopProblemMesin(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array`

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/ChatbotToolsTest.php`, at the end of the class (right before the final closing `}`, after Task 7's tests):

```php
    public function test_get_top_problem_mesin_groups_by_normalized_problem_text(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'TOP-M1', 'nama_mesin' => 'Top Problem Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOP1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Panel error', 'penyebab_breakdown' => 'Korsleting lama', 'tindakan_perbaikan' => 'Ganti panel lama',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOP2', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => '  panel error  ', 'penyebab_breakdown' => 'Korsleting baru', 'tindakan_perbaikan' => 'Ganti panel baru',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOP3', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Motor macet', 'penyebab_breakdown' => 'Oli habis', 'tindakan_perbaikan' => 'Isi oli',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemMesin();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(3, $result['total_kejadian']);
        $this->assertCount(2, $result['top_problem']);
        $this->assertSame(2, $result['top_problem'][0]['jumlah_kejadian']);
        $this->assertSame('panel error', $result['top_problem'][0]['problem']);
        $this->assertSame('Korsleting baru', $result['top_problem'][0]['contoh_penyebab']);
    }

    public function test_get_top_problem_mesin_filters_by_mesin(): void
    {
        $mesin1 = MasterMesin::create([
            'id_mesin' => 'TOP-M2', 'nama_mesin' => 'Filter Mesin A', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesin2 = MasterMesin::create([
            'id_mesin' => 'TOP-M3', 'nama_mesin' => 'Filter Mesin B', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOPF1', 'id_mesin' => $mesin1->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Problem A', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOPF2', 'id_mesin' => $mesin2->id,
            'tanggal_breakdown' => '2026-07-02', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-02', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-02', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Problem B', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemMesin(null, null, 'TOP-M2');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_kejadian']);
        $this->assertSame('Problem A', $result['top_problem'][0]['problem']);
    }

    public function test_get_top_problem_mesin_returns_error_for_unknown_mesin(): void
    {
        $result = (new ChatbotTools())->getTopProblemMesin(null, null, 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_mesin_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getTopProblemMesin();
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_mesin_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getTopProblemMesin('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_get_top_problem_mesin`
Expected: FAIL with "Call to undefined method App\Services\ChatbotTools::getTopProblemMesin()"

- [ ] **Step 3: Implement `getTopProblemMesin`**

Add to `app/Services/ChatbotTools.php`, directly after `getTopProblemDies`:

```php
    /** Ranking problem mesin PALING SERING muncul (Pareto), dikelompokkan per teks problem, dalam rentang bulan opsional. */
    public function getTopProblemMesin(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
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

        $records = TransaksiBreakdownMesin::query()
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown')
            ->get(['problem_mesin', 'penyebab_breakdown', 'tindakan_perbaikan', 'tanggal_breakdown']);

        if ($records->isEmpty()) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data breakdown mesin{$label}{$periodLabel}."];
        }

        $total = $records->count();

        $groups = $records
            ->groupBy(fn ($r) => strtolower(trim($r->problem_mesin)))
            ->map(function ($rows) use ($total) {
                $latest = $rows->first();
                return [
                    'problem'         => $latest->problem_mesin,
                    'jumlah_kejadian' => $rows->count(),
                    'pct_dari_total'  => round($rows->count() / $total * 100, 2),
                    'contoh_penyebab' => $latest->penyebab_breakdown,
                    'contoh_tindakan' => $latest->tindakan_perbaikan,
                ];
            })
            ->sortByDesc('jumlah_kejadian')
            ->values()
            ->take(10);

        return [
            'bulan_mulai'    => $bulanMulai ?? 'semua periode',
            'bulan_akhir'    => $bulanAkhir ?? 'semua periode',
            'total_kejadian' => $total,
            'top_problem'    => $groups->toArray(),
        ];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=test_get_top_problem_mesin`
Expected: PASS (6 tests)

- [ ] **Step 5: Add the schema entry**

Append to `schemaDefinitions()` in `app/Services/ChatbotTools.php`, after the `getTopProblemDies` entry:

```php
            [
                'name' => 'getTopProblemMesin',
                'description' => 'Sama seperti getTopProblemDies tapi untuk mesin — ranking problem mesin paling sering muncul (Pareto).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => [],
                ],
            ],
```

- [ ] **Step 6: Run full test suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS (all tests, existing + all added in Tasks 1-8)

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add getTopProblemMesin tool for Pareto ranking"
```

---

## Task 9: System prompt update

**Files:**
- Modify: `app/Services/GeminiChatbotService.php`
- Modify: `app/Services/GroqChatbotService.php`

**Interfaces:**
- Consumes: nothing new
- Produces: nothing new (text-only constant change)

- [ ] **Step 1: Update `GeminiChatbotService::SYSTEM_PROMPT`**

In `app/Services/GeminiChatbotService.php`, the current constant (after the previous round's edit) reads:

```php
    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Kalau tool mengembalikan daftar data mentah (misalnya list problem/penyebab/tindakan), rangkum HANYA pola yang benar-benar muncul berulang di data tersebut — jangan menyimpulkan penyebab teknis atau solusi yang tidak tertulis literal di data.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
```

Replace it with:

```php
    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Kalau tool mengembalikan daftar data mentah (misalnya list problem/penyebab/tindakan), rangkum HANYA pola yang benar-benar muncul berulang di data tersebut — jangan menyimpulkan penyebab teknis atau solusi yang tidak tertulis literal di data.
        Kalau user tidak menyebutkan periode, JANGAN tanya balik ke user — langsung panggil tool tanpa parameter periode (otomatis pakai semua data). Untuk pertanyaan "X apa yang PALING SERING/PALING BANYAK muncul", gunakan tool ranking (getTopProblemDies, getTopProblemMesin, getRankingBreakdownDies, getRankingBreakdownMesin) — bukan tool daftar mentah (getProblemDies, getProblemMesin) — kecuali user secara eksplisit minta detail/daftar tiap kejadian.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
```

- [ ] **Step 2: Update `GroqChatbotService::SYSTEM_PROMPT`**

Apply the exact same replacement (identical added line, inserted at the same relative position) in `app/Services/GroqChatbotService.php`.

- [ ] **Step 3: Verify both constants are character-for-character identical**

Run: `diff <(sed -n '/SYSTEM_PROMPT = <<</,/TEXT;/p' app/Services/GeminiChatbotService.php) <(sed -n '/SYSTEM_PROMPT = <<</,/TEXT;/p' app/Services/GroqChatbotService.php)`
Expected: no output (files identical in that range)

- [ ] **Step 4: Run existing provider and controller test suites to confirm no regressions**

Run: `php artisan test --filter=GeminiChatbotServiceTest && php artisan test --filter=GroqChatbotServiceTest && php artisan test --filter=ChatbotControllerTest`
Expected: PASS (these tests don't assert on prompt text directly)

- [ ] **Step 5: Commit**

```bash
git add app/Services/GeminiChatbotService.php app/Services/GroqChatbotService.php
git commit -m "feat(chatbot): instruct system prompt to skip period questions and prefer ranking tools"
```

---

## Task 10: Suggestion chips UI

**Files:**
- Modify: `resources/views/chatbot/index.blade.php`

**Interfaces:**
- Consumes: existing `send()` Alpine method (reads `this.input`, pushes user message, calls the send-message endpoint)
- Produces: `askSuggested(question: string)` Alpine method, `suggestedQuestions` array property

- [ ] **Step 1: Add the chip row to the template**

In `resources/views/chatbot/index.blade.php`, find the chat panel's messages area (the `<div style="flex:1;min-height:0;overflow-y:auto;...">` block containing the `x-for="(m, i) in messages"` template) and the `<form @submit.prevent="send()" ...>` that follows it. Insert the chip row between them:

```blade
        <div x-show="messages.length === 1" style="display:flex;flex-wrap:wrap;gap:8px;padding:0 16px 12px">
            <template x-for="q in suggestedQuestions" :key="q">
                <button type="button" @click="askSuggested(q)"
                        style="border:1px solid #d1d5db;background:#fff;color:#111827;border-radius:16px;padding:6px 14px;font-size:0.8rem;cursor:pointer"
                        x-text="q"></button>
            </template>
        </div>

        <form @submit.prevent="send()" style="display:flex;gap:8px;padding:12px;border-top:1px solid #e5e7eb">
```

(The `<form ...>` line already exists in the file — this step just adds the new `<div>` immediately before it, and leaves the rest of the form unchanged.)

- [ ] **Step 2: Add `suggestedQuestions` and `askSuggested()` to the Alpine component**

In the same file's `<script>` block, inside the object returned by `chatbotPage()`, add the new property and method. Insert them right after the existing `messages: [],` property:

```javascript
        messages: [],
        suggestedQuestions: [
            'Problem dies apa yang paling sering muncul?',
            'Mesin apa yang sering rusak?',
            'Data produksi bulan ini berapa Pcs?',
            'Rekap NG proses apa yang paling tinggi?',
        ],
```

(`messages: [],` already exists — this step adds `suggestedQuestions` right after it, keeping `messages: [],` itself unchanged.)

Then add the new method right after the existing `newChat()` method:

```javascript
        askSuggested(question) {
            this.input = question;
            this.send();
        },
```

- [ ] **Step 3: Verify the Blade template compiles**

Run: `php artisan view:clear && php artisan view:cache`
Expected: no errors (exit code 0), confirming the added Blade/Alpine markup has no syntax errors

- [ ] **Step 4: Manually verify in browser**

Run: `php artisan serve` (or use the existing dev server), log in as admin, visit `/chatbot`. Confirm:
- On page load (empty/new chat state), 4 suggestion chips appear below the greeting message
- Clicking a chip immediately sends that question (no extra click needed) and the chip row disappears once the reply arrives
- Starting a new chat (clicking "Chat Baru") brings the chip row back
- Switching to an existing conversation with prior messages does NOT show the chip row

- [ ] **Step 5: Commit**

```bash
git add resources/views/chatbot/index.blade.php
git commit -m "feat(chatbot): add suggestion chips for common questions"
```

---

## Final Verification

- [ ] **Run the full test suite**

Run: `php artisan test`
Expected: PASS, 0 failures

- [ ] **Manually smoke-test the fixed UX end-to-end via the chatbot UI**

Log in as admin, open `/chatbot`, and ask (adjust to match real seeded/production data):
- "Problem dies apa yang paling sering muncul?" → expect an immediate answer (no "untuk periode kapan?" follow-up question), grounded in `getTopProblemDies` with a clear top-problem + percentage
- "Mesin apa yang sering rusak?" → same, via `getRankingBreakdownMesin` with no period given
- Click each of the 4 suggestion chips from a fresh "Chat Baru" state → each fires immediately and returns a grounded answer
- Ask a narrative follow-up ("penyebabnya apa untuk problem itu?") → expect the model to either reuse conversation history or call `getProblemDies`/`getProblemMesin` for detail, not `getTopProblemDies`

## Deploy Note (hosting, FTP-only, no SSH)

Modified across all tasks: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`, `resources/views/chatbot/index.blade.php`. No new files, no migrations, no `.env` changes. Standard upload + `public/diag.php` cache-clear step (OPcache + view cache) required for the PHP/Blade changes to take effect.
