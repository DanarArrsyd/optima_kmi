# AI Chatbot — Full Page + Multi-Conversation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the floating chat-bubble widget with a dedicated full page at `/chatbot`, with a sidebar of past conversations ("Chat Baru" + switching between them).

**Architecture:** Backend (`GeminiChatbotService`, `ChatbotTools`) is unchanged. `ChatbotController` gains two read endpoints (`conversations`, `show`) and a page endpoint (`index`); loses the unused `history` endpoint. Frontend is a new Blade page with an Alpine.js component (CDN, no build step) that replaces `chatbot-widget.blade.php`.

**Tech Stack:** Laravel 13 (Blade, Eloquent), Alpine.js 3.14.1 (CDN), Bootstrap 5.3.3 / Bootstrap Icons, PHPUnit (`php artisan test`).

## Global Constraints

- Access: routes stay under `Route::middleware('role:admin,manager')` (`routes/web.php`) — `staff_produksi`/`staff_qc` must get 403
- No new DB columns — conversation title is derived on the fly from the first user message, `Str::limit(..., 40)`
- No conversation delete/rename in this iteration
- `POST /chatbot/message` gets `throttle:20,1`
- `GET /chatbot/conversations/{conversation}` on another user's conversation → 404 (not 403), consistent with the existing `sendMessage` behavior (`test_cannot_send_message_using_another_users_conversation_id` in `tests/Feature/ChatbotControllerTest.php:54`)

Spec: [docs/superpowers/specs/2026-07-08-ai-chatbot-fullpage-design.md](../specs/2026-07-08-ai-chatbot-fullpage-design.md)

---

## Task 1: `ChatMessage` touches parent conversation + `GET /chatbot/conversations` list endpoint

Conversations must sort newest-activity-first. Today `chat_conversations.updated_at` only changes when the conversation row itself is saved — never when a message is added to it (`ChatMessage` has no `$touches`). Fix that first, then build the list endpoint on top of correct ordering.

**Files:**
- Modify: `app/Models/ChatMessage.php`
- Modify: `app/Http/Controllers/ChatbotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChatbotControllerTest.php`

**Interfaces:**
- Produces: `GET /chatbot/conversations` (route name `chatbot.conversations`) → JSON array `[{id: int, title: string, updated_at: string}]`, newest `updated_at` first, scoped to `auth()->user()`

- [ ] **Step 1: Write failing test for `updated_at` touch**

Add to `tests/Feature/ChatbotControllerTest.php`:

```php
public function test_sending_message_touches_conversation_updated_at(): void
{
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Oke']]]]],
    ], 200)]);

    $admin = User::factory()->create(['role' => 'admin']);
    $conversation = ChatConversation::create(['user_id' => $admin->id]);
    $conversation->forceFill(['updated_at' => now()->subDay()])->save();
    $originalUpdatedAt = $conversation->updated_at;

    $this->actingAs($admin)->postJson(route('chatbot.send-message'), [
        'message' => 'halo', 'conversation_id' => $conversation->id,
    ])->assertOk();

    $this->assertTrue($conversation->fresh()->updated_at->gt($originalUpdatedAt));
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_sending_message_touches_conversation_updated_at`
Expected: FAIL — `updated_at` unchanged.

- [ ] **Step 3: Add `$touches` to `ChatMessage`**

In `app/Models/ChatMessage.php`, add the property (alongside the existing `$fillable`):

```php
    protected $fillable = ['conversation_id', 'role', 'content'];

    protected $touches = ['conversation'];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_sending_message_touches_conversation_updated_at`
Expected: PASS

- [ ] **Step 5: Write failing tests for the conversations list endpoint**

Add to `tests/Feature/ChatbotControllerTest.php`:

