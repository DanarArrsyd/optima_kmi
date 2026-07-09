# AI Chatbot — Groq Fallback Provider Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Groq (Llama 3.3 70B) as an automatic same-request fallback provider for the chatbot, so a Gemini quota/outage failure (confirmed in production: `429` from Google's free-tier project quota) doesn't leave the user with only the generic "unavailable" message.

**Architecture:** `ChatbotController` tries `GeminiChatbotService::ask()` first; on `null` (total failure) it tries `GroqChatbotService::ask()`; if both return `null`, a shared generic fallback string is used. Both services implement the same `ChatbotProviderInterface` and independently run their own tool-calling loop against the same `ChatbotTools` methods, so answers stay grounded in real DB queries regardless of which provider answered.

**Tech Stack:** Laravel 13 `Http` facade, Groq's OpenAI-compatible Chat Completions API (`https://api.groq.com/openai/v1/chat/completions`), model `llama-3.3-70b-versatile`, PHPUnit (`php artisan test`).

## Global Constraints

- Every number in a chatbot reply must come from a `ChatbotTools` call result — Groq MUST use the same tool-calling loop pattern as Gemini, never a plain text-only fallback
- Access control unchanged: `role:admin,manager`, untouched by this work
- No `ChatConversation`/`ChatMessage` schema change
- `GEMINI_API_KEY`/`GROQ_API_KEY` live only in `.env` (local + hosting), never committed
- `GroqChatbotService::ask()` caps at 5 tool-calling iterations, matching `GeminiChatbotService`'s existing `MAX_ITERATIONS`

Spec: [docs/superpowers/specs/2026-07-09-chatbot-groq-fallback-design.md](../specs/2026-07-09-chatbot-groq-fallback-design.md)

---

## Task 1: Extract shared tool schema, add provider interface, make Gemini failure return `null`

**Files:**
- Create: `app/Contracts/ChatbotProviderInterface.php`
- Modify: `app/Services/ChatbotTools.php`
- Modify: `app/Services/GeminiChatbotService.php`
- Test: `tests/Unit/GeminiChatbotServiceTest.php`

**Interfaces:**
- Produces: `ChatbotProviderInterface::ask(ChatConversation $conversation, string $userMessage): ?string` — implemented by `GeminiChatbotService` in this task, and by `GroqChatbotService` in Task 2
- Produces: `ChatbotTools::schemaDefinitions(): array` — returns `[{name, description, parameters}, ...]` for the 4 existing tools (`getMtbfMttrDies`, `getMtbfMttrMesin`, `getRekapNg`, `bandingkanProduksi`), the single source of truth both provider services wrap in their own request format

- [ ] **Step 1: Write the failing interface-and-nullable-return tests**

In `tests/Unit/GeminiChatbotServiceTest.php`, replace the 3 fallback-string tests with nullable-return versions (rename each, change `assertSame(..., $reply)` to `assertNull($reply)`):

```php
    public function test_returns_null_when_gemini_api_fails(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_loop_exceeds_max_iterations(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'X', 'periode' => '2026-07']],
                ]]],
            ]],
        ], 200)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_tool_call_has_missing_required_args(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'BENDING']],
                ]]],
            ]],
        ], 200)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG?');

        $this->assertNull($reply);
    }
```

