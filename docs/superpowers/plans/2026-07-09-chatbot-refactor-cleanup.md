# Chatbot Refactor + Repo Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract duplicated logic in `ChatbotTools.php`, `GeminiChatbotService`, and `GroqChatbotService` into shared private helpers/traits, split `index.blade.php`'s inline CSS/JS into static files, and delete 5 confirmed-dead files — pure internal cleanup, zero behavior change, zero public API change.

**Architecture:** Every `ChatbotTools` public method is called reflectively (`$this->tools->{$name}(...$args)`) by both LLM providers, so its name/signature/return shape is frozen; only internals move into new `private` helpers. The two provider services get two new shared traits (`HasChatbotSystemPrompt`, retry-loop helper) so the identical logic they carry becomes structurally impossible to diverge. The Blade view splits into markup + a plain CSS file + a plain JS file (no bundler — this project deploys raw files via FTP).

**Tech Stack:** Laravel 13 / Eloquent, PHPUnit, PHP traits, Alpine.js, plain CSS/JS.

## Global Constraints

- Every `ChatbotTools` public method keeps its exact current name, parameter list, and return array shape
- `ChatbotProviderInterface::ask(ChatConversation $conversation, string $userMessage): ?string` unchanged on both providers
- `SYSTEM_PROMPT` must be character-for-character identical between `GeminiChatbotService` and `GroqChatbotService` after the trait extraction
- No behavior change anywhere — every existing test must pass unmodified. Baseline is 93/93 passing (the prior stale assertion in `test_get_rekap_dandori_returns_error_when_no_records` was already fixed in a separate commit before this plan starts)
- File deletions limited to exactly: `app/Services/DandoriService.php`, `resources/views/layouts/navigation.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/welcome.blade.php`, `public/clear-cache.php`
- `public/diag.php`, `public/fix-storage.php`, `ChatbotController::debugGroq()`, and the `chatbot/debug-groq` route are not touched by this plan

---

## Task 1: `truncatedResult()` helper — retrofit `getProblemDies`/`getProblemMesin`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php` (no new tests — regression only)

**Interfaces:**
- Consumes: nothing new
- Produces: `ChatbotTools::truncatedResult(int $total, array $rows, string $bulanMulaiLabel, string $bulanAkhirLabel, array $extra = []): array` (private), consumed by `getProblemDies`/`getProblemMesin` in this task

- [ ] **Step 1: Run the existing regression tests to capture current passing baseline**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS, 93/93 — record this baseline, it must be identical after this task's change

- [ ] **Step 2: Add the `truncatedResult()` private helper**

In `app/Services/ChatbotTools.php`, add directly after the `monthSpanOptional()` private method:

```php
    private function truncatedResult(int $total, array $rows, string $bulanMulaiLabel, string $bulanAkhirLabel, array $extra = []): array
    {
        $result = array_merge([
            'bulan_mulai'  => $bulanMulaiLabel,
            'bulan_akhir'  => $bulanAkhirLabel,
            'total_record' => $total,
            'data'         => $rows,
        ], $extra);

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }
```

- [ ] **Step 3: Retrofit `getProblemDies` to use the helper**

Replace this block in `getProblemDies` (currently the tail of the method):

```php
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

with:

```php
        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'part'     => $r->partProses?->part?->part_name ?? '—',
            'problem'  => $r->problem_dies,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        return $this->truncatedResult($total, $rows, $bulanMulai ?? 'semua periode', $bulanAkhir ?? 'semua periode');
    }
```

- [ ] **Step 4: Retrofit `getProblemMesin` to use the helper**

Replace the identical-shape tail block in `getProblemMesin`:

```php
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

with:

```php
        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'mesin'    => $r->mesin?->nama_mesin ?? '—',
            'problem'  => $r->problem_mesin,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        return $this->truncatedResult($total, $rows, $bulanMulai ?? 'semua periode', $bulanAkhir ?? 'semua periode');
    }
```

- [ ] **Step 5: Run tests to verify no regression**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: 93/93 passing, identical to Step 1's baseline

- [ ] **Step 6: Commit**

