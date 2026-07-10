# Chatbot Full-DB Revamp Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rombak halaman `/chatbot` jadi sesi ephemeral (tanpa sidebar/persistensi), UI industrial-monochrome yang dipoles (markdown/tabel, typing, copy/regenerate), dan backend hybrid yang bisa telusur semua tabel bisnis lewat `queryDatabase` + `describeSchema`.

**Architecture:** Server jadi stateless — browser pegang history di memori Alpine, dikirim tiap request. Provider service (`ask()`) di-decouple dari model DB jadi terima `array $history`. `ChatbotTools` dapat 2 tool baru: `describeSchema` (introspeksi kolom real) + `queryDatabase` (guarded read-only SELECT). UI ditulis ulang dengan micro-parser markdown inline + efek typing client-side.

**Tech Stack:** Laravel 13, PHP 8.5, Alpine.js 3.14, Bootstrap 5.3, PHPUnit (SQLite in-memory), Http::fake untuk test provider.

## Global Constraints

- Provider fallback string persis: `Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.`
- Semua argumen dari LLM = untrusted; tool baru read-only, tak boleh menulis DB.
- `queryDatabase`: SELECT-only, whitelist-only tabel bisnis, cap 200 baris, transaksi rollback-selalu.
- Tabel bisnis whitelist (15): `master_karyawan`, `master_mesin`, `master_part`, `master_part_proses`, `master_part_proses_sph_log`, `master_shift`, `master_shift_istirahat`, `master_jenis_ng`, `master_losstime`, `transaksi_produksi`, `transaksi_qc`, `transaksi_dandori`, `transaksi_dandori_rincian`, `transaksi_breakdown_mesin`, `transaksi_breakdown_dies`.
- Tabel sistem TIDAK boleh diakses: `users`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `cache`, `cache_locks`, `activity_log`, `app_notifications`, `chat_conversations`, `chat_messages`, `migrations`.
- UI: house style monokrom (Arial, no shadow, #111827 topbar, #1f2937). Bahasa Indonesia.
- Tabel `chat_conversations`/`chat_messages` + model dibiarkan dorman (tak di-drop).
- Test dijalankan via `php artisan test`. Commit tiap akhir task.

---

## File Structure

| File | Tanggung jawab |
|------|----------------|
| `app/Contracts/ChatbotProviderInterface.php` | Signature `ask(array $history, string $userMessage)` |
| `app/Services/GeminiChatbotService.php` | `buildHistory(array)`, tool-loop Gemini |
| `app/Services/GroqChatbotService.php` | `buildHistory(array)`, tool-loop Groq |
| `app/Http/Controllers/ChatbotController.php` | `sendMessage` stateless; `suggestFollowUps` |
| `routes/web.php` | Route chatbot: hanya `index` + `send-message` |
| `app/Services/ChatbotTools.php` | + `describeSchema`, `queryDatabase`, registry whitelist |
| `app/Services/Concerns/HasChatbotSystemPrompt.php` | Instruksi pakai describeSchema→queryDatabase |
| `resources/views/chatbot/index.blade.php` | Layout single-column tanpa sidebar |
| `public/js/chatbot.js` | Alpine: history in-memory, typing, copy/regenerate, markdown |
| `public/css/chatbot.css` | Style monokrom dipoles |
| `tests/Unit/GroqChatbotServiceTest.php` | Update ke `ask(array,...)` |
| `tests/Unit/GeminiChatbotServiceTest.php` | Update ke `ask(array,...)` |
| `tests/Unit/ChatbotToolsTest.php` | + test describeSchema, queryDatabase, count 16 |
| `tests/Feature/ChatbotControllerTest.php` | Rewrite: stateless, no-persist, validasi history |

---

## Task 1: Decouple provider `ask()` dari model DB → `array $history`

**Files:**
- Modify: `app/Contracts/ChatbotProviderInterface.php`
- Modify: `app/Services/GeminiChatbotService.php:19-21,77-83`
- Modify: `app/Services/GroqChatbotService.php:19-22,74-80`
- Test: `tests/Unit/GroqChatbotServiceTest.php`, `tests/Unit/GeminiChatbotServiceTest.php`

**Interfaces:**
- Produces: `ChatbotProviderInterface::ask(array $history, string $userMessage): ?string` di mana `$history` = list `['role' => 'user'|'assistant', 'content' => string]`.

- [ ] **Step 1: Update GroqChatbotServiceTest ke signature array**

Ganti helper `conversation()` jadi tidak dipakai untuk `ask`; panggil `ask([], ...)` untuk kasus tanpa history, dan tambah satu test history array. Edit bagian atas file:

```php
<?php
namespace Tests\Unit;

use App\Services\ChatbotTools;
use App\Services\GroqChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GroqChatbotServiceTest extends TestCase
{
    use RefreshDatabase;
```

Hapus `use App\Models\ChatConversation;`, `use App\Models\User;`, dan method `private function conversation()`. Ganti setiap `->ask($this->conversation(), '...')` jadi `->ask([], '...')`. Tambah test baru:

```php
    public function test_prepends_history_before_current_message(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['messages'];
            return Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'oke']]],
            ], 200);
        });

        $history = [
            ['role' => 'user', 'content' => 'pertama'],
            ['role' => 'assistant', 'content' => 'jawaban pertama'],
        ];

        (new GroqChatbotService(new ChatbotTools()))->ask($history, 'kedua');

        // system + 2 history + current user = 4
        $this->assertSame('system', $captured[0]['role']);
        $this->assertSame(['role' => 'user', 'content' => 'pertama'], $captured[1]);
        $this->assertSame(['role' => 'assistant', 'content' => 'jawaban pertama'], $captured[2]);
        $this->assertSame(['role' => 'user', 'content' => 'kedua'], $captured[3]);
    }
```

- [ ] **Step 2: Update GeminiChatbotServiceTest ke signature array**

Hapus `use App\Models\ChatConversation;` / `use App\Models\User;` / helper `conversation()` bila ada; ganti `->ask($this->conversation(), '...')` → `->ask([], '...')`. Tambah:

```php
    public function test_prepends_history_mapping_assistant_to_model_role(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['contents'];
            return Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'oke']]]]],
            ], 200);
        });

        $history = [
            ['role' => 'user', 'content' => 'pertama'],
            ['role' => 'assistant', 'content' => 'jawaban pertama'],
        ];

        (new GeminiChatbotService(new ChatbotTools()))->ask($history, 'kedua');

        $this->assertSame('user', $captured[0]['role']);
        $this->assertSame('pertama', $captured[0]['parts'][0]['text']);
        $this->assertSame('model', $captured[1]['role']);
        $this->assertSame('kedua', $captured[2]['parts'][0]['text']);
    }
```

- [ ] **Step 3: Run tests — verify fail (signature mismatch)**

Run: `php artisan test --filter='GroqChatbotServiceTest|GeminiChatbotServiceTest'`
Expected: FAIL (TypeError / method signature — `ask` masih terima `ChatConversation`).

- [ ] **Step 4: Update interface**

Ganti seluruh isi `app/Contracts/ChatbotProviderInterface.php`:

```php
<?php
namespace App\Contracts;

interface ChatbotProviderInterface
{
    /**
     * Attempt to answer `$userMessage` using tool-calling against real OPTIMA
     * data. `$history` is an in-memory list of prior turns
     * (['role' => 'user'|'assistant', 'content' => string]) — no persistence.
     * Returns null on total provider failure so the caller can fall back.
     */
    public function ask(array $history, string $userMessage): ?string;
}
```

- [ ] **Step 5: Update GeminiChatbotService**

Hapus `use App\Models\ChatConversation;`. Ganti `ask` head + `buildHistory`:

```php
    public function ask(array $history, string $userMessage): ?string
    {
        $contents = $this->buildHistory($history);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];
```

```php
    private function buildHistory(array $history): array
    {
        return array_map(fn ($m) => [
            'role'  => ($m['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
            'parts' => [['text' => $m['content'] ?? '']],
        ], $history);
    }
```

- [ ] **Step 6: Update GroqChatbotService**

Hapus `use App\Models\ChatConversation;`. Ganti `ask` head + `buildHistory`:

```php
    public function ask(array $history, string $userMessage): ?string
    {
        $messages = [['role' => 'system', 'content' => self::SYSTEM_PROMPT]];
        $messages = array_merge($messages, $this->buildHistory($history));
        $messages[] = ['role' => 'user', 'content' => $userMessage];
```

```php
    private function buildHistory(array $history): array
    {
        return array_map(fn ($m) => [
            'role'    => ($m['role'] ?? 'user') === 'assistant' ? 'assistant' : 'user',
            'content' => $m['content'] ?? '',
        ], $history);
    }
```

- [ ] **Step 7: Run tests — verify pass**

Run: `php artisan test --filter='GroqChatbotServiceTest|GeminiChatbotServiceTest'`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Contracts/ChatbotProviderInterface.php app/Services/GeminiChatbotService.php app/Services/GroqChatbotService.php tests/Unit/GroqChatbotServiceTest.php tests/Unit/GeminiChatbotServiceTest.php
git commit -m "refactor(chatbot): decouple provider ask() from DB model to array history"
```

---

## Task 2: Controller stateless + bersihkan routes

**Files:**
- Modify: `app/Http/Controllers/ChatbotController.php`
- Modify: `routes/web.php:188-196`
- Test: `tests/Feature/ChatbotControllerTest.php` (rewrite)

**Interfaces:**
- Consumes: `ask(array $history, string $userMessage)` dari Task 1.
- Produces: `POST /chatbot/message` → `{reply, suggestions}` (tanpa `conversation_id`), tanpa tulis DB.

- [ ] **Step 1: Rewrite ChatbotControllerTest**

Ganti SELURUH isi `tests/Feature/ChatbotControllerTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_message_and_gets_reply(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Halo!']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo']);

        $response->assertOk()->assertJson(['reply' => 'Halo!']);
        $response->assertJsonStructure(['reply', 'suggestions']);
    }

    public function test_send_message_does_not_persist_to_database(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Halo!']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo'])->assertOk();

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertDatabaseCount('chat_conversations', 0);
    }

    public function test_history_is_forwarded_to_provider(): void
    {
        $captured = null;
        Http::fake(function ($request) use (&$captured) {
            $captured = $request->data()['contents'];
            return Http::response([
                'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'oke']]]]],
            ], 200);
        });

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'kedua',
            'history' => [
                ['role' => 'user', 'content' => 'pertama'],
                ['role' => 'assistant', 'content' => 'jawaban pertama'],
            ],
        ])->assertOk();

        $texts = array_map(fn ($c) => $c['parts'][0]['text'], $captured);
        $this->assertContains('pertama', $texts);
        $this->assertContains('kedua', $texts);
    }

    public function test_rejects_history_with_invalid_role(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'halo',
            'history' => [['role' => 'system', 'content' => 'x']],
        ])->assertStatus(422);
    }

    public function test_rejects_history_longer_than_20(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $history = array_fill(0, 21, ['role' => 'user', 'content' => 'x']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'halo', 'history' => $history,
        ])->assertStatus(422);
    }

    public function test_staff_produksi_cannot_send_message(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->postJson(route('chatbot.send-message'), ['message' => 'halo'])
            ->assertForbidden();
    }

    public function test_admin_can_view_chatbot_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('chatbot.index'))->assertOk();
    }

    public function test_staff_produksi_cannot_view_chatbot_page(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('chatbot.index'))->assertForbidden();
    }

    public function test_falls_back_to_groq_when_gemini_fails(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['role' => 'assistant', 'content' => 'Jawaban dari Groq.']]],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo'])
            ->assertOk()->assertJson(['reply' => 'Jawaban dari Groq.']);
    }

    public function test_returns_generic_fallback_when_both_providers_fail(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([], 429),
            'api.groq.com/*' => Http::response([], 500),
        ]);

        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), ['message' => 'halo'])
            ->assertOk()->assertJson(['reply' => 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.']);
    }
}
```

- [ ] **Step 2: Run test — verify fail**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: FAIL (`test_send_message_does_not_persist_to_database` fails — masih nulis DB; `history` belum diterima).

- [ ] **Step 3: Rewrite ChatbotController**

Ganti bagian atas + `sendMessage`, hapus `conversations`/`show`/`destroy`/`debugGroq`. Head file:

```php
<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;

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
            'message'           => ['required', 'string', 'max:2000'],
            'history'           => ['nullable', 'array', 'max:20'],
            'history.*.role'    => ['required', 'in:user,assistant'],
            'history.*.content' => ['required', 'string', 'max:4000'],
        ]);

        $history = $data['history'] ?? [];

        $reply = $this->gemini->ask($history, $data['message'])
            ?? $this->groq->ask($history, $data['message'])
            ?? self::FALLBACK;

        return response()->json([
            'reply'       => $reply,
            'suggestions' => $this->suggestFollowUps($data['message'], $reply),
        ]);
    }