These replace `test_returns_fallback_message_when_gemini_api_fails`, `test_returns_fallback_message_when_loop_exceeds_max_iterations`, and `test_returns_fallback_when_tool_call_has_missing_required_args` respectively — delete the old 3 methods, add these 3 in their place. Leave `test_returns_gemini_text_reply_directly_when_no_tool_call_needed` and `test_executes_tool_call_then_returns_final_text` unchanged (success path return type doesn't change).

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GeminiChatbotServiceTest`
Expected: FAIL — the 3 new/renamed tests get a string back (`'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.'`), not `null`.

- [ ] **Step 3: Create the provider interface**

Create `app/Contracts/ChatbotProviderInterface.php`:

```php
<?php
namespace App\Contracts;

use App\Models\ChatConversation;

interface ChatbotProviderInterface
{
    /**
     * Attempt to answer `$userMessage` using tool-calling against real OPTIMA
     * data. Returns null on total provider failure (network error, non-2xx
     * response, malformed tool call, or exhausted iteration budget) so the
     * caller can fall back to another provider.
     */
    public function ask(ChatConversation $conversation, string $userMessage): ?string;
}
```

- [ ] **Step 4: Add `ChatbotTools::schemaDefinitions()`**

In `app/Services/ChatbotTools.php`, add this method (place it as the last method in the class, before the closing `}`):

```php
    /** Provider-neutral tool schema (name/description/JSON-Schema parameters), wrapped per-provider by each ChatbotProviderInterface implementation. */
    public function schemaDefinitions(): array
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
            [
                'name' => 'getRekapNg',
                'description' => 'Rekap total qty NG dan NG rate untuk sebuah nama proses pada bulan tertentu.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'namaProses' => ['type' => 'string', 'description' => 'Nama proses, contoh: BENDING'],
                        'periode'    => ['type' => 'string', 'description' => 'Format YYYY-MM'],
                    ],
                    'required' => ['namaProses', 'periode'],
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
        ];
    }