```php
public function test_conversations_list_returns_only_own_conversations_newest_first(): void
{
    $admin = User::factory()->create(['role' => 'admin']);
    $otherAdmin = User::factory()->create(['role' => 'admin']);

    $older = ChatConversation::create(['user_id' => $admin->id]);
    $older->messages()->create(['role' => 'user', 'content' => 'Pertanyaan pertama soal MTBF mesin M-01']);
    $older->forceFill(['updated_at' => now()->subHour()])->save();

    $newer = ChatConversation::create(['user_id' => $admin->id]);
    $newer->messages()->create(['role' => 'user', 'content' => 'Rekap NG bulan ini']);

    ChatConversation::create(['user_id' => $otherAdmin->id]);

    $response = $this->actingAs($admin)->getJson(route('chatbot.conversations'));

    $response->assertOk();
    $ids = array_column($response->json(), 'id');
    $this->assertSame([$newer->id, $older->id], $ids);
    $this->assertSame('Rekap NG bulan ini', $response->json()[0]['title']);
}

public function test_staff_produksi_cannot_list_conversations(): void
{
    $staff = User::factory()->create(['role' => 'staff_produksi']);

    $this->actingAs($staff)->getJson(route('chatbot.conversations'))->assertForbidden();
}
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: FAIL — `chatbot.conversations` route not defined.

- [ ] **Step 7: Add the route**

In `routes/web.php`, replace the chatbot route group:

```php
    // --- AI CHATBOT (admin & manager only) ---
    Route::middleware('role:admin,manager')->prefix('chatbot')->name('chatbot.')->group(function () {
        Route::post('message', [\App\Http\Controllers\ChatbotController::class, 'sendMessage'])
            ->middleware('throttle:20,1')->name('send-message');
        Route::get('conversations', [\App\Http\Controllers\ChatbotController::class, 'conversations'])->name('conversations');
        Route::get('history',  [\App\Http\Controllers\ChatbotController::class, 'history'])->name('history');
    });
```

(The `throttle` on `send-message` and the `history` removal are both handled in Task 3 — leave `history` in place for now so Task 3's diff is self-contained.)

- [ ] **Step 8: Add the `conversations()` controller method**

In `app/Http/Controllers/ChatbotController.php`, add the `use` import and method:

```php
use Illuminate\Support\Str;
```

```php
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
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS (all tests in the file, including the pre-existing ones)

- [ ] **Step 10: Commit**

```bash
git add app/Models/ChatMessage.php app/Http/Controllers/ChatbotController.php routes/web.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): add conversations list endpoint, touch parent on new message"
```

---

## Task 2: `GET /chatbot/conversations/{conversation}` show endpoint

**Files:**
- Modify: `app/Http/Controllers/ChatbotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChatbotControllerTest.php`

**Interfaces:**
- Consumes: `ChatConversation` model (`app/Models/ChatConversation.php`), `messages()` relation (`app/Models/ChatConversation.php:20-23`)
- Produces: `GET /chatbot/conversations/{conversation}` (route name `chatbot.conversations.show`) → JSON `[{role: 'user'|'assistant', content: string}]`; 404 if the conversation doesn't belong to the authenticated user

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/ChatbotControllerTest.php`:

```php
public function test_show_returns_messages_for_own_conversation(): void
{
    $admin = User::factory()->create(['role' => 'admin']);
    $conversation = ChatConversation::create(['user_id' => $admin->id]);
    $conversation->messages()->create(['role' => 'user', 'content' => 'halo']);
    $conversation->messages()->create(['role' => 'assistant', 'content' => 'Halo juga!']);

    $response = $this->actingAs($admin)->getJson(route('chatbot.conversations.show', $conversation));

    $response->assertOk()->assertExactJson([
        ['role' => 'user', 'content' => 'halo'],
        ['role' => 'assistant', 'content' => 'Halo juga!'],
    ]);
}

