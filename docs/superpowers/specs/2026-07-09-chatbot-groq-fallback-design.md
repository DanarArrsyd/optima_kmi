# AI Chatbot — Groq Fallback Provider — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add Groq (Llama 3.3 70B) as an automatic fallback provider for the OPTIMA AI chatbot, so a Gemini quota/outage failure doesn't leave the user with only the generic "unavailable" message — Groq attempts the same request, with the same tool-calling access to real OPTIMA data.

**Context:** Production hit `429` (quota exceeded) on the shared Google Cloud project backing `GEMINI_API_KEY`. Root cause is project-level free-tier quota, confirmed via `storage/logs/laravel.log` (`Gemini API non-2xx response {"status":429}` after the API key itself was fixed and no longer returning 401). Switching providers entirely was considered and rejected — every free-tier provider has similar caps, and Gemini's tool-calling integration already works correctly. A same-request fallback to a second provider is the targeted fix.

**Architecture:** `ChatbotController` tries `GeminiChatbotService` first; on total failure (`null`) it tries `GroqChatbotService`; if both fail, a shared generic fallback message is returned. Each service independently runs its own tool-calling loop against the same `ChatbotTools` methods, so answers stay grounded in real DB queries regardless of which provider answered.

**Tech Stack:** Laravel `Http` facade (no new SDK), Groq's OpenAI-compatible Chat Completions API (`https://api.groq.com/openai/v1/chat/completions`), model `llama-3.3-70b-versatile`.

## Global Constraints

- Non-hallucination rule from the original chatbot spec still applies to Groq: every number in a reply must come from a `ChatbotTools` call result, never generated free-text. Groq fallback MUST use the same tool-calling loop pattern as Gemini, not a plain text-only fallback.
- Access control unchanged: `role:admin,manager` only, enforced at the route level (untouched by this change).
- No change to `ChatConversation`/`ChatMessage` schema — conversation history stored as plain `{role, content}` text turns works unchanged for both providers (neither provider's raw function-call payloads are persisted, matching current behavior).
- `GEMINI_API_KEY`/`GROQ_API_KEY` — both live only in `.env` (local + hosting), never committed. Hosting deploy is FTP-only, no SSH — `.env` update on the server needs a manual step by the user, called out explicitly in the plan's deploy notes.
- MAX_ITERATIONS = 5 for Groq's tool-calling loop, matching Gemini's existing cap.

---

## Components

**New files:**
- `app/Services/GroqChatbotService.php` — mirrors `GeminiChatbotService`'s structure and public contract, adapted to Groq/OpenAI's request/response shape
- `app/Contracts/ChatbotProviderInterface.php` — `ask(ChatConversation $conversation, string $userMessage): ?string`
- `tests/Unit/GroqChatbotServiceTest.php` — mirrors `tests/Unit/GeminiChatbotServiceTest.php`

**Modified files:**
- `app/Services/GeminiChatbotService.php` — implements `ChatbotProviderInterface`; returns `null` instead of the hardcoded fallback string on total failure; the `FALLBACK` constant and its usages are removed from this class
- `app/Services/ChatbotTools.php` — add `schemaDefinitions(): array`, the single source of truth for the 4 tools' `{name, description, parameters}` (JSON Schema), consumed by both services' provider-specific wrapping
- `app/Http/Controllers/ChatbotController.php` — constructor now takes both `GeminiChatbotService` and `GroqChatbotService`; `sendMessage()` tries Gemini, falls back to Groq, falls back to a shared `FALLBACK` constant (moved here from `GeminiChatbotService`)
- `config/services.php` — add `groq` entry
- `tests/Feature/ChatbotControllerTest.php` — add fallback-chain tests

**Config additions (`.env`, both local and hosting):**
```
GROQ_API_KEY=...
GROQ_MODEL=llama-3.3-70b-versatile
```

## `ChatbotTools::schemaDefinitions()`

Returns the 4 existing tools' definitions in a provider-neutral shape (this is exactly the inner content already used by `GeminiChatbotService::toolSchema()`, just extracted so Groq doesn't duplicate the descriptions):

```php
[
    ['name' => 'getMtbfMttrDies', 'description' => '...', 'parameters' => ['type' => 'object', 'properties' => [...], 'required' => [...]]],
    ['name' => 'getMtbfMttrMesin', 'description' => '...', 'parameters' => [...]],
    ['name' => 'getRekapNg', 'description' => '...', 'parameters' => [...]],
    ['name' => 'bandingkanProduksi', 'description' => '...', 'parameters' => [...]],
]
```

`GeminiChatbotService::toolSchema()` becomes: `['functionDeclarations' => $this->tools->schemaDefinitions()]` wrapped as Gemini's `tools` payload — same as today, just sourced from the shared method instead of a locally duplicated array.

