# AI Chatbot — Pareto Ranking + No-Period-Required + Suggestion Chips — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Observed in production — asking "problem apa yang sering muncul pada dies?" made the chatbot ask the user for a period before answering, and separately the raw-list tools have no way to give an accurate all-time frequency ranking (Pareto) since they cap at 150 rows ordered by recency. Fix both: make period optional (default all-time, no clarifying question) across the 8 date-scoped tools added in the previous round, and add two new tools that compute frequency ranking in SQL/PHP aggregation rather than relying on an LLM reading a capped raw list. Also add clickable suggestion chips to the chat UI so users get fast answers without typing.

**Context:** [2026-07-09-chatbot-tool-expansion-design.md](2026-07-09-chatbot-tool-expansion-design.md) added 6 tools (`getProblemDies`, `getProblemMesin`, `getRankingBreakdownMesin`, `getRankingBreakdownDies`, `getRekapDandori`, `getTotalProduksi`), all requiring `bulanMulai`/`bulanAkhir` as mandatory `YYYY-MM` strings. That's why the model asked for a period — it was following a `required` JSON-Schema field, not a system-prompt gap. Separately, `getProblemDies`/`getProblemMesin` cap raw output at 150 rows (most recent first) specifically so the LLM can safely read every returned row without over-inferring — but that cap means "problem paling sering" over years of data would only reflect the newest 150 incidents, not a true frequency ranking.

**Architecture:** Two changes, orthogonal to each other:
1. A new `monthSpanOptional()` helper and 8 tool signature changes make period nullable, defaulting to "no date filter" (all-time) when omitted. The system prompt gets one added instruction: never ask the user for a period, call the tool without it.
2. Two new tools (`getTopProblemDies`, `getTopProblemMesin`) answer frequency-ranking questions ("problem apa yang PALING SERING") by grouping in PHP (`Collection::groupBy`, same pattern as the existing `getRekapDandori`) on a normalized (`LOWER(TRIM(...))`) problem-text key, computing count + percentage per group, entirely independent of the 150-row cap — this is an aggregation query, not a raw dump, so it stays cheap and accurate at any data volume. The existing raw-list tools (`getProblemDies`/`getProblemMesin`) are unchanged in their per-row behavior and remain the right tool for narrative/detail questions ("apa aja penyebabnya", "perbaikan yang cocok").

Frontend: a set of clickable suggestion chips rendered only in the empty/greeting chat state, each one an example question that fires immediately on click.

**Tech Stack:** Same as prior chatbot work — Laravel Eloquent, PHPUnit, Alpine.js.

## Global Constraints

- `monthSpanOptional(?string $bulanMulai, ?string $bulanAkhir): [?Carbon $from, ?Carbon $to, ?string $error]`: both null → `[null, null, null]` (no filter); exactly one null → error `"Kalau isi salah satu periode, isi juga yang satunya."`; both given → delegates to existing `monthSpan()` validation unchanged
- The 8 tools from the previous round (`getProblemDies`, `getProblemMesin`, `getRankingBreakdownMesin`, `getRankingBreakdownDies`, `getRekapDandori`, `getTotalProduksi`) change their `bulanMulai`/`bulanAkhir` parameters from `string` to `?string = null`, use `monthSpanOptional()` instead of `monthSpan()`, and apply the date filter conditionally: `->when($from && $to, fn ($q) => $q->whereBetween(...))`. Their JSON schemas drop `bulanMulai`/`bulanAkhir` from `required`.
- The 4 original tools (`getMtbfMttrDies`, `getMtbfMttrMesin`, `getRekapNg`, `bandingkanProduksi`) are **out of scope** — they inherently need a specific single month or two distinct months to compare; forcing a default there would produce a nonsensical answer, not a convenience.
- `getTopProblemDies`/`getTopProblemMesin` group by `LOWER(TRIM(problem_dies))` / `LOWER(TRIM(problem_mesin))` — near-duplicate phrasing with real wording differences (not just case/whitespace) still counts as separate groups; this is the same accepted limitation as `getRekapDandori`'s grouping, not a new one
- Return top 10 groups sorted by count descending, each with `jumlah_kejadian`, `pct_dari_total` (rounded to 2 decimals), a representative `problem` string (original casing, from the most recent occurrence in that group), and one representative `contoh_penyebab`/`contoh_tindakan` pair (also from the most recent occurrence)
- Suggestion chips render only while `messages.length === 1` (the greeting-only state) and disappear once the first real message is sent — no persistent chip row

Spec context: follow-up to [2026-07-09-chatbot-tool-expansion-design.md](2026-07-09-chatbot-tool-expansion-design.md), which this spec's Global Constraints assume as the current baseline (8 date-scoped tools + system prompt with the raw-list anti-over-inference sentence already in place).

---

## `ChatbotTools::monthSpanOptional()` (new private helper)

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

## Signature + query changes on the 8 existing tools

Each tool's `string $bulanMulai, string $bulanAkhir` becomes `?string $bulanMulai = null, ?string $bulanAkhir = null`, its `monthSpan()` call becomes `monthSpanOptional()`, and its date-range query clause becomes conditional. Example for `getProblemDies` (others follow the identical pattern against their own query/aggregation):