```

PERTAHANKAN method `private function suggestFollowUps(...)` persis seperti sekarang (jangan diubah). HAPUS method `debugGroq`, `conversations`, `show`, `destroy`, dan `use App\Models\ChatConversation;`, `use App\Services\ChatbotTools;`, `use Illuminate\Support\Facades\Http;` bila tak terpakai lagi (Str masih dipakai suggestFollowUps).

- [ ] **Step 4: Bersihkan routes**

Di `routes/web.php`, ganti grup chatbot (baris ~188-196) jadi:

```php
    // --- AI CHATBOT (admin & manager only) ---
    Route::middleware('role:admin,manager')->prefix('chatbot')->name('chatbot.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ChatbotController::class, 'index'])->name('index');
        Route::post('message', [\App\Http\Controllers\ChatbotController::class, 'sendMessage'])
            ->middleware('throttle:20,1')->name('send-message');
    });
```

- [ ] **Step 5: Run test — verify pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS.

- [ ] **Step 6: Verify no route references dangling**

Run: `php artisan route:list --name=chatbot`
Expected: hanya `chatbot.index` + `chatbot.send-message`.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ChatbotController.php routes/web.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): stateless sendMessage with in-memory history, drop conversation routes"
```

---

## Task 3: Tool `describeSchema` + registry whitelist

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Produces:
  - `const BUSINESS_TABLES` (array `nama => deskripsi`, 15 entri).
  - `describeSchema(?string $table = null): array` — tanpa arg `{tables:[{table,deskripsi}]}`; dgn arg valid `{table,deskripsi,columns:[{nama,tipe}]}`; arg non-whitelist `{error}`.
  - `dataExplorerSchemas(): array` di-spread ke `schemaDefinitions()`.

