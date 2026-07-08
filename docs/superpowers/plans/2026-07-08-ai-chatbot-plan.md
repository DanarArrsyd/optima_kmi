# AI Chatbot (Gemini) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Gemini-powered chatbot to OPTIMA that answers operational questions (MTBF/MTTR, NG rekap, production comparison) by calling fixed PHP tool functions against real Eloquent data — no number in a reply is ever generated freely by the model.

**Architecture:** `ChatbotController` receives a message, hands it to `GeminiChatbotService`, which runs a tool-calling loop against the Gemini `generateContent` REST API (via Laravel's `Http` facade, no SDK). Tool calls are dispatched to `ChatbotTools`, whose methods query existing models/services (`MaintenanceDiesDashboardService`, `MaintenanceDashboardService`, `TransaksiQc`, `TransaksiProduksi`). Conversation history persists in `chat_conversations` / `chat_messages`. A floating Alpine.js widget, visible only to `admin`/`manager`, is the sole UI surface (see spec: `docs/superpowers/specs/2026-07-08-ai-chatbot-design.md`).

**Tech Stack:** Laravel `Http` facade, Eloquent, Alpine.js, MySQL/SQLite (tests), PHPUnit with `RefreshDatabase`.

## Global Constraints

- Access restricted to `role:admin,manager` (middleware alias `role` → `App\Http\Middleware\CheckRole`, already registered in `bootstrap/app.php`)
- Every number in a chatbot reply MUST come from a `ChatbotTools` method result, never from free-form model text
- Conversation history persists permanently in DB, scoped per user
- Models use `SoftDeletes` (project convention — see `CLAUDE.md`)
- `GEMINI_API_KEY` read from `.env` via `config('services.gemini.key')` — never hardcode, never commit `.env`
- Tool-calling loop hard-caps at 5 iterations; anything beyond returns the same fallback message as an API failure
- Gemini model id: `gemini-2.0-flash` (configurable via `GEMINI_MODEL` env, default `gemini-2.0-flash`)
- Endpoint format: `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent?key={key}`

---

### Task 1: Migrations + Models (ChatConversation, ChatMessage)

**Files:**
- Create: `database/migrations/2026_07_08_120000_create_chat_conversations_table.php`
- Create: `database/migrations/2026_07_08_120100_create_chat_messages_table.php`
- Create: `app/Models/ChatConversation.php`
- Create: `app/Models/ChatMessage.php`
- Test: `tests/Unit/ChatConversationTest.php`

**Interfaces:**
- Produces: `ChatConversation::messages(): HasMany`, `ChatConversation::user(): BelongsTo`, `ChatMessage::conversation(): BelongsTo`, `ChatMessage` fillable `['conversation_id', 'role', 'content']` where `role` is `'user'|'assistant'`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatConversationTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_belongs_to_user_and_has_many_messages(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $user->id]);

        $conversation->messages()->create(['role' => 'user', 'content' => 'Berapa MTBF dies BT-091A-3?']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'MTBF-nya 120 jam.']);

        $this->assertTrue($conversation->user->is($user));
        $this->assertCount(2, $conversation->fresh()->messages);
        $this->assertSame('user', $conversation->messages->first()->role);
    }

    public function test_soft_delete_conversation_keeps_row_but_hides_from_query(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $user->id]);
        $id = $conversation->id;

        $conversation->delete();

        $this->assertSoftDeleted('chat_conversations', ['id' => $id]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ChatConversationTest`
Expected: FAIL — `Class "App\Models\ChatConversation" not found`

- [ ] **Step 3: Write the migrations**

`database/migrations/2026_07_08_120000_create_chat_conversations_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_conversations');
    }
};
```

`database/migrations/2026_07_08_120100_create_chat_messages_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->enum('role', ['user', 'assistant']);
            $table->text('content');
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
```

- [ ] **Step 4: Write the models**

`app/Models/ChatConversation.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatConversation extends Model
{
    use SoftDeletes;

    protected $fillable = ['user_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id')->orderBy('created_at');
    }
}
```

`app/Models/ChatMessage.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['conversation_id', 'role', 'content'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }
}
```

- [ ] **Step 5: Run migrations locally and run test to verify it passes**

Run: `DB_DATABASE=optima_db DB_USERNAME=root DB_PASSWORD= php artisan migrate`
Run: `php artisan test --filter=ChatConversationTest`
Expected: PASS (2/2)

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_08_120000_create_chat_conversations_table.php \
        database/migrations/2026_07_08_120100_create_chat_messages_table.php \
        app/Models/ChatConversation.php app/Models/ChatMessage.php \
        tests/Unit/ChatConversationTest.php
git commit -m "feat(chatbot): add chat_conversations/chat_messages tables and models"
```

