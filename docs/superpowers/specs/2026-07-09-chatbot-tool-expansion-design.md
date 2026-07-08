# AI Chatbot — Tool Expansion (Dandori/Breakdown/VCB) + Model Swap + Delete Session — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Chatbot currently only answers 6 narrow query shapes (MTBF/MTTR per part/machine, NG rate, produksi comparison, aggregate breakdown totals). User wants it to answer open-ended root-cause questions ("problem apa yang sering muncul pada dies?", "penyebabnya rata-rata apa?", "mesin apa aja yang sering rusak?") and follow-up questions in the same conversational flow, without hallucinating. Also: switch Gemini model to `gemini-2.5-flash-lite`, and add the ability to delete a chat session.

**Context:** Existing 6 `ChatbotTools` methods (`getMtbfMttrDies`, `getMtbfMttrMesin`, `getRekapNg`, `bandingkanProduksi`, `getTotalBreakdownDies`, `getTotalBreakdownMesin`) all require a specific part/machine identifier and/or don't expose free-text root-cause fields (`problem_dies`/`penyebab_breakdown`/`tindakan_perbaikan` on `TransaksiBreakdownDies`, `problem_mesin`/`penyebab_breakdown`/`tindakan_perbaikan` on `TransaksiBreakdownMesin`). There is no tool for dandori/losstime data (`TransaksiDandoriRincian` + `MasterLosstime`) at all, and no way to rank machines/dies by breakdown frequency. Both `GeminiChatbotService` and `GroqChatbotService` already forward full conversation history on every call (no windowing), so multi-turn follow-ups ("penyebabnya apa?", "perbaikan yang cocok?") already work today as long as the earlier tool result text is in history — no new mechanism needed for that part, only new tools that put root-cause text into the model's hands in the first place.

**Architecture:** Add 6 new `ChatbotTools` methods, all reusing existing service/model code (no new raw SQL beyond straightforward Eloquent aggregation already in the style of the current tools). Two of the new tools return **raw lists** of free-text fields (problem/penyebab/tindakan) rather than a pre-aggregated summary, because those fields are operator-typed and grouping by exact string match would fragment near-duplicate phrasing ("Motor overheat" vs "motor kepanasan") into false-distinct buckets. The LLM synthesizes the pattern itself from the raw list, constrained by a strengthened system prompt that forbids inferring anything not literally present in the returned text. Raw-list tools cap at 150 rows to bound token cost; if truncated, the tool result says so explicitly so the model can tell the user to narrow the date range instead of silently answering from partial data.

**Tech Stack:** Same as existing chatbot work — Laravel Eloquent, PHPUnit, Gemini/Groq HTTP APIs.

## Global Constraints

- All new tools follow the existing `{'error': "..."}` return shape for invalid input / no data (never throw)
- All new tools use the existing `monthRange()`/`monthSpan()` private helpers on `ChatbotTools` for `YYYY-MM` parsing — no new date format
- Raw-list tools (`getProblemDies`, `getProblemMesin`) cap results at 150 rows ordered by `tanggal_breakdown` descending (most recent first); if the underlying query has more rows, include `'note' => "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap."` in the return array
- No changes to `GeminiChatbotService`'s or `GroqChatbotService`'s request/response dispatch loop — this work only touches `ChatbotTools` (new methods + schema entries), the shared system prompt text, `config/services.php`/`.env` (model swap), and the conversation-delete feature (new controller method + route + view)
- Existing 6 tools and their schemas are unchanged

Spec context: follow-up to [2026-07-09-chatbot-aggregate-breakdown-design.md](2026-07-09-chatbot-aggregate-breakdown-design.md) and [2026-07-09-chatbot-groq-fallback-design.md](2026-07-09-chatbot-groq-fallback-design.md). Note: at the time of writing, the `HasChatbotSystemPrompt` trait extraction proposed in the aggregate-breakdown spec has **not** been implemented yet — `SYSTEM_PROMPT` is still a duplicated `private const` in both `GeminiChatbotService` and `GroqChatbotService`. This spec's system-prompt addition must be applied identically to both consts regardless of whether that trait extraction lands first or after.

---

## New `ChatbotTools` methods

### 1. `getProblemDies(string $bulanMulai, string $bulanAkhir, ?string $partNo = null): array`