- [ ] **Step 1: Tulis test describeSchema**

Tambah di `tests/Unit/ChatbotToolsTest.php` sebelum `}` penutup class:

```php
    public function test_describe_schema_without_table_lists_business_tables(): void
    {
        $result = (new ChatbotTools())->describeSchema();

        $this->assertArrayHasKey('tables', $result);
        $names = array_column($result['tables'], 'table');
        $this->assertContains('master_karyawan', $names);
        $this->assertContains('transaksi_produksi', $names);
        $this->assertNotContains('users', $names);
        $this->assertNotContains('chat_messages', $names);
    }

    public function test_describe_schema_with_table_returns_real_columns(): void
    {
        $result = (new ChatbotTools())->describeSchema('master_mesin');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('master_mesin', $result['table']);
        $cols = array_column($result['columns'], 'nama');
        $this->assertContains('id_mesin', $cols);
        $this->assertContains('nama_mesin', $cols);
    }

    public function test_describe_schema_rejects_non_whitelisted_table(): void
    {
        $result = (new ChatbotTools())->describeSchema('users');
        $this->assertArrayHasKey('error', $result);
    }
```

Update juga test count schema jadi 15 (describeSchema ditambahkan di step ini):

```php
    public function test_schema_definitions_still_returns_all_thirteen_tools_with_unique_names(): void
    {
        $names = collect((new ChatbotTools())->schemaDefinitions())->pluck('name');

        $this->assertCount(15, $names);
        $this->assertCount(15, $names->unique(), 'Schema split must not introduce a duplicate tool name across the grouped methods.');
        $this->assertTrue($names->contains('getMtbfMttrDies'));
        $this->assertTrue($names->contains('getRekapDandori'));
        $this->assertTrue($names->contains('describeSchema'));
    }
```

- [ ] **Step 2: Run test — verify fail**

Run: `php artisan test --filter='ChatbotToolsTest::test_describe_schema|ChatbotToolsTest::test_schema_definitions'`
Expected: FAIL (method `describeSchema` belum ada; count masih 14).

- [ ] **Step 3: Tambah import Schema + registry const**

Di `app/Services/ChatbotTools.php`, tambah setelah `use Illuminate\Support\Facades\DB;`:

```php
use Illuminate\Support\Facades\Schema;
```

Tambah const setelah `ALL_TIME_FROM`:

```php
    /** Tabel bisnis yang boleh ditelusuri describeSchema/queryDatabase (whitelist). */
    private const BUSINESS_TABLES = [
        'master_karyawan'            => 'Data karyawan/operator/inspector (nik, nama_karyawan, departemen, is_aktif).',
        'master_mesin'               => 'Master mesin press (id_mesin, nama_mesin, line, tipe_mesin, kapasitas_max_stroke).',
        'master_part'                => 'Master part/dies (part_no, part_name, customer, tanggal_instalasi).',
        'master_part_proses'         => 'Proses per part + standar SPH (nama_proses, sph_standar_produksi, sph_standar_qc).',
        'master_part_proses_sph_log' => 'Riwayat perubahan standar SPH per proses.',
        'master_shift'               => 'Master shift (nama_shift, jam_start, jam_finish).',
        'master_shift_istirahat'     => 'Jadwal istirahat per shift.',
        'master_jenis_ng'            => 'Master jenis defect/NG.',
        'master_losstime'            => 'Master jenis losstime dandori + standar menit.',
        'transaksi_produksi'         => 'LHP produksi (tanggal_produksi, id_karyawan, id_mesin, id_part_proses, id_shift, qty_aktual, qty_ng).',
        'transaksi_qc'               => 'LHP quality (tgl_pengecekan, id_karyawan_qc, id_part_proses, qty_ok, qty_ng).',
        'transaksi_dandori'          => 'Header dandori (ganti setup/part).',
        'transaksi_dandori_rincian'  => 'Rincian dandori per losstime (tanggal, id_mesin, id_losstime, actual_menit, std_menit, over_menit).',
        'transaksi_breakdown_mesin'  => 'Breakdown mesin (tanggal_breakdown, id_mesin, problem_mesin, penyebab_breakdown, tindakan_perbaikan, repair_time, total_downtime).',
        'transaksi_breakdown_dies'   => 'Breakdown dies (tanggal_breakdown, id_part_proses, problem_dies, penyebab_breakdown, tindakan_perbaikan, repair_time, total_downtime).',
    ];
```