```bash
git add app/Services/ChatbotTools.php
git commit -m "refactor(chatbot): extract truncatedResult() helper from getProblemDies/getProblemMesin"
```

---

## Task 2: `groupByFrequency()` helper — retrofit `getTopProblemDies`/`getTopProblemMesin`

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php` (no new tests — regression only)

**Interfaces:**
- Consumes: nothing new
- Produces: `ChatbotTools::groupByFrequency(\Illuminate\Support\Collection $records, string $problemField, string $penyebabField, string $tindakanField): \Illuminate\Support\Collection` (private), consumed by `getTopProblemDies`/`getTopProblemMesin` in this task

- [ ] **Step 1: Run baseline tests**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: Same pass/fail count as Task 1's final state

- [ ] **Step 2: Add the `groupByFrequency()` private helper**

In `app/Services/ChatbotTools.php`, add directly after `truncatedResult()`:

```php
    private function groupByFrequency(\Illuminate\Support\Collection $records, string $problemField, string $penyebabField, string $tindakanField): \Illuminate\Support\Collection
    {
        $total = $records->count();

        return $records
            ->groupBy(fn ($r) => strtolower(trim($r->{$problemField})))
            ->map(function ($rows) use ($total, $problemField, $penyebabField, $tindakanField) {
                $latest = $rows->first();
                return [
                    'problem'         => trim($latest->{$problemField}),
                    'jumlah_kejadian' => $rows->count(),
                    'pct_dari_total'  => round($rows->count() / $total * 100, 2),
                    'contoh_penyebab' => $latest->{$penyebabField},
                    'contoh_tindakan' => $latest->{$tindakanField},
                ];
            })
            ->sortByDesc('jumlah_kejadian')
            ->values()
            ->take(10);
    }