```php
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

`getProblemMesin`, `getRankingBreakdownMesin`, `getRankingBreakdownDies`, `getRekapDandori`, `getTotalProduksi` all get the same three edits: nullable params, `monthSpanOptional()`, conditional `->when($from && $to, ...)` on their respective date columns (`tanggal_breakdown`, `tanggal`, `tanggal_produksi`), and `'bulan_mulai'/'bulan_akhir' => $bulanMulai ?? 'semua periode'` in their returns. Their "no data" error messages get the same `$periodLabel` conditional treatment as shown above (omit the "di periode X s/d Y" clause when no period was given).

## New tool: `getTopProblemDies`

```php
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
            $latest = $rows->first(); // already ordered desc by tanggal_breakdown
            return [
                'problem'          => $latest->problem_dies,
                'jumlah_kejadian'  => $rows->count(),
                'pct_dari_total'   => round($rows->count() / $total * 100, 2),
                'contoh_penyebab'  => $latest->penyebab_breakdown,
                'contoh_tindakan'  => $latest->tindakan_perbaikan,
            ];
        })
        ->sortByDesc('jumlah_kejadian')
        ->values()
        ->take(10);

    return [
        'bulan_mulai'   => $bulanMulai ?? 'semua periode',
        'bulan_akhir'   => $bulanAkhir ?? 'semua periode',
        'total_kejadian'=> $total,
        'top_problem'   => $groups->toArray(),
    ];
}
```

## New tool: `getTopProblemMesin`

Mirrors `getTopProblemDies` exactly, against `TransaksiBreakdownMesin` (`problem_mesin` instead of `problem_dies`, optional `idMesin` filter via the same `MasterMesin` lookup pattern used in `getProblemMesin`/`getRekapDandori`, `tanggal_breakdown` unchanged as the date column).

## `schemaDefinitions()` changes

For the 6 tools from the previous round (`getTotalBreakdownDies`, `getTotalBreakdownMesin`, `getProblemDies`, `getProblemMesin`, `getRankingBreakdownMesin`, `getRankingBreakdownDies`, `getRekapDandori`, `getTotalProduksi` — all 8), remove `bulanMulai`/`bulanAkhir` from each `required` array (leave them in `properties`, just no longer mandatory). Append their description with: `" Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time)."`

Two new schema entries:

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

## System prompt update

Append two sentences to `SYSTEM_PROMPT` in both `GeminiChatbotService` and `GroqChatbotService` (identical text, same manual-sync requirement as before):

> "Kalau user tidak menyebutkan periode, JANGAN tanya balik ke user — langsung panggil tool tanpa parameter periode (otomatis pakai semua data). Untuk pertanyaan 'X apa yang PALING SERING/PALING BANYAK muncul', gunakan tool ranking (getTopProblemDies, getTopProblemMesin, getRankingBreakdownDies, getRankingBreakdownMesin) — bukan tool daftar mentah (getProblemDies, getProblemMesin) — kecuali user secara eksplisit minta detail/daftar tiap kejadian."

## Frontend: suggestion chips

In `resources/views/chatbot/index.blade.php`, add a chip row between the greeting message area and the input form, shown only when `messages.length === 1` (i.e., only the greeting is present — no real exchange yet):

```blade
<div x-show="messages.length === 1" style="display:flex;flex-wrap:wrap;gap:8px;padding:0 16px 12px">
    <template x-for="q in suggestedQuestions" :key="q">
        <button type="button" @click="askSuggested(q)"
                style="border:1px solid #d1d5db;background:#fff;color:#111827;border-radius:16px;padding:6px 14px;font-size:0.8rem;cursor:pointer"
                x-text="q"></button>
    </template>
</div>
```

In the Alpine component (`chatbotPage()` in the same file's `<script>` block), add:

```javascript
suggestedQuestions: [
    'Problem dies apa yang paling sering muncul?',
    'Mesin apa yang sering rusak?',
    'Data produksi bulan ini berapa Pcs?',
    'Rekap NG proses apa yang paling tinggi?',
],

askSuggested(question) {
    this.input = question;
    this.send();
},
```

`send()` already reads `this.input` and pushes it as the user message — no changes needed there. Once `send()` runs, `messages.length` grows past 1 and the chip row's `x-show` hides it automatically.

## Error Handling

Same pattern as all existing tools — `monthSpanOptional()`'s "only one period given" case and all existing error paths still return `{'error': "..."}`, never throw.

## Testing

- `tests/Unit/ChatbotToolsTest.php`:
  - New tests for `monthSpanOptional()` behavior indirectly through each of the 8 modified tools: calling with both periods omitted returns all-time results (no error); calling with only one period given returns the "isi juga yang satunya" error; calling with both given behaves identically to before (regression coverage for existing behavior)
  - `getTopProblemDies`: multiple records sharing a normalized problem key group correctly (e.g. `"Motor overheat"` and `"motor overheat "` count as one group with `jumlah_kejadian: 2`), percentage computed correctly, top-10 cap and sort-desc-by-count, representative `problem`/`contoh_penyebab`/`contoh_tindakan` come from the most recent occurrence in the group, `partNo` filter narrows correctly, empty-result error case, all-time (no period) case returns data spanning multiple months
  - `getTopProblemMesin`: same coverage mirrored for mesin/`idMesin`
- `tests/Feature/ChatbotControllerTest.php`: no changes needed — this spec doesn't touch the controller
- No new `GeminiChatbotServiceTest`/`GroqChatbotServiceTest` cases needed — the system-prompt sentence addition isn't asserted on directly, consistent with the prior round's testing approach for prompt-text changes
- No automated test for the suggestion-chip UI (frontend-only, no test harness for Blade/Alpine in this codebase, consistent with how the delete-session UI was handled) — manual verification only

## Deploy Note (hosting, FTP-only, no SSH)

Modified: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`, `resources/views/chatbot/index.blade.php`. No new files, no migrations, no `.env` changes. Standard upload + `public/diag.php` cache-clear step.