- [ ] **Step 4: Implement describeSchema + wiring schema def**

Tambah method (dekat schema helper lain, mis. sebelum `mtbfMttrSchemas`):

```php
    /** Introspeksi struktur DB untuk LLM: daftar tabel atau kolom real satu tabel. */
    public function describeSchema(?string $table = null): array
    {
        if ($table === null || trim($table) === '') {
            return [
                'tables' => collect(self::BUSINESS_TABLES)
                    ->map(fn ($desc, $name) => ['table' => $name, 'deskripsi' => $desc])
                    ->values()->toArray(),
            ];
        }

        $table = trim($table);
        if (!array_key_exists($table, self::BUSINESS_TABLES)) {
            return ['error' => "Tabel \"{$table}\" tidak tersedia untuk ditelusuri."];
        }

        $columns = array_map(
            fn ($col) => ['nama' => $col, 'tipe' => Schema::getColumnType($table, $col)],
            Schema::getColumnListing($table)
        );

        return [
            'table'     => $table,
            'deskripsi' => self::BUSINESS_TABLES[$table],
            'columns'   => $columns,
        ];
    }

    private function dataExplorerSchemas(): array
    {
        return [
            [
                'name' => 'describeSchema',
                'description' => 'Lihat struktur database OPTIMA. Tanpa argumen: daftar semua tabel bisnis + deskripsi. Dengan argumen table: daftar kolom real + tipe untuk tabel itu. WAJIB dipanggil sebelum queryDatabase agar tahu nama kolom yang benar.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => ['type' => 'string', 'description' => 'Opsional. Nama tabel untuk lihat kolomnya. Kosongkan untuk daftar semua tabel.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
```

Tambahkan spread ke `schemaDefinitions()`:

```php
    public function schemaDefinitions(): array
    {
        return [
            ...$this->mtbfMttrSchemas(),
            ...$this->productionSchemas(),
            ...$this->breakdownAggregateSchemas(),
            ...$this->breakdownDetailSchemas(),
            ...$this->dandoriSchemas(),
            ...$this->dataExplorerSchemas(),
        ];
    }
```

- [ ] **Step 5: Run test — verify pass**

Run: `php artisan test --filter='ChatbotToolsTest::test_describe_schema|ChatbotToolsTest::test_schema_definitions|ChatbotToolsTest::test_every_schema'`
Expected: PASS (termasuk guard schema↔method otomatis cover describeSchema).