Raw list of breakdown records for root-cause questions ("problem apa yang sering muncul pada dies?", "penyebabnya apa?", "perbaikan yang cocok apa?").

- Parse range via `monthSpan()`
- If `$partNo` given, resolve via `MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', ...)` (same lookup pattern as `getMtbfMttrDies`); error if not found
- Query `TransaksiBreakdownDies::with('partProses.part')->whereBetween('tanggal_breakdown', [$from, $to])`, filtered by resolved part's part_proses IDs when `$partNo` given
- Order by `tanggal_breakdown` desc, take first 150
- Return:
  ```php
  [
      'bulan_mulai' => $bulanMulai,
      'bulan_akhir' => $bulanAkhir,
      'total_record' => <int, count before cap>,
      'data' => [
          ['tanggal' => 'Y-m-d', 'part' => <part_name>, 'problem' => ..., 'penyebab' => ..., 'tindakan' => ...],
          ...
      ],
      // + 'note' if truncated, per Global Constraints
  ]
  ```
- Error if no records: `"Tidak ada data breakdown dies di periode {$bulanMulai} s/d {$bulanAkhir}."` (add part name to message when `$partNo` given)

### 2. `getProblemMesin(string $bulanMulai, string $bulanAkhir, ?string $idMesin = null): array`

Mirrors `getProblemDies` against `TransaksiBreakdownMesin`, filtered by `MasterMesin` lookup (same pattern as `getMtbfMttrMesin`) when `$idMesin` given. Return shape identical except `'part'` becomes `'mesin' => <nama_mesin>`.

### 3. `getRankingBreakdownMesin(string $bulanMulai, string $bulanAkhir): array`

Answers "mesin apa aja yang sering rusak?" — reuses `MaintenanceDashboardService::compute($from, $to)` (no `$mesinId`, already returns one row per machine with `count`/`availability_pct`). Sort rows by `count` descending, return top 10 with `count > 0`:
```php
[
    'bulan_mulai' => ..., 'bulan_akhir' => ...,
    'ranking' => [
        ['mesin' => <nama_mesin>, 'kode_mesin' => ..., 'jumlah_breakdown' => <count>, 'availability_pct' => ...],
        ...
    ],
]
```
Error if all rows have `count === 0`: `"Tidak ada kejadian breakdown mesin di periode {$bulanMulai} s/d {$bulanAkhir}."`

### 4. `getRankingBreakdownDies(string $bulanMulai, string $bulanAkhir): array`

Same as #3 but reuses `MaintenanceDiesDashboardService::compute($from, $to)` (no `$partId`). Return key `'part'` instead of `'mesin'`/`'kode_mesin'`.

### 5. `getRekapDandori(string $bulanMulai, string $bulanAkhir, ?string $idMesin = null): array`

Dandori/losstime aggregate — `jenis_losstime` is a controlled master-data field (not free text), so exact-match `GROUP BY` is safe here (unlike problem/penyebab).

- Parse range via `monthSpan()`; resolve `$idMesin` via `MasterMesin` lookup when given (error if not found)
- Query `TransaksiDandoriRincian::with('losstime')->whereBetween('tanggal', [$from, $to])`, filtered by `id_mesin` when given
- Group by `losstime.jenis_losstime`, per group: `count`, `sum(actual_menit)`, `sum(over_menit)`
- Sort groups by `sum(over_menit)` descending
- Return:
  ```php
  [
      'bulan_mulai' => ..., 'bulan_akhir' => ...,
      'total_kejadian' => <int>,
      'total_over_menit' => <int>,
      'top_jenis_losstime' => [
          ['jenis_losstime' => ..., 'jumlah_kejadian' => ..., 'total_actual_menit' => ..., 'total_over_menit' => ...],
          ...
      ],
  ]
  ```
- Error if no records: `"Tidak ada data dandori di periode {$bulanMulai} s/d {$bulanAkhir}."`

### 6. `getTotalProduksi(string $bulanMulai, string $bulanAkhir): array`