---

### Task 2: ChatbotTools (fixed query functions)

**Files:**
- Create: `app/Services/ChatbotTools.php`
- Test: `tests/Unit/ChatbotToolsTest.php`

**Interfaces:**
- Consumes: `MaintenanceDiesDashboardService::compute(Carbon $from, Carbon $to, ?int $partId, ?string $customer): array` (existing, `app/Services/MaintenanceDiesDashboardService.php`), `MaintenanceDashboardService::compute(Carbon $from, Carbon $to, ?int $mesinId): array` (existing, `app/Services/MaintenanceDashboardService.php`), `App\Models\MasterPart`, `App\Models\MasterMesin`, `App\Models\TransaksiQc`, `App\Models\TransaksiProduksi`
- Produces: `ChatbotTools::getMtbfMttrDies(string $partNo, string $bulan): array`, `ChatbotTools::getMtbfMttrMesin(string $idMesin, string $bulan): array`, `ChatbotTools::getRekapNg(string $namaProses, string $periode): array`, `ChatbotTools::bandingkanProduksi(string $periode1, string $periode2): array` — every method returns `['error' => string]` on not-found input, otherwise a plain associative array of real numbers. `$bulan`/`$periode` format: `'Y-m'` e.g. `'2026-07'`.

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace Tests\Unit;

