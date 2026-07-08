# AI Chatbot — Full Page + Multi-Conversation — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Supersedes:** the UI constraint in [2026-07-08-ai-chatbot-design.md](2026-07-08-ai-chatbot-design.md) ("UI: floating widget muncul di semua halaman"). Backend (`GeminiChatbotService`, `ChatbotTools`, Gemini tool-calling loop, DB schema) is unchanged and reused as-is.

**Goal:** Replace the floating chat-bubble widget with a dedicated full page (`/chatbot`), and add multi-conversation support (sidebar list of past chats + "New Chat"), matching the rest of OPTIMA's page-based navigation instead of an overlay.

**Tech Stack:** Same as existing — Blade + Alpine.js (CDN, no build step — project has no JS bundler), `fetch()` to JSON endpoints, MySQL for chat history.

## Global Constraints

- Access: `admin` and `manager` only (unchanged from original spec)
- No new DB columns — conversation title is derived on the fly from the first user message (`Str::limit(..., 40)`), not stored
- No conversation delete/rename in this iteration (YAGNI — add later if needed)
- Add `throttle:20,1` to `POST /chatbot/message` (gap found in original implementation: no rate limit on an endpoint that calls a paid external API)

---

## Routes (`routes/web.php`, same `role:admin,manager` group, prefix `chatbot`)

| Method | Path | Name | Purpose |
|---|---|---|---|
| GET | `/chatbot` | `chatbot.index` | Full page shell (Blade) |
| GET | `/chatbot/conversations` | `chatbot.conversations` | List user's conversations: `[{id, title, updated_at}]`, newest first |
| GET | `/chatbot/conversations/{conversation}` | `chatbot.conversations.show` | Messages for one conversation: `[{role, content}]` |
| POST | `/chatbot/message` | `chatbot.send-message` | Unchanged behavior, now `throttle:20,1` |

Removed: `GET /chatbot/history` (dead code today — widget never called it; replaced by the two endpoints above).

**Ownership check:** `conversations.show` and `sendMessage` (when `conversation_id` given) must scope to `ChatConversation::where('user_id', $request->user()->id)` — same pattern already used in `sendMessage`, applied consistently. Return 404 (via `findOrFail` on the scoped query) if the conversation belongs to another user, not 403 — avoids leaking existence of other users' conversation IDs.

## Controller changes (`app/Http/Controllers/ChatbotController.php`)

- `index()` — return `view('chatbot.index')`, no server-side data needed (page fetches everything via the JSON endpoints on mount)
- `conversations()` — new method:
  ```php
  ChatConversation::where('user_id', $request->user()->id)
      ->with(['messages' => fn ($q) => $q->oldest()->limit(1)])
      ->latest('updated_at')
      ->get()
      ->map(fn ($c) => [
          'id' => $c->id,
          'title' => Str::limit($c->messages->first()?->content ?? 'Percakapan baru', 40),
          'updated_at' => $c->updated_at,
      ]);
  ```
- `show(ChatConversation $conversation)` — route-model-bound, but must still verify `$conversation->user_id === $request->user()->id` (404 if not) before returning `$conversation->messages`. Route-model binding alone does not scope by user.
- `sendMessage()` — unchanged logic; only the route gets `throttle:20,1` added
- Remove `history()`

## View (`resources/views/chatbot/index.blade.php`)

`@extends('layouts.app')`, two-column flex layout inside `#main`:

- **Left (~260px):** "Chat Baru" button + scrollable list of conversations (title + relative time), active one highlighted
- **Right:** message list (scrollable, same bubble style as the old widget — dark for user, light gray for assistant) + input form pinned to bottom

Height: `calc(100vh - Npx)` on the outer flex container (N tuned in-browser against topbar height `var(--tb-h)` + `#main` padding, following the same pattern as `max-height: calc(100vh - Xpx)` used in existing scrollable tables, e.g. `resources/views/master/mesin/index.blade.php:182`).

**Alpine component `chatbotPage()`:**
- State: `conversations`, `activeId`, `messages`, `loading`, `loadingConversations`, `input`
- `init()` — `fetch` conversations list; if non-empty, auto-select the first (most recent) and load its messages; if empty, show empty/greeting state with `activeId = null`
- `newChat()` — `activeId = null`, `messages = [greeting]`, focus input
- `selectConversation(id)` — `fetch` messages for that id, set `activeId`
- `send()` — same request/response handling as the current widget (`resources/views/components/chatbot-widget.blade.php:50-77`); additionally, if `activeId` was `null` before the response, prepend the new conversation to `conversations` using the just-sent message (truncated) as title

## Navigation (`resources/views/layouts/app.blade.php`)

New `nav-section` "AI Assistant", inserted before the existing "Input Data" section (~line 917), gated `@if(in_array($role, ['admin', 'manager']))`:

```blade
<div class="nav-section">
    <span class="nav-section-label">AI Assistant</span>
    <a href="{{ route('chatbot.index') }}"
       class="nav-item-link {{ str_starts_with($uri, 'chatbot') ? 'active' : '' }}"
       data-tip="AI Assistant">
        <i class="bi bi-robot"></i>
        <span class="nav-text">AI Assistant</span>
    </a>
</div>
```

## Cleanup

- Delete `resources/views/components/chatbot-widget.blade.php`
- Remove `@include('components.chatbot-widget')` from `resources/views/layouts/app.blade.php:1321`

## Error Handling

Unchanged from the original spec for the Gemini call/tool-loop. New surface area:
- `conversations.show` on another user's conversation ID → 404 (not 403, per ownership-check note above)
- Throttle exceeded on `/chatbot/message` → Laravel's default 429 JSON response; frontend `send()` catch block already shows the generic fallback message on any non-OK response, no new frontend handling needed

## Testing

- Update `tests/Feature/ChatbotControllerTest.php`:
  - Remove/replace the old `history` test
  - `conversations()` returns only the authenticated user's conversations, newest first, with derived titles
  - `show()` returns messages for own conversation; returns 404 for another user's conversation ID
  - `sendMessage()` behavior test stays valid unchanged
  - Add a throttle test: 21st request within a minute gets 429 (or accept Laravel's throttle is trusted framework behavior and skip — decide during implementation if it's worth the test slowdown)
- Manual/browser verify (dev server): open `/chatbot`, send a message, confirm it appears in sidebar as new conversation, refresh page, confirm history persists and loads, switch between two conversations, confirm role-gate still 403s for `staff_produksi`/`staff_qc`