Answers "data produksi bulan X-Y berapa Pcs" (a combined-range total, distinct from `bandingkanProduksi`'s two-period delta comparison).

- Parse range via `monthSpan()`
- Query `TransaksiProduksi::whereBetween('tanggal_produksi', [$from, $to])`
- Return: `['bulan_mulai' => ..., 'bulan_akhir' => ..., 'total_qty_aktual' => (int) sum('qty_aktual'), 'avg_sph_aktual' => (int) round(avg(sph_aktual))]`
- Error if no records: `"Tidak ada data produksi di periode {$bulanMulai} s/d {$bulanAkhir}."`

## `ChatbotTools::schemaDefinitions()` additions

Six new entries, same shape/style as the existing 6 (name, Indonesian description steering the model on when to pick this tool vs. others, JSON-Schema `parameters` with `partNo`/`idMesin` marked optional where applicable — omit from `required` array, not `nullable` type, matching how Gemini/Groq function-calling expects optional params):

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

## System Prompt Hardening (anti-halu for raw-list tools)

Append one sentence to `SYSTEM_PROMPT` in both `GeminiChatbotService` and `GroqChatbotService` (identical text in both, same as today's manual-sync requirement until the trait extraction lands):

> "Kalau tool mengembalikan daftar data mentah (misalnya list problem/penyebab/tindakan), rangkum HANYA pola yang benar-benar muncul berulang di data tersebut — jangan menyimpulkan penyebab teknis atau solusi yang tidak tertulis literal di data."

## Model Swap

- `config/services.php`: change default fallback `'model' => env('GEMINI_MODEL', 'gemini-2.0-flash')` → `env('GEMINI_MODEL', 'gemini-2.5-flash-lite')`
- `.env` (local) and hosting `.env`: set `GEMINI_MODEL=gemini-2.5-flash-lite`
- No code changes to `GeminiChatbotService` — it already reads the model from config

## Delete Chat Session

- `ChatConversation` already has `SoftDeletes` — deleting is a plain `->delete()` call
- New route in `routes/web.php`, inside the existing `chatbot.` group: `Route::delete('conversations/{conversation}', [ChatbotController::class, 'destroy'])->name('conversations.destroy');`
- New `ChatbotController::destroy(Request $request, ChatConversation $conversation)`:
  ```php
  public function destroy(Request $request, ChatConversation $conversation): JsonResponse
  {
      abort_if($conversation->user_id !== $request->user()->id, 404);
      $conversation->delete();
      return response()->json(['deleted' => true]);
  }
  ```
- `resources/views/chatbot/index.blade.php`: add a trash-icon button (Bootstrap Icons, matching existing UI conventions) next to each conversation item in the sidebar list. Click → confirm dialog (`confirm()` or existing app modal pattern) → `fetch DELETE` to the new route → remove the item from the rendered list; if the deleted conversation is the one currently open, reset the chat pane to a fresh/empty conversation state (same as clicking "percakapan baru").

## Error Handling

Same pattern as all existing tools — invalid month format, part/mesin not found, or no matching records all return `{'error': "..."}` (never throw), which the calling service's existing flow relays to the model as a tool result.

## Testing

- `tests/Unit/ChatbotToolsTest.php`: add cases for all 6 new methods —
  - `getProblemDies`/`getProblemMesin`: happy path (records found, correct fields returned), part/mesin-not-found error, empty-range error, truncation behavior when >150 records exist (assert `note` key present and `data` capped at 150)
  - `getRankingBreakdownMesin`/`getRankingBreakdownDies`: multi-machine/dies data sorted correctly by count desc, zero-breakdown error case
  - `getRekapDandori`: multiple `jenis_losstime` groups aggregated and sorted by `total_over_menit` desc, mesin filter narrows correctly, empty-range error
  - `getTotalProduksi`: multi-month sum correct, empty-range error
- `tests/Feature/ChatbotControllerTest.php`: add a test for `destroy` — deletes own conversation (soft-deleted, excluded from subsequent `conversations()` listing), 404 when deleting another user's conversation
- No new `GeminiChatbotServiceTest`/`GroqChatbotServiceTest` cases needed — existing tool-dispatch tests already cover any tool name generically; the system-prompt sentence addition doesn't need a dedicated test (not asserted on directly today)

## Deploy Note (hosting, FTP-only, no SSH)

Modified: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`, `app/Http/Controllers/ChatbotController.php`, `routes/web.php`, `resources/views/chatbot/index.blade.php`, `config/services.php`. **Requires `.env` change on host** (`GEMINI_MODEL=gemini-2.5-flash-lite`) — no migration needed (`ChatConversation` already soft-deletable). Standard upload + `public/diag.php` cache-clear step (OPcache + config cache).