```

This is the exact content currently in `GeminiChatbotService::toolSchema()` — Step 5 removes it from there and points the one call site at this new method instead.

- [ ] **Step 5: Refactor `GeminiChatbotService`**

Replace the entire contents of `app/Services/GeminiChatbotService.php` with:

```php
<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatbotService implements ChatbotProviderInterface
{
    private const MAX_ITERATIONS = 5;

    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;

    public function __construct(private ChatbotTools $tools) {}

    public function ask(ChatConversation $conversation, string $userMessage): ?string
    {
        $contents = $this->buildHistory($conversation);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGemini($contents);
            if ($response === null) {
                return null;
            }

            $part = $response['candidates'][0]['content']['parts'][0] ?? null;
            if ($part === null) {
                return null;
            }

            if (isset($part['text'])) {
                return $part['text'];
            }

            if (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'] ?? null;
                if ($name === null) {
                    return null;
                }

                $args = $part['functionCall']['args'] ?? [];

                if (!method_exists($this->tools, $name)) {
                    return null;
                }

                try {
                    $result = $this->tools->{$name}(...$args);
                } catch (\Throwable $e) {
                    Log::warning('Chatbot tool dispatch failed', ['name' => $name, 'message' => $e->getMessage()]);
                    return null;
                }

                $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $part['functionCall']]]];
                $contents[] = ['role' => 'function', 'parts' => [[
                    'functionResponse' => ['name' => $name, 'response' => $result],
                ]]];
                continue;
            }

            return null;
        }

        return null;
    }

    private function buildHistory(ChatConversation $conversation): array
    {
        return $conversation->messages->map(fn ($m) => [
            'role'  => $m->role === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m->content]],
        ])->values()->toArray();
    }

    private function callGemini(array $contents): ?array
    {
        $model = config('services.gemini.model');
        $key   = config('services.gemini.key');

        try {
            $response = Http::timeout(15)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}",
                [
                    'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                    'contents'           => $contents,
                    'tools'              => [['functionDeclarations' => $this->tools->schemaDefinitions()]],
                ]
            );

            if (!$response->successful()) {
                Log::warning('Gemini API non-2xx response', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('Gemini API call failed', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
```

Changes from the current file: implements `ChatbotProviderInterface`; `ask()` return type is `?string`; every `return self::FALLBACK;` becomes `return null;`; the `FALLBACK` constant is removed; the private `toolSchema()` method is removed and its one call site now reads `$this->tools->schemaDefinitions()`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=GeminiChatbotServiceTest`
Expected: PASS (all 5 tests: the 2 unchanged success-path tests + the 3 new nullable-return tests)

- [ ] **Step 7: Run the full test suite to check for ripple effects**

Run: `php artisan test`
Expected: PASS. `ChatbotController::sendMessage()` (`app/Http/Controllers/ChatbotController.php:31`) still calls `$this->svc->ask(...)` directly and stores whatever it returns — but no existing `ChatbotControllerTest` test exercises a Gemini-failure response (all of them either fake a 200 success or never reach the service call), so the `?string` return-type change has no observable effect yet. Task 3 changes the controller to use the fallback chain; if this run surfaces any unexpected failure, stop and investigate before continuing — it means a test exercises a path this plan didn't anticipate.

- [ ] **Step 8: Commit**

```bash
git add app/Contracts/ChatbotProviderInterface.php app/Services/ChatbotTools.php app/Services/GeminiChatbotService.php tests/Unit/GeminiChatbotServiceTest.php
git commit -m "refactor(chatbot): extract shared tool schema, make Gemini failure return null"
```

---

## Task 2: `GroqChatbotService`

**Files:**
- Create: `app/Services/GroqChatbotService.php`
- Create: `tests/Unit/GroqChatbotServiceTest.php`
- Modify: `config/services.php`

**Interfaces:**
- Consumes: `ChatbotProviderInterface` (Task 1, `app/Contracts/ChatbotProviderInterface.php`), `ChatbotTools::schemaDefinitions()` (Task 1, `app/Services/ChatbotTools.php`)
- Produces: `GroqChatbotService::ask(ChatConversation $conversation, string $userMessage): ?string`, consumed by `ChatbotController` in Task 3

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/GroqChatbotServiceTest.php`:

```php
<?php
namespace Tests\Unit;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatbotTools;
use App\Services\GroqChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): ChatConversation
    {
        $user = User::factory()->create(['role' => 'admin']);
        return ChatConversation::create(['user_id' => $user->id]);
    }

    public function test_returns_groq_text_reply_directly_when_no_tool_call_needed(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'Halo, ada yang bisa gw bantu?'],
                ]],
            ], 200),
        ]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Halo, ada yang bisa gw bantu?', $reply);
    }

    public function test_executes_tool_call_then_returns_final_text(): void
    {
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            if ($call === 1) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'getRekapNg',
                                    'arguments' => json_encode(['namaProses' => 'BENDING', 'periode' => '2026-07']),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }
            return Http::response([
                'choices' => [[
                    'message' => ['role' => 'assistant', 'content' => 'NG di proses BENDING bulan itu 0.'],
                ]],
            ], 200);
        });

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG bending bulan lalu?');

        $this->assertSame('NG di proses BENDING bulan itu 0.', $reply);
        Http::assertSentCount(2);
    }

    public function test_returns_null_when_groq_api_fails(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([], 500)]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_loop_exceeds_max_iterations(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_x',
                        'type' => 'function',
                        'function' => [
                            'name' => 'getRekapNg',
                            'arguments' => json_encode(['namaProses' => 'X', 'periode' => '2026-07']),
                        ],
                    ]],
                ],
            ]],
        ], 200)]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertNull($reply);
    }

    public function test_returns_null_when_tool_call_arguments_are_invalid_json(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_bad',
                        'type' => 'function',
                        'function' => [
                            'name' => 'getRekapNg',
                            'arguments' => 'not valid json{{',
                        ],
                    ]],
                ],
            ]],
        ], 200)]);

        $reply = (new GroqChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG?');

        $this->assertNull($reply);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=GroqChatbotServiceTest`
Expected: FAIL — class `App\Services\GroqChatbotService` not found.

- [ ] **Step 3: Add Groq config**

In `config/services.php`, add after the existing `'gemini' => [...]` entry (before the closing `];`):

```php
    'groq' => [
        'key'   => env('GROQ_API_KEY'),
        'model' => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
    ],
```

- [ ] **Step 4: Create `GroqChatbotService`**

Create `app/Services/GroqChatbotService.php`:

```php
<?php
namespace App\Services;