public function test_show_returns_404_for_another_users_conversation(): void
{
    $admin1 = User::factory()->create(['role' => 'admin']);
    $admin2 = User::factory()->create(['role' => 'admin']);
    $conversation = ChatConversation::create(['user_id' => $admin1->id]);

    $this->actingAs($admin2)->getJson(route('chatbot.conversations.show', $conversation))->assertNotFound();
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: FAIL — `chatbot.conversations.show` route not defined.

- [ ] **Step 3: Add the route**

In `routes/web.php`, add inside the `chatbot.` group (after the `conversations` route added in Task 1):

```php
        Route::get('conversations/{conversation}', [\App\Http\Controllers\ChatbotController::class, 'show'])->name('conversations.show');
```

- [ ] **Step 4: Add the `show()` controller method**

In `app/Http/Controllers/ChatbotController.php`, add the `ChatConversation` route-model-bound method:

```php
    public function show(Request $request, ChatConversation $conversation): JsonResponse
    {
        abort_if($conversation->user_id !== $request->user()->id, 404);

        return response()->json($conversation->messages->map(fn ($m) => [
            'role' => $m->role, 'content' => $m->content,
        ]));
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ChatbotController.php routes/web.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): add conversation show endpoint scoped to owner"
```

---

## Task 3: Remove `history` endpoint, add `GET /chatbot` page route

Task 1 already added `throttle:20,1` to `send-message`. This task removes the now-superseded `history` endpoint (dead code — the old widget never called it) and adds the page route the Blade view in Task 4 will use.

**Files:**
- Modify: `app/Http/Controllers/ChatbotController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ChatbotControllerTest.php`

**Interfaces:**
- Produces: `GET /chatbot` (route name `chatbot.index`) → renders `resources/views/chatbot/index.blade.php` (created in Task 4)

- [ ] **Step 1: Write failing tests for the index route's access control**

Add to `tests/Feature/ChatbotControllerTest.php`:

```php
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
```

- [ ] **Step 2: Remove the obsolete `history` test**

Delete `test_...` — search `tests/Feature/ChatbotControllerTest.php` for any test calling `route('chatbot.history')`. As of Task 1/2 none exists yet (the original test suite never tested `history`), so this step is a no-op check — confirm with:

Run: `grep -n "chatbot.history" tests/Feature/ChatbotControllerTest.php`
Expected: no output.

- [ ] **Step 3: Run new tests to verify they fail**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: FAIL — `chatbot.index` route not defined (and `chatbot/index.blade.php` doesn't exist, but the route failure will surface first).

- [ ] **Step 4: Update the route group — add `index`, remove `history`**

In `routes/web.php`, replace the chatbot route group with:

```php
    // --- AI CHATBOT (admin & manager only) ---
    Route::middleware('role:admin,manager')->prefix('chatbot')->name('chatbot.')->group(function () {
        Route::get('/', [\App\Http\Controllers\ChatbotController::class, 'index'])->name('index');
        Route::post('message', [\App\Http\Controllers\ChatbotController::class, 'sendMessage'])
            ->middleware('throttle:20,1')->name('send-message');
        Route::get('conversations', [\App\Http\Controllers\ChatbotController::class, 'conversations'])->name('conversations');
        Route::get('conversations/{conversation}', [\App\Http\Controllers\ChatbotController::class, 'show'])->name('conversations.show');
    });
```

- [ ] **Step 5: Add `index()`, remove `history()` in the controller**

In `app/Http/Controllers/ChatbotController.php`, add:

```php
    public function index(): \Illuminate\View\View
    {
        return view('chatbot.index');
    }
```

Remove the `history()` method entirely (it's dead code — no route points to it after Step 4).

- [ ] **Step 6: Create a placeholder view so the route resolves**

Create `resources/views/chatbot/index.blade.php` with a minimal valid page (Task 4 replaces this with the full UI):

```blade
@extends('layouts.app')
@section('title', 'AI Assistant')
@section('content')
<div>AI Assistant — placeholder, see Task 4</div>
@endsection
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS (all tests in the file)

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ChatbotController.php routes/web.php resources/views/chatbot/index.blade.php tests/Feature/ChatbotControllerTest.php
git commit -m "feat(chatbot): add page route, remove unused history endpoint"
```

---

## Task 4: Full chatbot page UI (Blade + Alpine)

Replaces the Task 3 placeholder with the real two-column page: sidebar of conversations + chat panel. Reuses the message-bubble markup and `send()` logic from the old widget (`resources/views/components/chatbot-widget.blade.php:1-84`, deleted in Task 6).

**Files:**
- Modify: `resources/views/chatbot/index.blade.php`

**Interfaces:**
- Consumes: `GET /chatbot/conversations` (Task 1), `GET /chatbot/conversations/{id}` (Task 2), `POST /chatbot/message` (existing — `resources/views/components/chatbot-widget.blade.php:60-67` shows the exact request/response shape: `{message, conversation_id}` → `{conversation_id, reply}`)

- [ ] **Step 1: Write the full page**

Replace the entire contents of `resources/views/chatbot/index.blade.php` with:

```blade
@extends('layouts.app')
@section('title', 'AI Assistant')
@section('content')
<div x-data="chatbotPage()" x-init="init()" style="display:flex;gap:16px;height:calc(100vh - 130px)">

    {{-- Sidebar --}}
    <div style="width:260px;flex-shrink:0;display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
        <div style="padding:12px;border-bottom:1px solid #e5e7eb">
            <button type="button" @click="newChat()"
                    style="width:100%;background:#111827;color:#fff;border:none;border-radius:6px;padding:8px;font-size:0.85rem;font-weight:600">
                <i class="bi bi-plus-lg me-1"></i>Chat Baru
            </button>
        </div>
        <div style="flex:1;overflow-y:auto;padding:6px">
            <template x-if="loadingConversations">
                <div style="padding:12px;color:#9ca3af;font-size:0.8rem">Memuat...</div>
            </template>
            <template x-for="c in conversations" :key="c.id">
                <button type="button" @click="selectConversation(c.id)"
                        :style="'width:100%;text-align:left;border:none;background:' + (c.id === activeId ? '#f3f4f6' : 'transparent') + ';padding:10px 12px;border-radius:6px;font-size:0.82rem;color:#111827;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis'"
                        x-text="c.title"></button>
            </template>
        </div>
    </div>

    {{-- Chat panel --}}
    <div style="flex:1;display:flex;flex-direction:column;background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;min-width:0">
        <div style="background:#111827;color:#fff;padding:14px 18px;font-weight:700;font-size:0.95rem">
            <i class="bi bi-robot me-2"></i>OPTIMA AI Assistant
        </div>

        <div style="flex:1;min-height:0;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:10px" x-ref="scrollArea">
            <template x-for="(m, i) in messages" :key="i">
                <div :style="m.role === 'user'
                        ? 'align-self:flex-end;background:#111827;color:#fff;padding:8px 14px;border-radius:10px;max-width:70%;font-size:0.88rem'
                        : 'align-self:flex-start;background:#f3f4f6;color:#111827;padding:8px 14px;border-radius:10px;max-width:70%;font-size:0.88rem'"
                     x-text="m.content"></div>
            </template>
            <div x-show="loading" style="align-self:flex-start;color:#9ca3af;font-size:0.85rem">Mikir...</div>
        </div>

        <form @submit.prevent="send()" style="display:flex;gap:8px;padding:12px;border-top:1px solid #e5e7eb">
            <input type="text" x-model="input" placeholder="Tanya soal data OPTIMA..."
                   :disabled="loading"
                   style="flex:1;border:1px solid #d1d5db;border-radius:6px;padding:8px 12px;font-size:0.88rem">
            <button type="submit" :disabled="loading || !input.trim()"
                    style="background:#111827;color:#fff;border:none;border-radius:6px;padding:8px 16px">
                <i class="bi bi-send"></i>
            </button>
        </form>
    </div>
</div>

<script>
function chatbotPage() {
    return {
        conversations: [],
        loadingConversations: true,
        activeId: null,
        loading: false,
        input: '',
        messages: [],

        greeting() {
            return { role: 'assistant', content: 'Halo! Saya OPTIMA AI Assistant. Tanya soal MTBF/MTTR mesin & dies, rekap NG, atau perbandingan produksi antar periode.' };
        },

        async init() {
            try {
                const res = await fetch('{{ route('chatbot.conversations') }}');
                this.conversations = await res.json();
            } finally {
                this.loadingConversations = false;
            }

            if (this.conversations.length > 0) {
                await this.selectConversation(this.conversations[0].id);
            } else {
                this.messages = [this.greeting()];
            }
        },

        newChat() {
            this.activeId = null;
            this.messages = [this.greeting()];
            this.input = '';
        },

        async selectConversation(id) {
            this.activeId = id;
            const res = await fetch(`/chatbot/conversations/${id}`);
            this.messages = await res.json();
            this.$nextTick(() => this.scrollDown());
        },

        async send() {
            const text = this.input.trim();
            if (!text || this.loading) return;

            const isNewConversation = this.activeId === null;

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
                    body: JSON.stringify({ message: text, conversation_id: this.activeId }),
                });
                const data = await res.json();
                this.activeId = data.conversation_id;
                this.messages.push({ role: 'assistant', content: data.reply });

                if (isNewConversation) {
                    this.conversations.unshift({
                        id: data.conversation_id,
                        title: text.length > 40 ? text.slice(0, 40) + '...' : text,
                        updated_at: new Date().toISOString(),
                    });
                }
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
@endsection
```

- [ ] **Step 2: Run the existing feature test suite to confirm nothing broke**

Run: `php artisan test --filter=ChatbotControllerTest`
Expected: PASS (this task only touches the view, not routes/controllers)

- [ ] **Step 3: Commit**

```bash
git add resources/views/chatbot/index.blade.php
git commit -m "feat(chatbot): build full chatbot page with conversation sidebar"
```

---

## Task 5: Nav link + remove old widget

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Delete: `resources/views/components/chatbot-widget.blade.php`

- [ ] **Step 1: Remove the widget include**

In `resources/views/layouts/app.blade.php`, delete line 1321:

```blade
    @include('components.chatbot-widget')
```

- [ ] **Step 2: Delete the widget file**

```bash
git rm resources/views/components/chatbot-widget.blade.php
```

- [ ] **Step 3: Add the nav section**

In `resources/views/layouts/app.blade.php`, insert immediately before the existing `@if(in_array($role, ['admin', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'manager']))` block that starts the "Input Data" section (line 917):

```blade
    @if(in_array($role, ['admin', 'manager']))
    <div class="nav-section">
        <span class="nav-section-label">AI Assistant</span>
        <a href="{{ route('chatbot.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'chatbot') ? 'active' : '' }}"
           data-tip="AI Assistant">
            <i class="bi bi-robot"></i>
            <span class="nav-text">AI Assistant</span>
        </a>
    </div>
    @endif

```

- [ ] **Step 4: Run the full test suite**

Run: `php artisan test`
Expected: PASS (no test touches this layout file directly, but this confirms nothing else broke)

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(chatbot): add AI Assistant nav link, remove floating widget"
```

---

## Task 6: Browser verification

Not a code-change task — confirms the feature works end-to-end in a real browser before calling this done. Requires `GEMINI_API_KEY` configured in `.env` (see `config/services.php`); if not available in this environment, run through the same steps and confirm the UI/network behavior up to the point of the Gemini call (the fallback message on failure is expected and acceptable for this check).

- [ ] **Step 1: Start the dev server and log in as an `admin` user**

- [ ] **Step 2: Navigate to `/chatbot` via the new "AI Assistant" nav link**

Confirm: page loads with sidebar + chat panel, no floating bubble on any other page.

- [ ] **Step 3: Send a message**

Confirm: message appears immediately, "Mikir..." shows, a reply appears (or the fallback message if Gemini isn't reachable in this environment), and the conversation appears at the top of the sidebar.

- [ ] **Step 4: Click "Chat Baru", send a second message**

Confirm: a second conversation appears above the first in the sidebar.

- [ ] **Step 5: Reload the page**

Confirm: the sidebar still lists both conversations, most recent selected, its messages loaded.

- [ ] **Step 6: Click the older conversation in the sidebar**

Confirm: its messages load and replace the chat panel content.

- [ ] **Step 7: Log in as a `staff_produksi` user and visit `/chatbot` directly**

Confirm: 403 response, and no "AI Assistant" link in the sidebar nav.

---

## Self-Review Notes

- **Spec coverage:** routes table, ownership 404 rule, title derivation, throttle, nav placement, widget removal, and the manual verification checklist from the spec are each covered by a task above.
- **`updated_at` touch:** not in the original spec explicitly, but required for the spec's "newest first" ordering to actually work — added to Task 1 with its own test.
- **Type/signature consistency:** `conversations()` returns `{id, title, updated_at}`; the Alpine `conversations` array and `newChat()`/`send()` push objects with the same three keys. `show()` returns `{role, content}[]`; `selectConversation()` assigns that directly to `messages`, matching the shape `send()`'s greeting object and pushed messages also use (`{role, content}`).