use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_mtbf_mttr_dies_returns_real_numbers_for_known_part(): void
    {
        $part = MasterPart::create([
            'part_no' => 'BT-091A-3', 'part_name' => 'Bracket', 'customer' => 'Toyota',
            'tanggal_instalasi' => '2026-01-01',
        ]);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 800, 'sph_standar_qc' => 400,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '11:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 2.0, 'total_downtime' => 3.0,
        ]);

        $result = (new ChatbotTools())->getMtbfMttrDies('BT-091A-3', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result[0]['count']);
        $this->assertEqualsWithDelta(2.0, $result[0]['mttr_jam'], 0.01);
    }

    public function test_get_mtbf_mttr_dies_returns_error_for_unknown_part(): void
    {
        $result = (new ChatbotTools())->getMtbfMttrDies('NOT-EXIST', '2026-07');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_mtbf_mttr_mesin_returns_real_numbers_for_known_mesin(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-01', 'nama_mesin' => 'Press 1', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => '2026-01-01',
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.5,
        ]);

        $result = (new ChatbotTools())->getMtbfMttrMesin('M-01', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['count']);
        $this->assertEqualsWithDelta(1.0, $result['mttr_jam'], 0.01);
    }

    public function test_get_rekap_ng_sums_qty_ng_for_proses_and_periode(): void
    {
        $part = MasterPart::create(['part_no' => 'PT-01', 'part_name' => 'Cover', 'customer' => 'Honda']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 600, 'sph_standar_qc' => 300,
        ]);
        TransaksiQc::create([
            'tgl_pengecekan' => '2026-07-10', 'tgl_produksi_part' => '2026-07-10',
            'id_shift' => null, 'id_karyawan_qc' => null, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_ok' => 90, 'qty_ng' => 10,
        ]);

        $result = (new ChatbotTools())->getRekapNg('BENDING', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(10, $result['total_ng']);
        $this->assertSame(100, $result['total_inspeksi']);
    }

    public function test_bandingkan_produksi_returns_both_periods_and_delta(): void
    {
        $part = MasterPart::create(['part_no' => 'PT-02', 'part_name' => 'Plate', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-15', 'id_shift' => null, 'id_karyawan' => null,
            'id_mesin' => null, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 3000, 'qty_ng' => 0,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-15', 'id_shift' => null, 'id_karyawan' => null,
            'id_mesin' => null, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'qty_ng' => 0,
        ]);

        $result = (new ChatbotTools())->bandingkanProduksi('2026-06', '2026-07');

        $this->assertSame(3000, $result['periode1']['total_qty_aktual']);
        $this->assertSame(4000, $result['periode2']['total_qty_aktual']);
        $this->assertSame(1000, $result['delta_qty_aktual']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: FAIL — `Class "App\Services\ChatbotTools" not found`

- [ ] **Step 3: Write the implementation**

`app/Services/ChatbotTools.php`:

```php
<?php
namespace App\Services;

use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use Illuminate\Support\Carbon;

class ChatbotTools
{
    public function __construct(
        private MaintenanceDiesDashboardService $diesDashboard = new MaintenanceDiesDashboardService(),
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService(),
    ) {}

    /** MTBF/MTTR untuk sebuah part/dies (per proses), dalam 1 bulan. */
    public function getMtbfMttrDies(string $partNo, string $bulan): array
    {
        $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
        if (!$part) {
            return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
        }

        [$from, $to] = $this->monthRange($bulan);
        if (!$from) {
            return ['error' => "Format bulan \"{$bulan}\" tidak valid, gunakan format YYYY-MM."];
        }

        $rows = $this->diesDashboard->compute($from, $to, $part->id);
        if (empty($rows)) {
            return ['error' => "Tidak ada data breakdown untuk part \"{$partNo}\" di periode {$bulan}."];
        }

        return array_map(fn ($r) => [
            'nama_proses'      => $r['nama_proses'],
            'count'            => $r['count'],
            'mttr_jam'         => $r['mttr_jam'],
            'mtbf_jam'         => $r['mtbf_jam'],
            'availability_pct' => $r['availability_pct'],
        ], $rows);
    }

    /** MTBF/MTTR untuk sebuah mesin, dalam 1 bulan. */
    public function getMtbfMttrMesin(string $idMesin, string $bulan): array
    {
        $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
        if (!$mesin) {
            return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
        }

        [$from, $to] = $this->monthRange($bulan);
        if (!$from) {
            return ['error' => "Format bulan \"{$bulan}\" tidak valid, gunakan format YYYY-MM."];
        }

        $rows = $this->mesinDashboard->compute($from, $to, $mesin->id);
        if (empty($rows)) {
            return ['error' => "Tidak ada data breakdown untuk mesin \"{$idMesin}\" di periode {$bulan}."];
        }

        return [
            'count'            => $rows[0]['count'],
            'mttr_jam'         => $rows[0]['mttr_jam'],
            'mtbf_jam'         => $rows[0]['mtbf_jam'],
            'availability_pct' => $rows[0]['availability_pct'],
        ];
    }

    /** Rekap qty NG untuk sebuah nama proses, dalam 1 bulan. */
    public function getRekapNg(string $namaProses, string $periode): array
    {
        [$from, $to] = $this->monthRange($periode);
        if (!$from) {
            return ['error' => "Format periode \"{$periode}\" tidak valid, gunakan format YYYY-MM."];
        }

        $q = TransaksiQc::whereHas('partProses', fn ($p) =>
                $p->whereRaw('LOWER(TRIM(nama_proses)) = ?', [strtolower(trim($namaProses))])
            )
            ->whereBetween('tgl_pengecekan', [$from->toDateString(), $to->toDateString()]);

        $totalOk = (int) $q->clone()->sum('qty_ok');
        $totalNg = (int) $q->clone()->sum('qty_ng');
        $total   = $totalOk + $totalNg;

        if ($total === 0) {
            return ['error' => "Tidak ada data QC untuk proses \"{$namaProses}\" di periode {$periode}."];
        }

        return [
            'nama_proses'     => $namaProses,
            'periode'         => $periode,
            'total_ok'        => $totalOk,
            'total_ng'        => $totalNg,
            'total_inspeksi'  => $total,
            'ng_rate_pct'     => round($totalNg / $total * 100, 2),
        ];
    }

    /** Bandingkan qty aktual & SPH rata-rata produksi antara 2 periode bulanan. */
    public function bandingkanProduksi(string $periode1, string $periode2): array
    {
        $p1 = $this->produksiSummary($periode1);
        $p2 = $this->produksiSummary($periode2);

        if (isset($p1['error']) || isset($p2['error'])) {
            return ['error' => $p1['error'] ?? $p2['error']];
        }

        return [
            'periode1'            => $p1,
            'periode2'            => $p2,
            'delta_qty_aktual'    => $p2['total_qty_aktual'] - $p1['total_qty_aktual'],
            'delta_avg_sph_aktual'=> $p2['avg_sph_aktual'] - $p1['avg_sph_aktual'],
        ];
    }

    private function produksiSummary(string $periode): array
    {
        [$from, $to] = $this->monthRange($periode);
        if (!$from) {
            return ['error' => "Format periode \"{$periode}\" tidak valid, gunakan format YYYY-MM."];
        }

        $rows = TransaksiProduksi::whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])->get();

        return [
            'periode'         => $periode,
            'total_qty_aktual'=> (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'  => $rows->count() > 0 ? (int) round($rows->avg(fn ($r) => $r->sph_aktual)) : 0,
        ];
    }

    private function monthRange(string $bulan): array
    {
        try {
            $from = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
            return [$from, $from->copy()->endOfMonth()];
        } catch (\Throwable) {
            return [null, null];
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotToolsTest`
Expected: PASS (5/5)

- [ ] **Step 5: Commit**

```bash
git add app/Services/ChatbotTools.php tests/Unit/ChatbotToolsTest.php
git commit -m "feat(chatbot): add ChatbotTools with 4 grounded query functions"
```

---

### Task 3: GeminiChatbotService (tool-calling loop)

**Files:**
- Create: `app/Services/GeminiChatbotService.php`
- Modify: `config/services.php` — add `gemini` entry
- Test: `tests/Unit/GeminiChatbotServiceTest.php`

**Interfaces:**
- Consumes: `ChatbotTools` (Task 2), `ChatConversation`/`ChatMessage` (Task 1), `Illuminate\Support\Facades\Http`
- Produces: `GeminiChatbotService::ask(ChatConversation $conversation, string $userMessage): string` — returns the assistant's final text reply, or a fallback string on any failure. Never throws.

- [ ] **Step 1: Add Gemini config**

In `config/services.php`, add before the closing `];`:

```php
    'gemini' => [
        'key'   => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
    ],
```

- [ ] **Step 2: Write the failing tests**

```php
<?php
namespace Tests\Unit;

use App\Models\ChatConversation;
use App\Models\User;
use App\Services\ChatbotTools;
use App\Services\GeminiChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiChatbotServiceTest extends TestCase
{
    use RefreshDatabase;

    private function conversation(): ChatConversation
    {
        $user = User::factory()->create(['role' => 'admin']);
        return ChatConversation::create(['user_id' => $user->id]);
    }

    public function test_returns_gemini_text_reply_directly_when_no_tool_call_needed(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'Halo, ada yang bisa gw bantu?']]],
                ]],
            ], 200),
        ]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Halo, ada yang bisa gw bantu?', $reply);
    }

    public function test_executes_tool_call_then_returns_final_text(): void
    {
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            if ($call === 1) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['role' => 'model', 'parts' => [[
                            'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'BENDING', 'periode' => '2026-07']],
                        ]]],
                    ]],
                ], 200);
            }
            return Http::response([
                'candidates' => [[
                    'content' => ['role' => 'model', 'parts' => [['text' => 'NG di proses BENDING bulan itu 0.']]],
                ]],
            ], 200);
        });

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'berapa NG bending bulan lalu?');

        $this->assertSame('NG di proses BENDING bulan itu 0.', $reply);
        Http::assertSentCount(2);
    }

    public function test_returns_fallback_message_when_gemini_api_fails(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', $reply);
    }

    public function test_returns_fallback_message_when_loop_exceeds_max_iterations(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['role' => 'model', 'parts' => [[
                    'functionCall' => ['name' => 'getRekapNg', 'args' => ['namaProses' => 'X', 'periode' => '2026-07']],
                ]]],
            ]],
        ], 200)]);

        $reply = (new GeminiChatbotService(new ChatbotTools()))->ask($this->conversation(), 'halo');

        $this->assertSame('Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.', $reply);
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `php artisan test --filter=GeminiChatbotServiceTest`
Expected: FAIL — `Class "App\Services\GeminiChatbotService" not found`

- [ ] **Step 4: Write the implementation**

`app/Services/GeminiChatbotService.php`:

```php
<?php
namespace App\Services;

use App\Models\ChatConversation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiChatbotService
{
    private const MAX_ITERATIONS = 5;
    private const FALLBACK = 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.';

    private const SYSTEM_PROMPT = <<<TEXT
        Kamu adalah asisten AI untuk OPTIMA, sistem MES pabrik stamping PT. Kenco Manufactur Indonesia.
        Jawab HANYA berdasarkan hasil pemanggilan tools yang tersedia. Jangan pernah mengarang angka.
        Jika tool mengembalikan 'error', sampaikan pesan error itu ke user secara natural.
        Jawab dalam Bahasa Indonesia, singkat dan jelas.
        TEXT;

    public function __construct(private ChatbotTools $tools) {}

    public function ask(ChatConversation $conversation, string $userMessage): string
    {
        $contents = $this->buildHistory($conversation);
        $contents[] = ['role' => 'user', 'parts' => [['text' => $userMessage]]];

        for ($i = 0; $i < self::MAX_ITERATIONS; $i++) {
            $response = $this->callGemini($contents);
            if ($response === null) {
                return self::FALLBACK;
            }

            $part = $response['candidates'][0]['content']['parts'][0] ?? null;
            if ($part === null) {
                return self::FALLBACK;
            }

            if (isset($part['text'])) {
                return $part['text'];
            }

            if (isset($part['functionCall'])) {
                $name = $part['functionCall']['name'];
                $args = $part['functionCall']['args'] ?? [];

                if (!method_exists($this->tools, $name)) {
                    return self::FALLBACK;
                }

                $result = $this->tools->{$name}(...$args);

                $contents[] = ['role' => 'model', 'parts' => [['functionCall' => $part['functionCall']]]];
                $contents[] = ['role' => 'function', 'parts' => [[
                    'functionResponse' => ['name' => $name, 'response' => $result],
                ]]];
                continue;
            }

            return self::FALLBACK;
        }

        return self::FALLBACK;
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
                    'tools'              => [['functionDeclarations' => $this->toolSchema()]],
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

    private function toolSchema(): array
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
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=GeminiChatbotServiceTest`
Expected: PASS (4/4)

- [ ] **Step 6: Commit**

```bash
git add config/services.php app/Services/GeminiChatbotService.php tests/Unit/GeminiChatbotServiceTest.php
git commit -m "feat(chatbot): add GeminiChatbotService with tool-calling loop"
```

---

### Task 4: ChatbotController + routes

**Files:**
- Create: `app/Http/Controllers/ChatbotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChatbotControllerTest.php`

**Interfaces:**
- Consumes: `GeminiChatbotService::ask()` (Task 3), `ChatConversation`/`ChatMessage` (Task 1)
- Produces: routes `chatbot.send-message` (POST `/chatbot/message`), `chatbot.history` (GET `/chatbot/history`), both behind `role:admin,manager`

- [ ] **Step 1: Write the failing tests**

```php
<?php
namespace Tests\Feature;

use App\Models\ChatConversation;
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
        $this->assertDatabaseHas('chat_messages', ['role' => 'user', 'content' => 'halo']);
        $this->assertDatabaseHas('chat_messages', ['role' => 'assistant', 'content' => 'Halo!']);
    }

    public function test_staff_produksi_cannot_access_chatbot(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->postJson(route('chatbot.send-message'), ['message' => 'halo']);

        $response->assertForbidden();
    }

    public function test_reusing_conversation_id_appends_to_same_conversation(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke']]]]],
        ], 200)]);

        $admin = User::factory()->create(['role' => 'admin']);
        $conversation = ChatConversation::create(['user_id' => $admin->id]);

        $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
            'message' => 'lanjut', 'conversation_id' => $conversation->id,
        ])->assertOk();

        $this->assertSame(2, $conversation->fresh()->messages()->count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: FAIL — route `chatbot.send-message` not defined

- [ ] **Step 3: Write the controller**

`app/Http/Controllers/ChatbotController.php`:

```php
<?php
namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Services\GeminiChatbotService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class ChatbotController extends Controller
{
    public function __construct(private GeminiChatbotService $svc) {}

    public function sendMessage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message'         => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'exists:chat_conversations,id'],
        ]);

        $conversation = $data['conversation_id']
            ? ChatConversation::where('user_id', $request->user()->id)->findOrFail($data['conversation_id'])
            : ChatConversation::create(['user_id' => $request->user()->id]);

        $conversation->messages()->create(['role' => 'user', 'content' => $data['message']]);

        $reply = $this->svc->ask($conversation->fresh('messages'), $data['message']);

        $conversation->messages()->create(['role' => 'assistant', 'content' => $reply]);

        return response()->json(['conversation_id' => $conversation->id, 'reply' => $reply]);
    }

    public function history(Request $request): JsonResponse
    {
        $conversation = ChatConversation::where('user_id', $request->user()->id)
            ->latest()->first();

        return response()->json([
            'conversation_id' => $conversation?->id,
            'messages'        => $conversation?->messages->map(fn ($m) => [
                'role' => $m->role, 'content' => $m->content,
            ]) ?? [],
        ]);
    }
}
```

- [ ] **Step 4: Add routes**

In `routes/web.php`, add this block right before the closing `});` of the top-level `auth`-protected group (after the `role:admin` maintenance group, before `require __DIR__.'/auth.php';`):

```php
    // --- AI CHATBOT (admin & manager only) ---
    Route::middleware('role:admin,manager')->prefix('chatbot')->name('chatbot.')->group(function () {
        Route::post('message', [\App\Http\Controllers\ChatbotController::class, 'sendMessage'])->name('send-message');
        Route::get('history',  [\App\Http\Controllers\ChatbotController::class, 'history'])->name('history');
    });
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS (3/3)

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ChatbotController.php routes/web.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): add ChatbotController and role-gated routes"
```

---

### Task 5: Floating widget frontend

**Files:**
- Create: `resources/views/components/chatbot-widget.blade.php`
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: `route('chatbot.send-message')`, `route('chatbot.history')` (Task 4)
- Produces: floating chat button + panel, visible only when `auth()->user()?->hasRole(['admin','manager'])`

- [ ] **Step 1: Write the widget component**

`resources/views/components/chatbot-widget.blade.php`:

```blade
<div x-data="chatbotWidget()" x-init="loadHistory()" style="position:fixed;bottom:20px;right:20px;z-index:1200">
    <button @click="open = !open"
            style="width:52px;height:52px;border-radius:50%;background:#111827;color:#fff;border:none;box-shadow:0 4px 14px rgba(0,0,0,.25);cursor:pointer;font-size:1.3rem">
        <i class="bi" :class="open ? 'bi-x-lg' : 'bi-robot'"></i>
    </button>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         style="position:absolute;bottom:64px;right:0;width:360px;max-width:calc(100vw - 40px);height:480px;
                background:#fff;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden">

        <div style="background:#111827;color:#fff;padding:12px 16px;font-weight:700;font-size:0.9rem">
            <i class="bi bi-robot me-2"></i>OPTIMA AI Assistant
        </div>

        <div class="flex-grow-1" style="flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div :style="m.role === 'user'
                        ? 'align-self:flex-end;background:#111827;color:#fff;padding:8px 12px;border-radius:10px;max-width:80%;font-size:0.85rem'
                        : 'align-self:flex-start;background:#f3f4f6;color:#111827;padding:8px 12px;border-radius:10px;max-width:80%;font-size:0.85rem'"
                     x-text="m.content"></div>
            </template>
            <div x-show="loading" style="align-self:flex-start;color:#9ca3af;font-size:0.8rem">Mikir...</div>
        </div>

        <form @submit.prevent="send()" style="display:flex;gap:6px;padding:10px;border-top:1px solid #e5e7eb">
            <input type="text" x-model="input" placeholder="Tanya soal data OPTIMA..."
                   :disabled="loading"
                   style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:6px 10px;font-size:0.85rem">
            <button type="submit" :disabled="loading || !input.trim()"
                    style="background:#111827;color:#fff;border:none;border-radius:6px;padding:6px 12px">
                <i class="bi bi-send"></i>
            </button>
        </form>
    </div>
</div>

<script>
function chatbotWidget() {
    return {
        open: false,
        loading: false,
        input: '',
        conversationId: null,
        messages: [],

        async loadHistory() {
            const res = await fetch('{{ route('chatbot.history') }}');
            const data = await res.json();
            this.conversationId = data.conversation_id;
            this.messages = data.messages || [];
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            this.messages.push({ role: 'user', content: text });
            this.input = '';
            this.loading = true;
            this.$nextTick(() => this.scrollDown());

            try {
                const res = await fetch('{{ route('chatbot.send-message') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify({ message: text, conversation_id: this.conversationId }),
                });
                const data = await res.json();
                this.conversationId = data.conversation_id;
                this.messages.push({ role: 'assistant', content: data.reply });
            } catch (e) {
                this.messages.push({ role: 'assistant', content: 'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.' });
            } finally {
                this.loading = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        scrollDown() {
            this.$refs.scrollArea.scrollTop = this.$refs.scrollArea.scrollHeight;
        },
    };
}
</script>
```

- [ ] **Step 2: Include the widget in the main layout**

In `resources/views/layouts/app.blade.php`, add immediately before `</body>` (around line 1310):

```blade
    @if(auth()->check() && auth()->user()->hasRole(['admin', 'manager']))
        @include('components.chatbot-widget')
    @endif
</body>
```

(Replace the existing bare `</body>` line with the snippet above — the `@if`/`@include` block goes right before it.)

- [ ] **Step 3: Verify CSRF meta tag exists**

Run: `grep -n 'csrf-token' resources/views/layouts/app.blade.php`
Expected: a `<meta name="csrf-token" content="{{ csrf_token() }}">` line already present in `<head>`. If missing, add it there — the widget's fetch call depends on it.

- [ ] **Step 4: Manual verification**

Run: `DB_DATABASE=optima_db DB_USERNAME=root DB_PASSWORD= php artisan serve` (or use existing XAMPP vhost)
- Log in as `admin` → floating robot icon appears bottom-right on every page
- Log in as `staff_produksi` → icon does NOT appear
- Click icon, ask "berapa MTBF dies BT-091A-3 bulan Juli 2026?" (using real seeded data) → reply should contain the real MTBF number from the DB, not a made-up one
- Reload the page → previous conversation history reloads via `loadHistory()`

- [ ] **Step 5: Commit**

```bash
git add resources/views/components/chatbot-widget.blade.php resources/views/layouts/app.blade.php
git commit -m "feat(chatbot): add floating AI assistant widget for admin/manager"
```

---

## Deployment Note (hosting)

After merging, on the hosting side (manual, per project convention — no `artisan migrate` on hosting):
1. Run the two migrations' SQL manually via phpMyAdmin (`chat_conversations`, `chat_messages` tables, straightforward `CREATE TABLE` — no data backfill needed since these are brand new tables)
2. Add `GEMINI_API_KEY=...` to the hosting `.env` (do NOT upload `.env` itself — edit via hosting file manager or phpMyAdmin-adjacent tool)
3. Upload all new files listed in each task above