`GroqChatbotService::toolSchema()` wraps each entry as: `['type' => 'function', 'function' => ['name' => ..., 'description' => ..., 'parameters' => ...]]` — one array per tool, passed as the `tools` field in the Groq request body.

## `GroqChatbotService` — request/response shape

**Request body:**
```php
[
    'model'    => config('services.groq.model'),
    'messages' => [
        ['role' => 'system', 'content' => <same system prompt text as Gemini's>],
        ...history...,
        ['role' => 'user', 'content' => $userMessage],
    ],
    'tools'    => $this->toolSchema(),
]
```

**History mapping** (from `$conversation->messages`, same source as Gemini): `role === 'assistant' ? 'assistant' : 'user'`, `content` copied as-is — no `model`/`function` role renaming needed since Groq/OpenAI already uses `assistant`/`user` natively.

**Response parsing** (`choices[0].message`):
- `content` present, no `tool_calls` → final answer, return the text.
- `tool_calls` present (array, each `{id, type: 'function', function: {name, arguments}}`) → for each tool call:
  1. `json_decode($toolCall['function']['arguments'], true)` — Groq/OpenAI sends arguments as a **JSON-encoded string**, unlike Gemini's native array — this decode step is the one structurally distinct piece of parsing and must not be skipped.
  2. Call `$this->tools->{$name}(...$decodedArgs)`, same dispatch pattern as Gemini (including the existing try/catch-and-fallback-on-`\Throwable` guard from `GeminiChatbotService`, ported identically).
  3. Append the assistant's tool-call message AND one `role: 'tool', tool_call_id: <id>, content: json_encode($result)` message per call to the running `messages` array, then loop (max 5 iterations total, same cap as Gemini).

**Error handling** — identical shape to `GeminiChatbotService::callGemini()`: `Http::timeout(15)->post(...)`, non-2xx → `Log::warning('Groq API non-2xx response', ['status' => ...])` and return `null` up the call chain; any `\Throwable` → `Log::warning('Groq API call failed', ['message' => ...])`, return `null`. Malformed/missing `functionCall`/tool name → same defensive `null` return already established for Gemini (regression-tested in the original chatbot work — port the same guard, don't relax it).

## `ChatbotController` orchestration

```php
public function __construct(
    private GeminiChatbotService $gemini,
    private GroqChatbotService $groq,
) {}

private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

public function sendMessage(Request $request): JsonResponse
{
    // ...existing validation + conversation resolution, unchanged...

    $reply = $this->gemini->ask($conversation->fresh('messages'), $data['message'])
        ?? $this->groq->ask($conversation->fresh('messages'), $data['message'])
        ?? self::FALLBACK;

    // ...existing persistence of user + assistant messages, unchanged...
}
```

No change to `conversations()`, `show()`, or `index()` — this work is scoped entirely to the answer-generation path.

## Error Handling

- Gemini fails (429/500/timeout/exception) → silently try Groq, no error surfaced to the user unless Groq also fails
- Groq fails too → existing generic fallback message, same UX as today
- Both providers' individual failures are logged via `Log::warning` (already the pattern for Gemini) — this preserves the `diag.php`-based debugging workflow already used to diagnose the original 401/429 issue
- A malformed Groq tool-call (bad JSON in `arguments`, unknown tool name) must not throw an uncaught exception — return `null` from that call attempt, same defensive contract Gemini already has (see `app/Services/GeminiChatbotService.php`'s existing `\Throwable` guard around tool dispatch, added as a fix during the original chatbot implementation)

## Testing

- `tests/Unit/GroqChatbotServiceTest.php`: mirrors `GeminiChatbotServiceTest` — `Http::fake` the Groq endpoint, verify tool-calling loop executes and returns tool-grounded answers, verify non-2xx/exception returns `null` (not a hardcoded string)
- `tests/Feature/ChatbotControllerTest.php`: add — Gemini `Http::fake`d to fail (429), Groq `Http::fake`d to succeed → assert the reply is Groq's text, not the generic fallback; both fake to fail → assert the generic fallback text
- Existing Gemini-only tests must still pass with `GeminiChatbotService::ask()`'s new `?string` return type (a successful Gemini call returns the same string as before — only the failure path's return value changes from a hardcoded string to `null`)

## Deploy Note (hosting, FTP-only, no SSH)

New files to upload: `app/Services/GroqChatbotService.php`, `app/Contracts/ChatbotProviderInterface.php`. Modified: `app/Services/GeminiChatbotService.php`, `app/Services/ChatbotTools.php`, `app/Http/Controllers/ChatbotController.php`, `config/services.php`. Plus: add `GROQ_API_KEY` and `GROQ_MODEL` to hosting `.env`, then re-run `public/diag.php` once (clears `bootstrap/cache/*.php` so the new `config/services.php` entry and `.env` values actually take effect — this exact step was the fix-verification gap during the 401/429 investigation).