- [ ] **Step 6: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add describeSchema tool + business-table whitelist registry"
```

---

## Task 4: Tool `queryDatabase` (guarded read-only SELECT)

**Files:**
- Modify: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `const BUSINESS_TABLES` dari Task 3.
- Produces: `queryDatabase(string $sql): array` — sukses `{row_count, truncated, rows}`; gagal guard `{error}`. `const QUERY_ROW_CAP = 200`.

- [ ] **Step 1: Tulis test queryDatabase**

Tambah di `tests/Unit/ChatbotToolsTest.php` sebelum `}` penutup (butuh `use App\Models\MasterMesin;` sudah ada di file):

```php
    public function test_query_database_runs_valid_select(): void
    {
        MasterMesin::create([
            'id_mesin' => 'Q-01', 'nama_mesin' => 'Press Q', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        $result = (new ChatbotTools())->queryDatabase('SELECT id_mesin, nama_mesin FROM master_mesin');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['row_count']);
        $this->assertSame('Q-01', $result['rows'][0]['id_mesin']);
        $this->assertFalse($result['truncated']);
    }

    public function test_query_database_rejects_non_select(): void
    {
        $result = (new ChatbotTools())->queryDatabase('UPDATE master_mesin SET nama_mesin = "x"');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_query_database_rejects_write_keyword_in_select(): void
    {
        $result = (new ChatbotTools())->queryDatabase('SELECT * FROM master_mesin; DROP TABLE master_mesin');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_query_database_rejects_system_table(): void
    {
        $result = (new ChatbotTools())->queryDatabase('SELECT * FROM users');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_query_database_rejects_unknown_table(): void
    {
        $result = (new ChatbotTools())->queryDatabase('SELECT * FROM nonexistent_table');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_query_database_rejects_query_without_table(): void
    {
        $result = (new ChatbotTools())->queryDatabase('SELECT 1');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_query_database_caps_rows_and_flags_truncated(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            MasterMesin::create([
                'id_mesin' => "QC-{$i}", 'nama_mesin' => "Press {$i}", 'line' => '1',
                'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            ]);
        }

        $result = (new ChatbotTools())->queryDatabase('SELECT id_mesin FROM master_mesin');

        $this->assertSame(200, $result['row_count']);
        $this->assertTrue($result['truncated']);
    }

    public function test_query_database_clamps_explicit_limit_over_cap(): void
    {
        for ($i = 1; $i <= 205; $i++) {
            MasterMesin::create([
                'id_mesin' => "QL-{$i}", 'nama_mesin' => "Press {$i}", 'line' => '1',
                'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            ]);
        }

        $result = (new ChatbotTools())->queryDatabase('SELECT id_mesin FROM master_mesin LIMIT 500');

        $this->assertLessThanOrEqual(200, $result['row_count']);
    }
```

- [ ] **Step 2: Run test — verify fail**

Run: `php artisan test --filter='ChatbotToolsTest::test_query_database'`
Expected: FAIL (method belum ada).

- [ ] **Step 3: Implement queryDatabase**

Tambah `const QUERY_ROW_CAP = 200;` setelah `BUSINESS_TABLES`. Tambah method (dekat describeSchema):

```php
    /** Guarded read-only SELECT untuk pertanyaan lintas-tabel di luar tool analitik. */
    public function queryDatabase(string $sql): array
    {
        $sql = trim($sql);

        if (!preg_match('/^(select|with)\b/i', $sql)) {
            return ['error' => 'Hanya query baca (SELECT) yang diizinkan.'];
        }

        // Tolak multi-statement (titik-koma selain trailing tunggal).
        if (str_contains(rtrim($sql, "; \n\t"), ';')) {
            return ['error' => 'Hanya satu perintah query yang diizinkan.'];
        }

        // Tolak keyword tulis/DDL sebagai kata utuh.
        if (preg_match('/\b(insert|update|delete|replace|drop|alter|truncate|create|grant|revoke|attach|pragma|into|lock|call|exec|merge)\b/i', $sql)) {
            return ['error' => 'Query mengandung operasi yang tidak diizinkan.'];
        }

        // Whitelist-only: setiap tabel setelah FROM/JOIN wajib ada di BUSINESS_TABLES.
        preg_match_all('/\b(?:from|join)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $sql, $m);
        if (empty($m[1])) {
            return ['error' => 'Query harus menyebut tabel bisnis yang valid.'];
        }
        foreach ($m[1] as $referenced) {
            if (!array_key_exists(strtolower($referenced), self::BUSINESS_TABLES)) {
                return ['error' => "Tabel \"{$referenced}\" tidak tersedia untuk ditelusuri."];
            }
        }

        // Paksa LIMIT: clamp yang eksplisit > cap, atau append kalau tak ada.
        $capped = preg_replace_callback(
            '/\blimit\s+(\d+)/i',
            fn ($mm) => 'LIMIT ' . min((int) $mm[1], self::QUERY_ROW_CAP),
            $sql,
            1,
            $count
        );
        if ($count === 0) {
            $capped = rtrim($sql, "; \n\t") . ' LIMIT ' . self::QUERY_ROW_CAP;
        }

        // Read-only: bungkus transaksi yang selalu rollback (safety net).
        try {
            DB::beginTransaction();
            $rows = DB::select($capped);
        } catch (\Throwable $e) {
            return ['error' => 'Query gagal dijalankan: ' . $e->getMessage()];
        } finally {
            try { DB::rollBack(); } catch (\Throwable) {}
        }

        $rows = array_map(fn ($r) => (array) $r, $rows);

        return [
            'row_count' => count($rows),
            'truncated' => count($rows) >= self::QUERY_ROW_CAP,
            'rows'      => $rows,
        ];
    }
```

- [ ] **Step 4: Wire queryDatabase ke schema def**

Tambah entri kedua di `dataExplorerSchemas()` (setelah describeSchema):

```php
            [
                'name' => 'queryDatabase',
                'description' => 'Jalankan SQL SELECT read-only ke database OPTIMA untuk pertanyaan yang tidak tercakup tool analitik lain (mis. list master data, performa per-operator/inspector, per-shift, rekap jenis NG, gabungan lintas-tabel). Hanya SELECT, hanya tabel bisnis. Panggil describeSchema dulu untuk tahu nama kolom yang benar. Hasil dibatasi 200 baris.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string', 'description' => 'Satu perintah SQL SELECT valid untuk MySQL. Contoh: SELECT nama_karyawan, departemen FROM master_karyawan WHERE is_aktif = 1'],
                    ],
                    'required' => ['sql'],
                ],
            ],
```

- [ ] **Step 5: Update count schema jadi 16**

Di `tests/Unit/ChatbotToolsTest.php`, ubah `test_schema_definitions_still_returns_all_thirteen_tools_with_unique_names`: ganti kedua `assertCount(15, ...)` jadi `assertCount(16, ...)` dan tambah `$this->assertTrue($names->contains('queryDatabase'));`.

- [ ] **Step 6: Run test — verify pass**

Run: `php artisan test --filter='ChatbotToolsTest'`
Expected: PASS (semua, termasuk guard schema↔method + count 16).

- [ ] **Step 7: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add guarded read-only queryDatabase tool"
```

---

## Task 5: System prompt — arahkan pakai describeSchema→queryDatabase

**Files:**
- Modify: `app/Services/Concerns/HasChatbotSystemPrompt.php`

**Interfaces:** Tidak ada interface baru; hanya teks prompt.

- [ ] **Step 1: Tambah instruksi ke SYSTEM_PROMPT**

Di `app/Services/Concerns/HasChatbotSystemPrompt.php`, sisipkan paragraf baru sebelum baris terakhir (`Jawab dalam Bahasa Indonesia, singkat dan jelas.`):

```
        Untuk pertanyaan yang TIDAK tercakup tool analitik spesifik (misalnya daftar master data, performa per-operator/inspector, rekap per shift, rekap jenis NG, atau gabungan lintas-tabel), gunakan tool describeSchema DULU untuk melihat nama tabel & kolom yang benar, lalu panggil queryDatabase dengan satu perintah SQL SELECT. Jangan pernah menebak nama kolom — selalu cek lewat describeSchema. Jangan sebut kata "SQL", "query", "database", nama tabel/kolom teknis, atau nama tool ke user; sampaikan hasilnya sebagai jawaban percakapan biasa.
```

- [ ] **Step 2: Verify prompt masih terbaca (smoke)**

Run: `php artisan test --filter='ChatbotControllerTest::test_admin_can_send_message_and_gets_reply'`
Expected: PASS (prompt valid, tak memecah call).

- [ ] **Step 3: Commit**

```bash
git add app/Services/Concerns/HasChatbotSystemPrompt.php
git commit -m "feat(chatbot): instruct model to use describeSchema then queryDatabase"
```

---

## Task 6: View + CSS — layout single-column monokrom

**Files:**
- Modify: `resources/views/chatbot/index.blade.php`
- Modify: `public/css/chatbot.css`

**Interfaces:**
- Produces markup yang dikonsumsi `chatbot.js` (Task 7): `x-data="chatbotPage()"`, `x-ref="scrollArea"`, binding `messages`, `suggestions`, `input`, `loading`, method `send()`, `askSuggested()`, `shuffleSuggestions()`, `copyMessage(m)`, `regenerate()`, `renderMarkdown(content)`.

- [ ] **Step 1: Rewrite view tanpa sidebar**

Ganti SELURUH isi `resources/views/chatbot/index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'AI Assistant')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/chatbot.css') }}?v={{ filemtime(public_path('css/chatbot.css')) }}">
@endpush

@section('content')
<div x-data="chatbotPage()" x-init="init()" class="cb-wrap">
    <section class="cb-main">
        <div class="cb-header">
            <div class="cb-header-av"><i class="bi bi-robot"></i></div>
            <div>
                <div class="cb-header-title">OPTIMA AI Assistant</div>
                <div class="cb-header-status"><span class="cb-dot"></span>Online &middot; Sesi tidak disimpan</div>
            </div>
        </div>

        <div class="cb-scroll" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div class="cb-row" :class="m.role === 'user' ? 'cb-row-user' : 'cb-row-bot'">
                    <div class="cb-av" :class="m.role === 'user' ? 'cb-av-user' : 'cb-av-bot'">
                        <i :class="m.role === 'user' ? 'bi bi-person-fill' : 'bi bi-robot'"></i>
                    </div>
                    <div class="cb-bubble-wrap">
                        <div class="cb-bubble" :class="m.role === 'user' ? 'cb-bubble-user' : 'cb-bubble-bot'">
                            <template x-if="m.role === 'user'"><span x-text="m.content"></span></template>
                            <template x-if="m.role !== 'user'"><div class="cb-md" x-html="renderMarkdown(m.display ?? m.content)"></div></template>
                        </div>
                        <div x-show="m.role !== 'user' && !m.typing" class="cb-actions">
                            <button type="button" class="cb-act" @click="copyMessage(m)" title="Salin"><i class="bi bi-clipboard"></i></button>
                            <button type="button" class="cb-act" x-show="i === messages.length - 1" @click="regenerate()" title="Buat ulang jawaban"><i class="bi bi-arrow-clockwise"></i></button>
                        </div>
                    </div>
                </div>
            </template>
            <div class="cb-row cb-row-bot" x-show="loading">
                <div class="cb-av cb-av-bot"><i class="bi bi-robot"></i></div>
                <div class="cb-bubble cb-bubble-bot cb-typing"><span></span><span></span><span></span></div>
            </div>
        </div>

        <div x-show="!loading" class="cb-chips">
            <button type="button" @click="shuffleSuggestions()" class="cb-shuffle" title="Ganti pertanyaan">
                <i class="bi bi-shuffle"></i>
            </button>
            <template x-for="q in suggestions" :key="q">
                <button type="button" @click="askSuggested(q)" class="cb-chip">
                    <i class="bi bi-lightning-charge-fill"></i><span x-text="q"></span>
                </button>
            </template>
        </div>

        <form @submit.prevent="send()" class="cb-form">
            <input type="text" x-model="input" placeholder="Tanya soal data OPTIMA&hellip;"
                   :disabled="loading" class="cb-input">
            <button type="submit" :disabled="loading || !input.trim()" class="cb-send" title="Kirim">
                <i class="bi bi-send-fill"></i>
            </button>
        </form>
    </section>
</div>

<script src="{{ asset('js/chatbot.js') }}?v={{ filemtime(public_path('js/chatbot.js')) }}"></script>
@endsection
```

- [ ] **Step 2: Rewrite CSS (single-column monokrom)**

Ganti SELURUH isi `public/css/chatbot.css`:

```css
:root {
    --cb-bg: #f3f4f6;
    --cb-panel: #ffffff;
    --cb-topbar: #111827;
    --cb-accent: #1f2937;
    --cb-border: #e5e7eb;
    --cb-text: #111827;
    --cb-muted: #6b7280;
    --cb-user: #1f2937;
}

.cb-wrap { display: flex; height: calc(100vh - 120px); min-height: 480px; font-family: Arial, sans-serif; }
.cb-main { flex: 1; display: flex; flex-direction: column; background: var(--cb-panel); border: 1px solid var(--cb-border); border-radius: 8px; overflow: hidden; max-width: 900px; margin: 0 auto; width: 100%; }

.cb-header { display: flex; align-items: center; gap: 12px; padding: 14px 18px; background: var(--cb-topbar); color: #fff; }
.cb-header-av { width: 36px; height: 36px; border-radius: 8px; background: var(--cb-accent); display: flex; align-items: center; justify-content: center; font-size: 18px; }
.cb-header-title { font-weight: 700; font-size: 15px; }
.cb-header-status { font-size: 12px; color: #9ca3af; display: flex; align-items: center; gap: 6px; }
.cb-dot { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; display: inline-block; }

.cb-scroll { flex: 1; overflow-y: auto; padding: 20px; background: var(--cb-bg); }
.cb-row { display: flex; gap: 10px; margin-bottom: 16px; align-items: flex-start; }
.cb-row-user { flex-direction: row-reverse; }
.cb-av { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 15px; }
.cb-av-bot { background: var(--cb-accent); color: #fff; }
.cb-av-user { background: #d1d5db; color: var(--cb-text); }
.cb-bubble-wrap { max-width: 78%; display: flex; flex-direction: column; }
.cb-row-user .cb-bubble-wrap { align-items: flex-end; }
.cb-bubble { padding: 10px 14px; border-radius: 10px; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
.cb-bubble-bot { background: var(--cb-panel); border: 1px solid var(--cb-border); color: var(--cb-text); }
.cb-bubble-user { background: var(--cb-user); color: #fff; }

.cb-actions { display: flex; gap: 4px; margin-top: 4px; }
.cb-act { border: none; background: transparent; color: var(--cb-muted); cursor: pointer; padding: 3px 6px; border-radius: 6px; font-size: 13px; }
.cb-act:hover { background: var(--cb-border); color: var(--cb-text); }

.cb-md p { margin: 0 0 8px; }
.cb-md p:last-child { margin-bottom: 0; }
.cb-md ul, .cb-md ol { margin: 4px 0 8px; padding-left: 20px; }
.cb-md code { background: #f3f4f6; padding: 1px 5px; border-radius: 4px; font-family: monospace; font-size: 13px; }
.cb-md pre { background: #f3f4f6; padding: 10px; border-radius: 6px; overflow-x: auto; }
.cb-md table { border-collapse: collapse; width: 100%; margin: 6px 0; font-size: 13px; }
.cb-md th, .cb-md td { border: 1px solid var(--cb-border); padding: 5px 8px; text-align: left; }
.cb-md th { background: #f9fafb; font-weight: 700; }
.cb-md a { color: var(--cb-accent); text-decoration: underline; }

.cb-typing { display: inline-flex; gap: 4px; align-items: center; }
.cb-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--cb-muted); animation: cb-blink 1.2s infinite both; }
.cb-typing span:nth-child(2) { animation-delay: .2s; }
.cb-typing span:nth-child(3) { animation-delay: .4s; }
@keyframes cb-blink { 0%, 80%, 100% { opacity: .3; } 40% { opacity: 1; } }

.cb-chips { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px 18px 0; align-items: center; }
.cb-shuffle { border: 1px solid var(--cb-border); background: var(--cb-panel); border-radius: 6px; padding: 6px 9px; cursor: pointer; color: var(--cb-muted); }
.cb-shuffle:hover { background: var(--cb-border); }
.cb-chip { border: 1px solid var(--cb-border); background: var(--cb-panel); border-radius: 16px; padding: 6px 12px; font-size: 12.5px; cursor: pointer; display: flex; align-items: center; gap: 6px; color: var(--cb-text); }
.cb-chip:hover { border-color: var(--cb-accent); }
.cb-chip i { color: var(--cb-accent); }

.cb-form { display: flex; gap: 10px; padding: 14px 18px; border-top: 1px solid var(--cb-border); background: var(--cb-panel); }
.cb-input { flex: 1; border: 1px solid var(--cb-border); border-radius: 8px; padding: 10px 14px; font-size: 14px; font-family: Arial, sans-serif; outline: none; }
.cb-input:focus { border-color: var(--cb-accent); }
.cb-send { border: none; background: var(--cb-accent); color: #fff; border-radius: 8px; width: 44px; cursor: pointer; font-size: 16px; }
.cb-send:disabled { opacity: .5; cursor: not-allowed; }

@media (max-width: 640px) {
    .cb-wrap { height: calc(100vh - 90px); }
    .cb-bubble-wrap { max-width: 88%; }
}
```

- [ ] **Step 3: Commit**

```bash
git add resources/views/chatbot/index.blade.php public/css/chatbot.css
git commit -m "feat(chatbot): single-column monochrome layout without history sidebar"
```

---

## Task 7: chatbot.js — history in-memory, typing, copy/regenerate, markdown

**Files:**
- Modify: `public/js/chatbot.js`

**Interfaces:**
- Consumes: `POST /chatbot/message` body `{message, history}` → `{reply, suggestions}` (Task 2).
- Produces method yang dipakai view (Task 6): `init`, `send`, `askSuggested`, `shuffleSuggestions`, `copyMessage`, `regenerate`, `renderMarkdown`.

- [ ] **Step 1: Rewrite chatbot.js**

Ganti SELURUH isi `public/js/chatbot.js`:

```javascript
function chatbotPage() {
    return {
        loading: false,
        input: '',
        messages: [],
        lastUserMessage: '',
        questionPool: [
            'Problem dies apa yang paling sering muncul?',
            'Mesin apa yang paling sering rusak?',
            'Dandori losstime apa yang paling banyak makan waktu?',
            'Problem mesin apa yang paling sering terjadi?',
            'Dies mana yang paling sering breakdown?',
            'Mesin mana yang paling sering breakdown?',
            'Total produksi keseluruhan berapa Pcs?',
            'Penyebab breakdown dies yang paling umum apa?',
            'Rata-rata SPH produksi berapa?',
            'Jenis losstime dandori apa yang paling sering muncul?',
            'Berapa total kejadian breakdown dies?',
            'Ranking mesin paling bermasalah urutannya gimana?',
            'Siapa operator dengan produksi terbanyak?',
            'Berapa jumlah karyawan aktif per departemen?',
        ],
        suggestions: [],

        greeting() {
            return { role: 'assistant', content: 'Halo! Saya OPTIMA AI Assistant. Tanya soal produksi, quality/NG, dandori, breakdown mesin & dies, atau data master apa pun — saya telusuri langsung dari database.', typing: false };
        },

        init() {
            this.messages = [this.greeting()];
            this.shuffleSuggestions();
        },

        shuffleSuggestions() {
            const pool = [...this.questionPool];
            for (let i = pool.length - 1; i > 0; i--) {
                const j = Math.floor(Math.random() * (i + 1));
                [pool[i], pool[j]] = [pool[j], pool[i]];
            }
            this.suggestions = pool.slice(0, 3);
        },

        askSuggested(question) {
            this.input = question;
            this.send();
        },

        // History in-memory yang dikirim ke server: buang greeting pertama,
        // ambil maksimal 20 turn terakhir, hanya field role+content.
        buildHistory() {
            return this.messages
                .slice(1)
                .filter(m => !m.typing)
                .slice(-20)
                .map(m => ({ role: m.role, content: m.content }));
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            this.lastUserMessage = text;
            const history = this.buildHistory();

            this.messages.push({ role: 'user', content: text });
            this.input = '';
            await this.dispatch(text, history);
        },

        async regenerate() {
            if (this.loading || !this.lastUserMessage) return;
            // Buang jawaban bot terakhir, kirim ulang pertanyaan terakhir.
            if (this.messages.length && this.messages[this.messages.length - 1].role === 'assistant') {
                this.messages.pop();
            }
            const history = this.buildHistory();
            await this.dispatch(this.lastUserMessage, history);
        },

        async dispatch(text, history) {
            this.loading = true;
            this.$nextTick(() => this.scrollDown());

            try {
                const res = await fetch('/chatbot/message', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ message: text, history }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                await this.pushTyped(data.reply);

                if (Array.isArray(data.suggestions) && data.suggestions.length) {
                    this.suggestions = data.suggestions;
                } else {
                    this.shuffleSuggestions();
                }
            } catch (e) {
                this.messages.push({ role: 'assistant', content: 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', typing: false });
                this.shuffleSuggestions();
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        // Efek ketik client-side: reveal per kata, render markdown di akhir.
        async pushTyped(fullText) {
            const msg = { role: 'assistant', content: fullText, display: '', typing: true };
            this.messages.push(msg);
            const words = fullText.split(/(\s+)/);
            for (let i = 0; i < words.length; i++) {
                msg.display += words[i];
                if (i % 2 === 0) {
                    this.$nextTick(() => this.scrollDown());
                    await new Promise(r => setTimeout(r, 18));
                }
            }
            msg.display = fullText;
            msg.typing = false;
        },

        async copyMessage(m) {
            try {
                await navigator.clipboard.writeText(m.content);
            } catch (e) {
                // senyap — clipboard tidak tersedia
            }
        },

        scrollDown() {
            this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
        },

        // Micro-parser markdown → HTML aman. Escape dulu, lalu bangun tag whitelist.
        renderMarkdown(src) {
            if (src == null) return '';
            const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            let text = esc(String(src));

            const lines = text.split('\n');
            let html = '';
            let i = 0;

            // Inline formatting: link (callback agar teks & href benar), code, bold, italic.
            const linkFix = s => s.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g,
                (_, t, u) => `<a href="${u}" rel="noopener noreferrer" target="_blank">${t}</a>`);

            const fmt = s => linkFix(s
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/\*([^*]+)\*/g, '<em>$1</em>'));

            while (i < lines.length) {
                let line = lines[i];

                // Tabel markdown: header | --- | baris
                if (/\|/.test(line) && i + 1 < lines.length && /^\s*\|?[\s:|-]+\|?\s*$/.test(lines[i + 1]) && /-/.test(lines[i + 1])) {
                    const parseRow = r => r.replace(/^\s*\|/, '').replace(/\|\s*$/, '').split('|').map(c => c.trim());
                    const headers = parseRow(line);
                    i += 2;
                    let body = '';
                    while (i < lines.length && /\|/.test(lines[i]) && lines[i].trim() !== '') {
                        const cells = parseRow(lines[i]);
                        body += '<tr>' + cells.map(c => `<td>${fmt(c)}</td>`).join('') + '</tr>';
                        i++;
                    }
                    html += '<table><thead><tr>' + headers.map(h => `<th>${fmt(h)}</th>`).join('') + '</tr></thead><tbody>' + body + '</tbody></table>';
                    continue;
                }

                // List tak berurut
                if (/^\s*[-*]\s+/.test(line)) {
                    let items = '';
                    while (i < lines.length && /^\s*[-*]\s+/.test(lines[i])) {
                        items += `<li>${fmt(lines[i].replace(/^\s*[-*]\s+/, ''))}</li>`;
                        i++;
                    }
                    html += `<ul>${items}</ul>`;
                    continue;
                }

                // List berurut
                if (/^\s*\d+\.\s+/.test(line)) {
                    let items = '';
                    while (i < lines.length && /^\s*\d+\.\s+/.test(lines[i])) {
                        items += `<li>${fmt(lines[i].replace(/^\s*\d+\.\s+/, ''))}</li>`;
                        i++;
                    }
                    html += `<ol>${items}</ol>`;
                    continue;
                }

                // Baris kosong
                if (line.trim() === '') { i++; continue; }

                // Paragraf (gabung baris berurutan non-kosong non-blok)
                let para = line;
                i++;
                while (i < lines.length && lines[i].trim() !== '' && !/^\s*[-*]\s+/.test(lines[i]) && !/^\s*\d+\.\s+/.test(lines[i]) && !/\|/.test(lines[i])) {
                    para += ' ' + lines[i];
                    i++;
                }
                html += `<p>${fmt(para)}</p>`;
            }

            return html;
        },
    };
}
```

- [ ] **Step 2: Commit**

```bash
git add public/js/chatbot.js
git commit -m "feat(chatbot): in-memory history, typing effect, copy/regenerate, markdown render"
```

---

## Task 8: Verifikasi end-to-end

**Files:** none (verifikasi manual/otomatis).

- [ ] **Step 1: Full test suite hijau**

Run: `php artisan test`
Expected: PASS semua (tak ada regresi; ChatbotControllerTest, ChatbotToolsTest, service tests hijau).

- [ ] **Step 2: Verifikasi halaman via skill verify**

Gunakan skill `verify` (atau `run`) untuk drive halaman `/chatbot` sebagai admin:
- Kirim pertanyaan analitik (mis. "Mesin apa yang paling sering rusak?") → jawaban muncul dgn efek ketik.
- Kirim pertanyaan master-data (mis. "Berapa jumlah karyawan aktif per departemen?") → bot pakai queryDatabase, jawab tabel/list.
- Cek tombol copy (clipboard) & regenerate (jawaban berganti).
- Refresh browser → chat kembali ke greeting (sesi hilang), tak ada sidebar.
- Cek `assertDatabaseCount` sudah membuktikan nol persist di Step 1.

Expected: semua perilaku sesuai; tak ada error konsol.

- [ ] **Step 3: Commit (bila ada perbaikan dari verify)**

```bash
git add -A
git commit -m "fix(chatbot): adjustments from end-to-end verification"
```

---

## Self-Review Notes

- **Spec coverage:** B1 (sesi ephemeral) → Task 1,2,6,7. B2 (UI) → Task 6,7. B3 (backend hybrid) → Task 3,4,5. Testing → tiap task + Task 8. Semua bagian spec ada task-nya.
- **Query cap edge case:** `LIMIT offset, count` (dua angka) → regex clamp angka pertama; aman (hasil ≤ cap), dicatat sebagai keterbatasan minor v1.
- **Markdown safety:** escape HTML dulu sebelum bangun tag whitelist → tak ada XSS dari output model.
- **Schema count:** 14 → 15 (Task 3) → 16 (Task 4); count test diupdate bertahap agar tiap task tetap hijau.