```

- [ ] **Step 3: Retrofit `getTopProblemDies` to use the helper**

Replace this block:

```php
        $total = $records->count();

        $groups = $records
            ->groupBy(fn ($r) => strtolower(trim($r->problem_dies)))
            ->map(function ($rows) use ($total) {
                $latest = $rows->first();
                return [
                    'problem'         => trim($latest->problem_dies),
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

with:

```php
        $groups = $this->groupByFrequency($records, 'problem_dies', 'penyebab_breakdown', 'tindakan_perbaikan');

        return [
            'bulan_mulai'    => $bulanMulai ?? 'semua periode',
            'bulan_akhir'    => $bulanAkhir ?? 'semua periode',
            'total_kejadian' => $records->count(),
            'top_problem'    => $groups->toArray(),
        ];
    }
```

- [ ] **Step 4: Retrofit `getTopProblemMesin` to use the helper**

Replace the identical-shape block in `getTopProblemMesin`:

```php
        $total = $records->count();

        $groups = $records
            ->groupBy(fn ($r) => strtolower(trim($r->problem_mesin)))
            ->map(function ($rows) use ($total) {
                $latest = $rows->first();
                return [
                    'problem'         => trim($latest->problem_mesin),
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

with:

```php
        $groups = $this->groupByFrequency($records, 'problem_mesin', 'penyebab_breakdown', 'tindakan_perbaikan');

        return [
            'bulan_mulai'    => $bulanMulai ?? 'semua periode',
            'bulan_akhir'    => $bulanAkhir ?? 'semua periode',
            'total_kejadian' => $records->count(),
            'top_problem'    => $groups->toArray(),
        ];
    }
```

- [ ] **Step 5: Run tests to verify no regression**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: Identical pass/fail count to Step 1's baseline

- [ ] **Step 6: Commit**

```bash
git add app/Services/ChatbotTools.php
git commit -m "refactor(chatbot): extract groupByFrequency() helper from getTopProblemDies/getTopProblemMesin"
```

---

## Task 3: `findPartByNo()`/`findMesinByKode()` helpers — retrofit 7 call sites

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php` (no new tests — regression only)

**Interfaces:**
- Consumes: nothing new
- Produces: `ChatbotTools::findPartByNo(string $partNo): MasterPart|array` (private), `ChatbotTools::findMesinByKode(string $idMesin): MasterMesin|array` (private), consumed by 7 call sites in this task: `getMtbfMttrDies`, `getMtbfMttrMesin`, `getProblemDies`, `getProblemMesin`, `getRekapDandori`, `getTopProblemDies`, `getTopProblemMesin`

- [ ] **Step 1: Run baseline tests**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: Same pass/fail count as Task 2's final state

- [ ] **Step 2: Add the two lookup helpers**

In `app/Services/ChatbotTools.php`, add directly after `groupByFrequency()`:

```php
    private function findPartByNo(string $partNo): MasterPart|array
    {
        $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
        return $part ?? ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
    }

    private function findMesinByKode(string $idMesin): MasterMesin|array
    {
        $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
        return $mesin ?? ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
    }
```

- [ ] **Step 3: Retrofit `getMtbfMttrDies`**

Replace:
```php
        $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
        if (!$part) {
            return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
        }
```
with:
```php
        $part = $this->findPartByNo($partNo);
        if (is_array($part)) {
            return $part;
        }
```

- [ ] **Step 4: Retrofit `getMtbfMttrMesin`**

Replace:
```php
        $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
        if (!$mesin) {
            return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
        }
```
with:
```php
        $mesin = $this->findMesinByKode($idMesin);
        if (is_array($mesin)) {
            return $mesin;
        }
```

- [ ] **Step 5: Retrofit `getProblemDies`**

Replace (inside the `if ($partNo !== null)` block):
```php
        $prosesIds = null;
        if ($partNo !== null) {
            $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
            if (!$part) {
                return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }
```
with:
```php
        $prosesIds = null;
        if ($partNo !== null) {
            $part = $this->findPartByNo($partNo);
            if (is_array($part)) {
                return $part;
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }
```

- [ ] **Step 6: Retrofit `getProblemMesin`**

Replace (inside the `if ($idMesin !== null)` block):
```php
        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }
```
with:
```php
        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = $this->findMesinByKode($idMesin);
            if (is_array($mesin)) {
                return $mesin;
            }
            $mesinId = $mesin->id;
        }
```

- [ ] **Step 7: Retrofit `getRekapDandori`**

Replace (inside the `if ($idMesin !== null)` block):
```php
        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }
```
with:
```php
        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = $this->findMesinByKode($idMesin);
            if (is_array($mesin)) {
                return $mesin;
            }
            $mesinId = $mesin->id;
        }
```

- [ ] **Step 8: Retrofit `getTopProblemDies`**

Replace (inside the `if ($partNo !== null)` block):
```php
        $prosesIds = null;
        if ($partNo !== null) {
            $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
            if (!$part) {
                return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }
```
with:
```php
        $prosesIds = null;
        if ($partNo !== null) {
            $part = $this->findPartByNo($partNo);
            if (is_array($part)) {
                return $part;
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }
```

- [ ] **Step 9: Retrofit `getTopProblemMesin`**

Replace (inside the `if ($idMesin !== null)` block):
```php
        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }
```
with:
```php
        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = $this->findMesinByKode($idMesin);
            if (is_array($mesin)) {
                return $mesin;
            }
            $mesinId = $mesin->id;
        }
```

- [ ] **Step 10: Run tests to verify no regression**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: Identical pass/fail count to Step 1's baseline

- [ ] **Step 11: Verify no stray duplicated lookups remain**

Run: `grep -n "whereRaw('LOWER(TRIM(part_no))\|whereRaw('LOWER(TRIM(id_mesin))" app/Services/ChatbotTools.php`
Expected: exactly 2 matches — both inside `findPartByNo()`/`findMesinByKode()` themselves, zero matches anywhere else in the file

- [ ] **Step 12: Commit**

```bash
git add app/Services/ChatbotTools.php
git commit -m "refactor(chatbot): extract findPartByNo()/findMesinByKode() lookup helpers"
```

---

## Task 4: Split `schemaDefinitions()` into grouped private methods

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php` (no new tests — regression only, plus one new structural assertion, see Step 3)

**Interfaces:**
- Consumes: nothing new
- Produces: `ChatbotTools::mtbfMttrSchemas(): array`, `productionSchemas(): array`, `breakdownAggregateSchemas(): array`, `breakdownDetailSchemas(): array`, `dandoriSchemas(): array` (all private), consumed by `schemaDefinitions()`

- [ ] **Step 1: Run baseline tests**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: Same pass/fail count as Task 3's final state

- [ ] **Step 2: Split `schemaDefinitions()`**

Replace the entire current `schemaDefinitions(): array` method (the one big array literal, currently containing all 14 tool schema entries: `getMtbfMttrDies`, `getMtbfMttrMesin`, `getRekapNg`, `bandingkanProduksi`, `getTotalBreakdownDies`, `getTotalBreakdownMesin`, `getProblemDies`, `getProblemMesin`, `getRankingBreakdownMesin`, `getRankingBreakdownDies`, `getRekapDandori`, `getTotalProduksi`, `getTopProblemDies`, `getTopProblemMesin`) with:

```php
    public function schemaDefinitions(): array
    {
        return [
            ...$this->mtbfMttrSchemas(),
            ...$this->productionSchemas(),
            ...$this->breakdownAggregateSchemas(),
            ...$this->breakdownDetailSchemas(),
            ...$this->dandoriSchemas(),
        ];
    }

    private function mtbfMttrSchemas(): array
    {
        return [
            [
                'name' => 'getMtbfMttrDies',
                'description' => 'Ambil MTBF/MTTR untuk sebuah part/dies pada bulan tertentu, per proses.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'partNo' => ['type' => 'string', 'description' => 'Nomor part/dies, contoh: BT-091A-3'],
                        'bulan'  => ['type' => 'string', 'description' => 'Format YYYY-MM, contoh: 2026-07'],
                    ],
                    'required' => ['partNo', 'bulan'],
                ],
            ],
            [
                'name' => 'getMtbfMttrMesin',
                'description' => 'Ambil MTBF/MTTR untuk sebuah mesin pada bulan tertentu.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'idMesin' => ['type' => 'string', 'description' => 'Kode mesin, contoh: M-01'],
                        'bulan'   => ['type' => 'string', 'description' => 'Format YYYY-MM'],
                    ],
                    'required' => ['idMesin', 'bulan'],
                ],
            ],
        ];
    }

    private function productionSchemas(): array
    {
        return [
            [
                'name' => 'getRekapNg',
                'description' => 'Rekap total qty NG dan NG rate untuk sebuah nama proses. Periode opsional — kalau tidak diisi, pakai semua data.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'namaProses' => ['type' => 'string', 'description' => 'Nama proses, contoh: BENDING'],
                        'periode'    => ['type' => 'string', 'description' => 'Opsional. Format YYYY-MM. Kosongkan untuk semua data.'],
                    ],
                    'required' => ['namaProses'],
                ],
            ],
            [
                'name' => 'bandingkanProduksi',
                'description' => 'Bandingkan total qty aktual & SPH rata-rata produksi antara 2 bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periode1' => ['type' => 'string', 'description' => 'Format YYYY-MM, periode pertama'],
                        'periode2' => ['type' => 'string', 'description' => 'Format YYYY-MM, periode kedua'],
                    ],
                    'required' => ['periode1', 'periode2'],
                ],
            ],
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
        ];
    }

    private function breakdownAggregateSchemas(): array
    {
        return [
            [
                'name' => 'getTotalBreakdownDies',
                'description' => 'Total kejadian breakdown untuk SEMUA dies dalam rentang bulan tertentu (dipakai untuk pertanyaan luas/agregat, bukan per part spesifik).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
            [
                'name' => 'getTotalBreakdownMesin',
                'description' => 'Total kejadian breakdown untuk SEMUA mesin dalam rentang bulan tertentu (dipakai untuk pertanyaan luas/agregat, bukan per mesin spesifik).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
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
        ];
    }

    private function breakdownDetailSchemas(): array
    {
        return [
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
        ];
    }

    private function dandoriSchemas(): array
    {
        return [
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
        ];
    }
```

- [ ] **Step 3: Write a structural regression test**

Add to `tests/Unit/ChatbotToolsTest.php`, at the end of the class:

```php
    public function test_schema_definitions_still_returns_all_thirteen_tools_with_unique_names(): void
    {
        $names = collect((new ChatbotTools())->schemaDefinitions())->pluck('name');

        $this->assertCount(14, $names);
        $this->assertCount(14, $names->unique(), 'Schema split must not introduce a duplicate tool name across the grouped methods.');
        $this->assertTrue($names->contains('getMtbfMttrDies'));
        $this->assertTrue($names->contains('getRekapDandori'));
        $this->assertTrue($names->contains('getTopProblemMesin'));
    }
```

- [ ] **Step 4: Run test to verify it fails before the split, passes after**

Run: `php artisan test --filter=test_schema_definitions_still_returns_all_thirteen_tools_with_unique_names`
Expected: PASS (the split from Step 2 already happened before this test was added — this step just confirms the count and uniqueness hold true post-split; if it fails, an entry was dropped or duplicated during the copy-paste in Step 2, go back and fix)

- [ ] **Step 5: Run full ChatbotToolsTest suite**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: Identical pass/fail count to Task 3's final state, plus the 1 new passing test from Step 3

- [ ] **Step 6: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "refactor(chatbot): split schemaDefinitions() into grouped private methods"
```

---

## Task 5: `HasChatbotSystemPrompt` trait

**Files:**
- Create: `app/Services/Concerns/HasChatbotSystemPrompt.php`
- Modify: `app/Services/GeminiChatbotService.php`
- Modify: `app/Services/GroqChatbotService.php`
- Test: `tests/Unit/GeminiChatbotServiceTest.php`, `tests/Unit/GroqChatbotServiceTest.php` (no new tests — regression only)

**Interfaces:**
- Consumes: nothing new
- Produces: `App\Services\Concerns\HasChatbotSystemPrompt` trait providing `protected const SYSTEM_PROMPT`, used by both provider services

- [ ] **Step 1: Run baseline tests**

Run: `php artisan test --filter=GeminiChatbotServiceTest && php artisan test --filter=GroqChatbotServiceTest`
Expected: Both fully passing

- [ ] **Step 2: Create the trait**

Create `app/Services/Concerns/HasChatbotSystemPrompt.php`:

```php
<?php
namespace App\Services\Concerns;

trait HasChatbotSystemPrompt
{
    protected const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Kalau tool mengembalikan daftar data mentah (misalnya list problem/penyebab/tindakan), rangkum HANYA pola yang benar-benar muncul berulang di data tersebut — jangan menyimpulkan penyebab teknis atau solusi yang tidak tertulis literal di data.
        Kalau user tidak menyebutkan periode, JANGAN tanya balik ke user — langsung panggil tool tanpa parameter periode (otomatis pakai semua data). Dalam kasus ini JANGAN isi parameter bulan/periode (bulanMulai, bulanAkhir, periode, bulan) dengan tebakan atau tanggal hari ini — biarkan kosong sepenuhnya. Kalau user menyebut bulan, kirim SELALU dalam format YYYY-MM (contoh 2026-07). Untuk pertanyaan "X apa yang PALING SERING/PALING BANYAK muncul", gunakan tool ranking (getTopProblemDies, getTopProblemMesin, getRankingBreakdownDies, getRankingBreakdownMesin) — bukan tool daftar mentah (getProblemDies, getProblemMesin) — kecuali user secara eksplisit minta detail/daftar tiap kejadian.
        Jika tool mengembalikan field 'error' atau 'catatan', sampaikan isinya ke user dengan bahasa percakapan biasa. JANGAN PERNAH menyebut nama tool/fungsi (misal getRekapDandori), kata "tools", atau kata "error" ke user. Kalau isinya soal data tidak ada/belum tercatat, jawab santai bahwa datanya belum ada — bukan seolah sistem rusak.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
}
```

- [ ] **Step 3: Wire the trait into `GeminiChatbotService`**

In `app/Services/GeminiChatbotService.php`:
1. Add `use App\Services\Concerns\HasChatbotSystemPrompt;` to the imports
2. Add `use HasChatbotSystemPrompt;` as the first line inside the class body
3. Delete the local `private const SYSTEM_PROMPT = <<<TEXT ... TEXT;` block entirely (the trait now provides it)

The class becomes:
```php
<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use App\Services\Concerns\HasChatbotSystemPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GeminiChatbotService implements ChatbotProviderInterface
{
    use HasChatbotSystemPrompt;

    private const MAX_ITERATIONS = 5;

    public function __construct(private ChatbotTools $tools) {}

    // ... rest of the class (ask(), buildHistory(), callGemini()) unchanged ...
}
```

- [ ] **Step 4: Wire the trait into `GroqChatbotService`**

Apply the identical change from Step 3 to `app/Services/GroqChatbotService.php`.

- [ ] **Step 5: Verify both classes resolve to the identical constant text**

Run: `php artisan tinker --execute="echo (new ReflectionClass(App\Services\GeminiChatbotService::class))->getConstant('SYSTEM_PROMPT') === (new ReflectionClass(App\Services\GroqChatbotService::class))->getConstant('SYSTEM_PROMPT') ? 'IDENTICAL' : 'DIVERGED';"`
Expected output: `IDENTICAL`

- [ ] **Step 6: Run tests to verify no regression**

Run: `php artisan test --filter=GeminiChatbotServiceTest && php artisan test --filter=GroqChatbotServiceTest && php artisan test --filter=ChatbotControllerTest`
Expected: All fully passing, identical to Step 1's baseline

- [ ] **Step 7: Commit**

```bash
git add app/Services/Concerns/HasChatbotSystemPrompt.php app/Services/GeminiChatbotService.php app/Services/GroqChatbotService.php
git commit -m "refactor(chatbot): extract SYSTEM_PROMPT into shared HasChatbotSystemPrompt trait"
```

---

## Task 6: Shared retry-with-backoff helper

**Files:**
- Create: `app/Services/Concerns/HasRetryingHttpCall.php`
- Modify: `app/Services/GeminiChatbotService.php`
- Modify: `app/Services/GroqChatbotService.php`
- Test: `tests/Unit/GeminiChatbotServiceTest.php`, `tests/Unit/GroqChatbotServiceTest.php` (no new tests — regression only)

**Interfaces:**
- Consumes: nothing new
- Produces: `App\Services\Concerns\HasRetryingHttpCall` trait providing `protected function withRetry(callable $makeRequest, string $providerName): ?array`, used by both provider services' `callGemini()`/`callGroq()`

- [ ] **Step 1: Run baseline tests**

Run: `php artisan test --filter=GeminiChatbotServiceTest && php artisan test --filter=GroqChatbotServiceTest`
Expected: Both fully passing (Task 5's final state)

- [ ] **Step 2: Create the trait**

Create `app/Services/Concerns/HasRetryingHttpCall.php`:

```php
<?php
namespace App\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HasRetryingHttpCall
{
    protected function withRetry(callable $makeRequest, string $providerName): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $makeRequest();

                if ($response->successful()) {
                    return $response->json();
                }

                if ($attempt === 1 && in_array($response->status(), [429, 500, 502, 503], true)) {
                    usleep(900_000);
                    continue;
                }

                Log::warning("{$providerName} API non-2xx response", [
                    'status' => $response->status(),
                    'body'   => Str::limit($response->body(), 500),
                ]);
                return null;
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    usleep(900_000);
                    continue;
                }
                Log::warning("{$providerName} API call failed", ['message' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }
}
```

- [ ] **Step 3: Wire the trait into `GeminiChatbotService` and simplify `callGemini()`**

In `app/Services/GeminiChatbotService.php`:
1. Add `use App\Services\Concerns\HasRetryingHttpCall;` to the imports
2. Add `use HasRetryingHttpCall;` on the same trait-use line as `HasChatbotSystemPrompt` (comma-separated): `use HasChatbotSystemPrompt, HasRetryingHttpCall;`
3. Remove the now-unused `use Illuminate\Support\Str;` import if `callGemini()` no longer references `Str` directly after this change (it won't — `Str::limit` moves into the trait)
4. Replace the entire `callGemini()` method body:

```php
    private function callGemini(array $contents): ?array
    {
        $model = config('services.gemini.model');
        $key   = config('services.gemini.key');
        $url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}";
        $body  = [
            'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
            'contents'           => $contents,
            'tools'              => [['functionDeclarations' => $this->tools->schemaDefinitions()]],
        ];

        return $this->withRetry(fn () => Http::timeout(15)->post($url, $body), 'Gemini');
    }
```

- [ ] **Step 4: Wire the trait into `GroqChatbotService` and simplify `callGroq()`**

In `app/Services/GroqChatbotService.php`, apply the same import/trait-use changes as Step 3, then replace `callGroq()`'s body:

```php
    private function callGroq(array $messages): ?array
    {
        $model = config('services.groq.model');
        $key   = config('services.groq.key');
        $body  = [
            'model'    => $model,
            'messages' => $messages,
            'tools'    => $this->toolSchema(),
        ];

        return $this->withRetry(
            fn () => Http::timeout(15)->withToken($key)->post('https://api.groq.com/openai/v1/chat/completions', $body),
            'Groq'
        );
    }
```

- [ ] **Step 5: Run tests to verify no regression**

Run: `php artisan test --filter=GeminiChatbotServiceTest && php artisan test --filter=GroqChatbotServiceTest && php artisan test --filter=ChatbotControllerTest`
Expected: All fully passing, including the existing 429-then-fallback test coverage from `ChatbotControllerTest` (`test_falls_back_to_groq_when_gemini_fails` and similar) — the retry loop's observable behavior is unchanged, only its code location moved

- [ ] **Step 6: Run the full suite once**

Run: `php artisan test`
Expected: 93/93 passing, identical to the baseline before this task

- [ ] **Step 7: Commit**

```bash
git add app/Services/Concerns/HasRetryingHttpCall.php app/Services/GeminiChatbotService.php app/Services/GroqChatbotService.php
git commit -m "refactor(chatbot): extract retry-with-backoff loop into shared HasRetryingHttpCall trait"
```

---

## Task 7: Split `index.blade.php` CSS/JS into static files

**Files:**
- Create: `public/css/chatbot.css`
- Create: `public/js/chatbot.js`
- Modify: `resources/views/chatbot/index.blade.php`

**Interfaces:**
- Consumes: nothing new
- Produces: nothing new (pure file split, same rendered output)

- [ ] **Step 1: Read the current file to capture the exact CSS and JS blocks**

Run: `sed -n '5,122p' resources/views/chatbot/index.blade.php` to capture the `<style>...</style>` inner content, and `sed -n '208,365p' resources/views/chatbot/index.blade.php` to capture the `<script>...</script>` inner content (line numbers approximate — confirm against the actual current file before copying, since Tasks 1-6 didn't touch this file but line numbers should be verified fresh)

- [ ] **Step 2: Create `public/css/chatbot.css`**

Create the file containing exactly the CSS rules currently between `<style>` and `</style>` in `resources/views/chatbot/index.blade.php` (the `.cb-wrap` through the final `@media (max-width:767px)` block covering `.cb-wrap`, `.cb-side`, `.cb-row`) — copy verbatim, no modifications, no selector renaming.

- [ ] **Step 3: Create `public/js/chatbot.js`**

Create the file containing exactly the `function chatbotPage() { ... }` block currently inside `<script>...</script>` — copy verbatim, no modifications to any method body, property, or logic.

- [ ] **Step 4: Modify `resources/views/chatbot/index.blade.php`**

Replace the `@push('styles')<style>...</style>@endpush` block with:
```blade
@push('styles')
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v={{ filemtime(public_path('css/chatbot.css')) }}">
@endpush
```

Replace the `<script>function chatbotPage() { ... }</script>` block (at the end of the file, before `@endsection`) with:
```blade
<script src="{{ asset('js/chatbot.js') }}?v={{ filemtime(public_path('js/chatbot.js')) }}"></script>
```

The `x-data="chatbotPage()" x-init="init()"` markup and all HTML structure in `@section('content') ... @endsection` stays exactly as-is — only the `<style>` and `<script>` blocks are replaced with the `<link>`/`<script src>` tags.

- [ ] **Step 5: Verify the view compiles**

Run: `php artisan view:clear && php artisan view:cache`
Expected: Exit code 0, no errors

- [ ] **Step 6: Manually verify in browser**

Start the local dev server, log in as admin, visit `/chatbot`. Confirm: page looks visually identical to before (sidebar, chat bubbles, chip row, input bar all styled the same), sending a message still works, clicking a suggestion chip still works, browser DevTools Network tab shows `chatbot.css` and `chatbot.js` loading with 200 status (not 404).

- [ ] **Step 7: Commit**

```bash
git add public/css/chatbot.css public/js/chatbot.js resources/views/chatbot/index.blade.php
git commit -m "refactor(chatbot): split inline CSS/JS out of index.blade.php into static files"
```

---

## Task 8: Delete confirmed-dead files

**Files:**
- Delete: `app/Services/DandoriService.php`
- Delete: `resources/views/layouts/navigation.blade.php`
- Delete: `resources/views/dashboard.blade.php`
- Delete: `resources/views/welcome.blade.php`
- Delete: `public/clear-cache.php`

**Interfaces:**
- Consumes: nothing
- Produces: nothing (pure deletion)

- [ ] **Step 1: Re-verify zero references immediately before deleting (state may have shifted since the audit)**

Run each of these and confirm no output (or only the file's own declaration line, which won't exist after grep since we're searching for usages, not declarations):
```bash
grep -rn "DandoriService" --include="*.php" app/ routes/ tests/ | grep -v "app/Services/DandoriService.php"
grep -rn "navigation" --include="*.blade.php" resources/views/
grep -rn "view('dashboard')\|view(\"dashboard\")" --include="*.php" app/ routes/
grep -rn "view('welcome')\|view(\"welcome\")" --include="*.php" app/ routes/
grep -rn "clear-cache" --include="*.php" app/ routes/ public/ | grep -v "public/clear-cache.php"
```
Expected: all five commands produce no output. If any produces output, STOP — do not delete that file, escalate to the human (the audit may be stale).

- [ ] **Step 2: Delete the 5 files**

```bash
git rm app/Services/DandoriService.php
git rm resources/views/layouts/navigation.blade.php
git rm resources/views/dashboard.blade.php
git rm resources/views/welcome.blade.php
git rm public/clear-cache.php
```

- [ ] **Step 3: Run the full test suite**

Run: `php artisan test`
Expected: 93/93 passing, identical to the baseline before this task — confirms the deletions broke nothing

- [ ] **Step 4: Verify the app still boots and routes still resolve**

Run: `php artisan route:list --name=dashboard` and `php artisan route:list --name=chatbot`
Expected: Both commands still list their routes without error (route definitions don't reference the deleted view files directly — `dashboard`/`/` routes redirect, never call `view()`)

- [ ] **Step 5: Commit**

```bash
git commit -m "chore(cleanup): remove 5 dead files with zero references (audit-confirmed)"
```

---

## Final Verification

- [ ] **Run the full test suite**

Run: `php artisan test`
Expected: 93/93 passing plus the 1 new structural test from Task 4 (94/94 total) — no new failures introduced across all 8 tasks

- [ ] **Manually smoke-test the chatbot end-to-end**

Log in as admin, open `/chatbot`, ask a question that exercises a tool-calling round-trip (e.g. "problem dies apa yang paling sering muncul?"), confirm the reply still comes back grounded and correct, confirm the UI still renders and behaves identically to before this refactor (this plan changed zero user-facing behavior, so any visible difference is a regression to investigate before merging)

## Deploy Note (hosting, FTP-only, no SSH)

New files: `app/Services/Concerns/HasChatbotSystemPrompt.php`, `app/Services/Concerns/HasRetryingHttpCall.php`, `public/css/chatbot.css`, `public/js/chatbot.js`. Modified: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`, `resources/views/chatbot/index.blade.php`. Deleted (must also be deleted from the hosting filesystem via FTP, not just git — FTP deploy doesn't prune removed files automatically): `app/Services/DandoriService.php`, `resources/views/layouts/navigation.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/welcome.blade.php`, `public/clear-cache.php`. No migrations, no `.env` changes. Standard upload + `public/diag.php` cache-clear step.
