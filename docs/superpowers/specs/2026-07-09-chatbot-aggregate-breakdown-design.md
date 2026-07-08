# AI Chatbot — Aggregate Breakdown Tools + Refusal Guardrail — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix a real hallucination observed in production — asked "berapa breakdown dies selama 2026" (a broad, multi-month, all-dies question), the chatbot fabricated specific-sounding numbers instead of refusing, because none of the 4 existing tools cover an unscoped/multi-month aggregate query. Add two new tools that cover this query shape for real, and harden the system prompt so any question still outside tool coverage gets an honest refusal instead of invented data.

**Context:** `getMtbfMttrDies(partNo, bulan)` / `getMtbfMttrMesin(idMesin, bulan)` require a specific part/machine AND a single `YYYY-MM` month — there is no tool for "all dies/machines, arbitrary date range." When a user asks a question no tool covers, the model (observed in production, provider unconfirmed — both Gemini and Groq share the same system prompt and tool set) answered anyway with invented specifics ("Juli 2026", "Januari 2026") that never came from a tool call, directly violating the existing "Jangan pernah mengarang angka" instruic — the instruction alone wasn't sufficient constraint on model behavior.

**Architecture:** Two new `ChatbotTools` methods reuse the existing `MaintenanceDiesDashboardService`/`MaintenanceDashboardService::compute()` calls in their already-supported "no ID filter = all dies/machines" mode, summing the `count` field across all returned rows for a caller-supplied month range (not just a single month, so "selama 2026" resolves in one tool call instead of needing 12 iterations against the 5-iteration cap). The system prompt gets one added sentence instructing explicit refusal when no tool fits. The system prompt itself moves out of `GeminiChatbotService`/`GroqChatbotService` (currently defined identically, by hand, in both) into one shared trait, closing the manual-sync risk the previous whole-branch review already flagged.

**Tech Stack:** Same as existing chatbot work — Laravel Eloquent, PHPUnit.

## Global Constraints

- Both new tools must reuse the existing dashboard `compute()` methods — no new raw queries against `TransaksiBreakdownDies`/`TransaksiBreakdownMesin`
- Tool parameter format stays `YYYY-MM` per month, consistent with all 4 existing tools — the new tools take TWO such strings (`bulanMulai`, `bulanAkhir`) defining an inclusive range, not a new date format
- The system prompt's non-hallucination instruction set must remain **identical** between `GeminiChatbotService` and `GroqChatbotService` — enforced structurally (single shared trait), not by convention
- No changes to `GeminiChatbotService`'s or `GroqChatbotService`'s request/response handling logic — this work only touches `ChatbotTools` (new methods + schema entries) and the shared system prompt text

Spec context: this is a follow-up to [2026-07-09-chatbot-groq-fallback-design.md](2026-07-09-chatbot-groq-fallback-design.md) (adds Groq fallback) and the original [2026-07-08-ai-chatbot-design.md](2026-07-08-ai-chatbot-design.md) (defines the 4 existing tools and the non-hallucination requirement this work reinforces).

---

## New `ChatbotTools` methods

```php
public function getTotalBreakdownDies(string $bulanMulai, string $bulanAkhir): array
public function getTotalBreakdownMesin(string $bulanMulai, string $bulanAkhir): array
```

Both:
1. Parse `$bulanMulai`/`$bulanAkhir` as `YYYY-MM`; error if either fails to parse, or if `$bulanMulai` is after `$bulanAkhir` (reuse the existing `monthRange()` per-string parser for each endpoint, then take `$fromStart` of the first and `$toEnd` of the second)
2. Call `$this->diesDashboard->compute($from, $to)` / `$this->mesinDashboard->compute($from, $to)` with **no** part/mesin ID (both dashboard services already support this — `?int $partId = null` / `?int $mesinId = null` return all dies/machines in range)
3. Sum the `count` field across all returned rows for the total breakdown occurrence count; count how many rows have `count > 0` for "how many distinct dies/machines were affected"

**Return shape** (dies example, mesin mirrors with `jumlah_mesin_terdampak`):
```php
[
    'bulan_mulai'            => $bulanMulai,
    'bulan_akhir'            => $bulanAkhir,
    'total_breakdown'        => <int, sum of count across all rows>,
    'jumlah_dies_terdampak'  => <int, count of rows where count > 0>,
]
```

**Error cases** (same `{'error': "..."}` shape as the 4 existing tools):
- Either month fails `YYYY-MM` parsing: `"Format bulan \"{$bulan}\" tidak valid, gunakan format YYYY-MM."`
- `$bulanMulai` after `$bulanAkhir`: `"Bulan mulai tidak boleh setelah bulan akhir."`

## `ChatbotTools::schemaDefinitions()` additions

Two more entries, same shape as the existing 4:
```php
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
```

The `description` text explicitly says "SEMUA" (all) and "bukan per part/mesin spesifik" to help the model pick this tool over `getMtbfMttrDies`/`getMtbfMttrMesin` when the user's question doesn't name a specific part/machine.

## Shared system prompt (closes the drift risk)

Create `app/Services/Concerns/HasChatbotSystemPrompt.php`:
```php
<?php
namespace App\Services\Concerns;

trait HasChatbotSystemPrompt
{
    protected const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tidak ada tool yang cocok untuk menjawab pertanyaan user, WAJIB bilang terus terang bahwa kamu tidak punya kapabilitas untuk itu — JANGAN membuat jawaban sendiri meskipun terdengar masuk akal.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;
}
```

`GeminiChatbotService` and `GroqChatbotService` both `use HasChatbotSystemPrompt;` and delete their own local `private const SYSTEM_PROMPT` declaration. Since PHP resolves the constant through the trait at compile time, both classes are now provably using the exact same text — a future edit to one can't silently diverge from the other, which is the actual root-cause fix for the review-flagged manual-sync risk (not just a one-time correction).

## Error Handling

Same pattern as all 4 existing tools — invalid input returns a `{'error': "..."}` array (never throws), which the calling service's non-hallucination-preserving flow already forwards to the model as a tool result it must relay "naturally" per the system prompt.

## Testing

- `tests/Unit/ChatbotToolsTest.php`: add tests for `getTotalBreakdownDies` and `getTotalBreakdownMesin` — a multi-month range with breakdown records in more than one of the covered months (proving the sum spans the whole range, not just one month), an invalid-format case, and a `bulanMulai` > `bulanAkhir` case
- No new `GeminiChatbotServiceTest`/`GroqChatbotServiceTest` cases are needed for the system-prompt change — the trait extraction makes cross-provider drift structurally impossible rather than something to test for. The 2 new tools' schema entries don't need dedicated provider-level tests either — the existing `test_executes_tool_call_then_returns_final_text` tests in both suites already prove the general tool-dispatch path works for any tool name, and the tool-specific behavior is fully covered at the `ChatbotToolsTest` level
- Existing `GeminiChatbotServiceTest`/`GroqChatbotServiceTest` tests must still pass unchanged after the `SYSTEM_PROMPT` trait extraction (they don't assert on the prompt text directly, only on tool-calling behavior)

## Deploy Note (hosting, FTP-only, no SSH)

New file: `app/Services/Concerns/HasChatbotSystemPrompt.php`. Modified: `app/Services/ChatbotTools.php`, `app/Services/GeminiChatbotService.php`, `app/Services/GroqChatbotService.php`. No `.env`/config changes, no migrations. Standard upload + the usual `public/diag.php` cache-clear step (needed for any PHP class change to take effect given OPcache on the host).