use App\Contracts\ChatbotProviderInterface;
use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GroqChatbotService implements ChatbotProviderInterface
{
    private const MAX_ITERATIONS = 5;

    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;

    public function __construct(private ChatbotTools $tools) {}

    public function ask(ChatConversation $conversation, string $userMessage): ?string
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        $messages = array_merge($messages, $this->buildHistory($conversation));
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGroq($messages);
            if ($response === null) {
                return null;
            }

            $message = $response['choices'][0]['message'] ?? null;
            if ($message === null) {
                return null;
            }

            $toolCalls = $message['tool_calls'] ?? null;

            if (empty($toolCalls)) {
                return $message['content'] ?? null;
            }

            $messages[] = $message;

            foreach ($toolCalls as $toolCall) {
                $name = $toolCall['function']['name'] ?? null;
                if ($name === null || !method_exists($this->tools, $name)) {
                    return null;
                }

                $args = json_decode($toolCall['function']['arguments'] ?? '[]', true);
                if (!is_array($args)) {
                    return null;
                }

                try {
                    $result = $this->tools->{$name}(...$args);
                } catch (\Throwable $e) {
                    Log::warning('Chatbot tool dispatch failed', ['name' => $name, 'message' => $e->getMessage()]);
                    return null;
                }

                $messages[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $toolCall['id'] ?? '',
                    'content'      => json_encode($result),
                ];
            }
        }

        return null;
    }

    private function buildHistory(ChatConversation $conversation): array
    {
        return $conversation->messages->map(fn ($m) => [
            'role'    => $m->role === 'assistant' ? 'assistant' : 'user',
            'content' => $m->content,
        ])->values()->toArray();
    }

    private function callGroq(array $messages): ?array
    {
        $model = config('services.groq.model');
        $key   = config('services.groq.key');

        try {
            $response = Http::timeout(15)
                ->withToken($key)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model'    => $model,
                    'messages' => $messages,
                    'tools'    => $this->toolSchema(),
                ]);

            if (!$response->successful()) {
                Log::warning('Groq API non-2xx response', ['status' => $response->status()]);
                return null;
            }

            return $response->json();
        } catch (\Throwable $e) {
            Log::warning('Groq API call failed', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function toolSchema(): array
    {
        return array_map(fn ($tool) => [
            'type'     => 'function',
            'function' => $tool,
        ], $this->tools->schemaDefinitions());
    }
}
```

Note the one structurally distinct step versus Gemini: `json_decode($toolCall['function']['arguments'] ?? '[]', true)` — Groq/OpenAI sends tool-call arguments as a JSON-encoded **string**, unlike Gemini's already-parsed array. The `!is_array($args)` guard catches both invalid JSON (`json_decode` returns `null`) and any non-object JSON value.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=GroqChatbotServiceTest`
Expected: PASS (5/5)

- [ ] **Step 6: Commit**

```bash
git add app/Services/GroqChatbotService.php tests/Unit/GroqChatbotServiceTest.php config/services.php
git commit -m "feat(chatbot): add Groq as a tool-calling-capable chatbot provider"
```

---

## Task 3: Controller fallback orchestration

**Files:**
- Modify: `app/Http/Controllers/ChatbotController.php`
- Test: `tests/Feature/ChatbotControllerTest.php`

**Interfaces:**
- Consumes: `GeminiChatbotService::ask(): ?string` (Task 1), `GroqChatbotService::ask(): ?string` (Task 2)

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ChatbotControllerTest.php`:

```php
    public function test_falls_back_to_groq_when_gemini_fails(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban dari Groq.']]],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo']);

        $response->assertOk()->assertJson(['reply' => 'Jawaban dari Groq.']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'assistant', 'content' => 'Jawaban dari Groq.']);
    }

    public function test_returns_generic_fallback_when_both_providers_fail(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([], 500),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo']);

        $response->assertOk()->assertJson(['reply' => 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: FAIL — the 2 new tests fail (current controller has no `groq` dependency and still calls the single `svc`); depending on Task 1's Step 7 state, other pre-existing tests may also currently be failing because `ask()` now returns `?string` — this task's Step 4 fixes all of them at once.

- [ ] **Step 3: Add the `Http` facade import if not already present**

Check `tests/Feature/ChatbotControllerTest.php`'s existing `use` statements — `Illuminate\Support\Facades\Http` is already imported (used by existing tests). No import change needed.

- [ ] **Step 4: Refactor `ChatbotController`**

Replace the entire contents of `app/Http/Controllers/ChatbotController.php` with:

```php
<?php
namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ChatbotController extends Controller
{
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    public function __construct(
        private GeminiChatbotService $gemini,
        private GroqChatbotService $groq,
    ) {}

    public function index(): View
    {
        return view('chatbot.index');
    }

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:chat_conversations,id'],
        ]);

        $conversation = ($data['conversation_id'] ?? null)
            ? ChatConversation::where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : ChatConversation::create(['user_id' => $request->user()->id]);

        $reply = $this->gemini->ask($conversation->fresh('messages'), $data['message'])
            ?? $this->groq->ask($conversation->fresh('messages'), $data['message'])
            ?? self::FALLBACK;

        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);
        $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);

        return response()->json(['conversation_id' => $conversation->id, 'reply' => $reply]);
    }

    public function conversations(Request $request): JsonResponse
    {
        $conversations = ChatConversation::where('user_id', $request->user()->id)
            ->with(['messages' => fn ($q) => $q->oldest()->limit(1)])
            ->latest('updated_at')
            ->get();

        return response()->json($conversations->map(fn ($c) => [
            'id'         => $c->id,
            'title'      => Str::limit($c->messages->first()?->content ?? 'Percakapan baru', 40),
            'updated_at' => $c->updated_at,
        ]));
    }

    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 404);

        return response()->json($conversation->messages->map(fn ($m) => [
            'role' => $m->role, 'content' => $m->content,
        ]));
    }
}
```

Changes from the current file: constructor now takes both `GeminiChatbotService $gemini` and `GroqChatbotService $groq`; new `private const FALLBACK`; `sendMessage()`'s reply line becomes the 3-way null-coalescing chain. `conversations()`, `show()`, and `index()` are unchanged.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS (all tests in the file, including the 2 new fallback-chain tests)

- [ ] **Step 6: Run the full test suite**

Run: `php artisan test`
Expected: PASS — this confirms Task 1's Step 7 ripple is now resolved and nothing else broke.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ChatbotController.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): orchestrate Gemini-then-Groq fallback in sendMessage"
```

---

## Task 4: Static verification + deploy notes

Not a code-change task — confirms the feature is internally consistent and documents the manual hosting-deploy steps (this project has no SSH access; `.env` and file uploads are manual via FTP, per `memory/project_hosting_deploy.md`).

- [ ] **Step 1: Route and config sanity check**

Run: `php artisan route:list --name=chatbot`
Expected: same 4 routes as before this work (`chatbot.index`, `chatbot.conversations`, `chatbot.conversations.show`, `chatbot.send-message`) — this task doesn't touch routing, confirming no accidental route drift.

- [ ] **Step 2: Blade/config compile check**

Run: `php artisan view:cache` then `php artisan view:clear` (always run `view:clear` afterward, even on success, to reset to normal dev state)
Expected: `Blade templates cached successfully` with no errors, then `Compiled views cleared successfully`.

- [ ] **Step 3: Full test suite**

Run: `php artisan test`
Expected: all tests passing, including the new `GroqChatbotServiceTest` (5 tests) and the 2 new `ChatbotControllerTest` fallback-chain tests.

- [ ] **Step 4: Document the manual hosting deploy checklist**

This step is documentation only — no file changes beyond what's already committed. Report back to the user (not part of the git history) the exact checklist:

**Files to upload via FTP:**
- New: `app/Contracts/ChatbotProviderInterface.php`
- New: `app/Services/GroqChatbotService.php`
- Modified: `app/Services/GeminiChatbotService.php`
- Modified: `app/Services/ChatbotTools.php`
- Modified: `app/Http/Controllers/ChatbotController.php`
- Modified: `config/services.php`

**Hosting `.env` — add these two lines:**
```
GROQ_API_KEY=<key from https://console.groq.com/keys>
GROQ_MODEL=llama-3.3-70b-versatile
```

**After uploading:** open `public/diag.php` once (it resets `bootstrap/cache/*.php`, which is required for the new `config/services.php` entry and the new `.env` values to actually take effect — this exact step was the gap that caused the original 401 fix to appear not to work during the earlier investigation). Then test `/chatbot` with a message that would trigger a tool call (e.g. "rekap NG bending bulan ini") to confirm the full loop works end-to-end on whichever provider answers.
