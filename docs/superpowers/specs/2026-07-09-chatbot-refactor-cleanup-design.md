# Chatbot Code Refactor + Repo Cleanup — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** The chatbot subsystem grew across several rounds of feature work (14 tools, 2 LLM providers, a redesigned UI) without a matching cleanup pass. `ChatbotTools.php` is 767 lines with several exact-duplicate code blocks; `GeminiChatbotService`/`GroqChatbotService` still carry the long-flagged duplicated `SYSTEM_PROMPT` plus a newly-duplicated retry-loop; `resources/views/chatbot/index.blade.php` mixes 200+ lines of CSS, a full Alpine component, and markup in one file. Separately, a repo-wide audit found 5 dead files with zero references anywhere. This spec extracts the duplication into shared, private, non-breaking helpers, splits the view into markup/CSS/JS, and removes the confirmed-dead files — pure internal cleanup, no behavior change, no public API change.

**Context:** Every `ChatbotTools` method is called reflectively — `$this->tools->{$name}(...$args)` from both `GeminiChatbotService::ask()` and `GroqChatbotService::ask()`, driven by LLM function-calling. This means the **public method names, signatures, and return shapes of every `ChatbotTools` method are load-bearing and must not change** — refactoring here means extracting duplicated *internals* into new `private` helper methods that the existing public methods call, not restructuring the public surface. Same constraint applies to `ChatbotProviderInterface::ask()` on the two provider services — external callers (`ChatbotController`) depend on that exact signature.

The project has no JS/CSS build pipeline (`package.json`/`vite.config.js` were removed; deploy is FTP-only, uploading raw files as-is per [[project_hosting_deploy]]), so the Blade split uses plain `<link>`/`<script src>` tags against static files in `public/`, not an asset bundler.

**Architecture:** Three independent refactor targets (each ships and tests separately) plus one deletion pass:

1. **`ChatbotTools.php` internals** — extract 3 duplicated patterns into private helpers: raw-list truncation (`getProblemDies`/`getProblemMesin`), Pareto grouping (`getTopProblemDies`/`getTopProblemMesin`), and master-data lookup (`MasterPart`/`MasterMesin`, used 8 times). Also split the 180-line `schemaDefinitions()` array literal into smaller private methods grouped by tool family, concatenated at the end.
2. **`GeminiChatbotService` + `GroqChatbotService`** — extract the identical `SYSTEM_PROMPT` into a shared trait (`HasChatbotSystemPrompt`, previously planned but never done), and extract the identical retry-with-backoff loop structure into a shared trait method that takes a request closure.
3. **`resources/views/chatbot/index.blade.php` split** — CSS to `public/css/chatbot.css`, Alpine component to `public/js/chatbot.js`, loaded via versioned `<link>`/`<script src>` tags (cache-busting via `filemtime()` query param, since there's no build hash).
4. **Dead file removal** — delete the 5 files the audit confirmed have zero references anywhere in the codebase. The 3 temporary/debug files (`public/diag.php`, `public/fix-storage.php`, `ChatbotController::debugGroq()` + its route) are explicitly **out of scope** — they're still needed for the ongoing Gemini/Groq quota incident and get removed in a separate follow-up once that's confirmed resolved.

**Tech Stack:** Laravel 13 / Eloquent, PHPUnit, PHP traits, Alpine.js, plain CSS/JS (no bundler).

## Global Constraints

- Every `ChatbotTools` public method keeps its exact current name, parameter list (including nullable defaults), and return array shape — reflective dispatch (`$this->tools->{$name}(...$args)`) depends on this
- `ChatbotProviderInterface::ask(ChatConversation $conversation, string $userMessage): ?string` is unchanged on both provider classes
- `GeminiChatbotService::SYSTEM_PROMPT` and `GroqChatbotService::SYSTEM_PROMPT` must remain **character-for-character identical** after moving into the shared trait — this is the exact drift risk the trait exists to close structurally
- No behavior change anywhere in this spec — every existing passing test must still pass unmodified in assertions (only `use`/setup lines may change if a trait extraction requires it), and no new test should be needed to prove *new* behavior since there isn't any
- File deletions limited to the 5 audit-confirmed-dead files: `app/Services/DandoriService.php`, `resources/views/layouts/navigation.blade.php`, `resources/views/dashboard.blade.php`, `resources/views/welcome.blade.php`, `public/clear-cache.php`. Nothing else gets deleted in this spec.
- `public/diag.php`, `public/fix-storage.php`, `ChatbotController::debugGroq()`, and the `chatbot/debug-groq` route are **not touched** by this spec

---

## Part 1 — `ChatbotTools.php` internals

### 1a. Raw-list truncation helper

Both `getProblemDies` (lines 192-209) and `getProblemMesin` (lines 243-260) build a result array from a paginated query the identical way: map rows into a fixed key shape, then attach a `note` if `$total > 150`. Extract:

```php
private function truncatedResult(int $total, array $rows, string $bulanMulai, ?string $bulanAkhirLabel, array $extra = []): array
{
    $result = array_merge([
        'bulan_mulai'  => $bulanMulai,
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

`getProblemDies`/`getProblemMesin` call this after building `$rows`, passing their own `$bulanMulai ?? 'semua periode'` / `$bulanAkhir ?? 'semua periode'`. (`$extra` stays empty for both current callers — included so the helper doesn't need to change shape if a third raw-list tool is added later with one more field.)

### 1b. Pareto grouping helper

`getTopProblemDies` (lines 439-453) and `getTopProblemMesin` (lines 494-508) run the identical group→map→sort→take(10) pipeline, differing only in which 3 column names they read (`problem_dies`/`problem_mesin` etc.). Extract:

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

Both callers pass their already-fetched `$records` collection and their 3 column names; each keeps its own `$total = $records->count()` call before invoking this (needed for their own empty-check), or the helper recomputes it internally — recomputing internally (as shown) is simpler and avoids a 4th parameter, at the cost of counting the collection twice across the two call sites combined (negligible, in-memory collection).

### 1c. Master-data lookup helpers

The pattern `Model::whereRaw('LOWER(TRIM(col)) = ?', [strtolower(trim($input))])->first()` + not-found error appears for `MasterPart` (3 call sites: `getMtbfMttrDies`, `getProblemDies`, `getTopProblemDies`) and `MasterMesin` (5 call sites: `getMtbfMttrMesin`, `getProblemMesin`, `getRankingBreakdownMesin`... — actually `getRankingBreakdownMesin`/`getRankingBreakdownDies` don't do a lookup, so it's `getMtbfMttrMesin`, `getProblemMesin`, `getRekapDandori`, `getTopProblemMesin` = 4 sites). Extract:

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

Callers do:
```php
$part = $this->findPartByNo($partNo);
if (is_array($part)) {
    return $part; // already the error shape
}
```
This union-return style (`Model|array`) matches how the codebase already treats `['error' => ...]` as a sentinel — no new abstraction concept introduced, just centralizing the 4-line pattern that was copy-pasted 7 times total.

### 1d. `schemaDefinitions()` split

Split the single 178-line array literal (lines 591-765) into private methods grouped by the tool families already visible in the code's own ordering, concatenated in `schemaDefinitions()`:

```php
public function schemaDefinitions(): array
{
    return [
        ...$this->mtbfMttrSchemas(),      // getMtbfMttrDies, getMtbfMttrMesin
        ...$this->productionSchemas(),     // getRekapNg, bandingkanProduksi, getTotalProduksi
        ...$this->breakdownAggregateSchemas(), // getTotalBreakdownDies/Mesin, getRankingBreakdownDies/Mesin
        ...$this->breakdownDetailSchemas(),    // getProblemDies/Mesin, getTopProblemDies/Mesin
        ...$this->dandoriSchemas(),        // getRekapDandori
    ];
}
```

Each private method returns the exact same array entries currently inline — this is a pure mechanical split (copy-paste into named methods), not a rewrite of any schema content. The exact grouping boundaries (which schema goes in which private method) are locked in the implementation plan so there's no ambiguity for whoever implements it.

## Part 2 — `GeminiChatbotService` + `GroqChatbotService`

### 2a. Shared system prompt trait

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

Both services `use HasChatbotSystemPrompt;` and delete their own local `private const SYSTEM_PROMPT` declaration. PHP resolves the constant through the trait at compile time, so both classes are now provably using the exact same text — a future edit to one can no longer silently diverge from the other.

### 2b. Shared retry-with-backoff helper

`callGemini()` and `callGroq()` currently run an identical 2-attempt retry structure (try request → success returns → transient status (429/500/502/503) on attempt 1 sleeps 900ms and retries → otherwise logs and returns null → catch Throwable does the same sleep-then-retry-then-log-null dance). Only the actual HTTP call and its URL/headers/body differ. Extract into the same trait (or a second trait, `HasRetryingHttpCall`, composed alongside `HasChatbotSystemPrompt` — implementation plan decides which is cleaner once it sees both bodies side by side, both are valid, prefer one trait file per concern per existing project convention of small focused files):

```php
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
```

`callGemini()` becomes:
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
`callGroq()` follows the identical pattern with its own URL/headers/body. This is the same refactor discipline as the system-prompt trait — the retry *logic* becomes structurally impossible to diverge, only the request construction stays provider-specific (as it must).

## Part 3 — `resources/views/chatbot/index.blade.php` split

- `public/css/chatbot.css` — every rule currently inside the `<style>` block (`.cb-wrap` through the final `@media` block), verbatim
- `public/js/chatbot.js` — the `function chatbotPage() { ... }` block, verbatim, minus the surrounding `<script>` tags
- `resources/views/chatbot/index.blade.php` shrinks to: the `@push('styles')` block replaced with a `<link>` tag, the `x-data="chatbotPage()"` markup unchanged, and the closing `<script>` block replaced with a `<script src>` tag

Cache-busting (FTP deploy means browsers can cache stale CSS/JS after an update with no build-hash filename to force a refetch):
```blade
@push('styles')
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v={{ filemtime(public_path('css/chatbot.css')) }}">
@endpush
```
```blade
<script src="{{ asset('js/chatbot.js') }}?v={{ filemtime(public_path('js/chatbot.js')) }}"></script>
```
`filemtime()` changes automatically every time the file is re-uploaded via FTP, forcing browsers to fetch the new version — no manual version bump needed, and no build step required.

## Part 4 — Dead file removal

Delete, with a `git rm`, verifying `php artisan test` still passes after each (should be a no-op since these are unreferenced):
- `app/Services/DandoriService.php`
- `resources/views/layouts/navigation.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/welcome.blade.php`
- `public/clear-cache.php` (0 bytes, already empty)

## Error Handling

No new error paths — this spec doesn't add or remove any user-facing behavior. Every extracted helper preserves the exact error-shape contract (`['error' => ...]` or the model instance) its call sites already depend on.

## Testing

- `tests/Unit/ChatbotToolsTest.php`: **no new tests needed** — this is pure internal refactor with identical public behavior. The existing 40+ tests covering all 14 tool methods are the regression net; every one must still pass unmodified (aside from the one pre-existing unrelated failure already flagged: `test_get_rekap_dandori_returns_error_when_no_records`, which is a stale assertion from a separate uncommitted behavior change, not something this spec touches or is responsible for fixing)
- `tests/Unit/GeminiChatbotServiceTest.php` / `tests/Unit/GroqChatbotServiceTest.php`: no new tests needed for the trait extractions — existing tests exercise `ask()`'s observable behavior (tool dispatch, retry-on-429 is not currently tested explicitly but isn't changing in behavior, only in code location) and must all still pass
- No test harness exists for Blade/Alpine/CSS in this codebase (consistent with how prior UI-only tasks were handled) — verify the split view compiles via `php artisan view:clear && php artisan view:cache`, and note that a manual browser smoke-test (chat still renders, sends, suggestion chips still work) is recommended before/after deploy since this cannot be automated here
- After the Part 4 deletions, run the full suite once to confirm nothing broke (expected: identical pass/fail count to before, since the audit already verified zero references)

## Deploy Note (hosting, FTP-only, no SSH)

New files: `app/Services/Concerns/HasChatbotSystemPrompt.php`, one or two retry-helper trait file(s), `public/css/chatbot.css`, `public/js/chatbot.js`. Modified: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`, `resources/views/chatbot/index.blade.php`. Deleted: the 5 dead files listed in Part 4 (delete from the hosting filesystem too, not just git — FTP deploy doesn't auto-prune removed files). Standard upload + `public/diag.php` cache-clear step.
