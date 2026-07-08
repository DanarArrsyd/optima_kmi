# AI Chatbot (Gemini) — Design Spec

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Tambah chatbot AI ke OPTIMA yang bisa jawab pertanyaan seputar data operasional (produksi, QC, dandori, maintenance) berdasarkan data DB asli — tanpa halusinasi angka.

**Architecture:** Gemini API (function-calling / tool-calling) + fixed set of PHP query functions. Gemini cuma memilih fungsi & parameter; semua angka yang tampil di jawaban selalu berasal dari hasil query Eloquent, bukan dari generasi teks bebas Gemini.

**Tech Stack:** Laravel `Http` facade (tanpa SDK tambahan) ke Gemini REST API, Eloquent, Alpine.js (widget frontend), MySQL (riwayat chat).

## Global Constraints

- Scope data: full scope (produksi, QC, dandori, maintenance/breakdown mesin & dies)
- Akses: hanya role `admin` dan `manager` (staff_produksi/staff_qc tidak bisa akses chatbot)
- UI: floating widget muncul di semua halaman (bukan halaman terpisah)
- Riwayat chat disimpan permanen ke DB per user
- MVP tools (fixed, bukan generic query builder): MTBF/MTTR lookup, rekap NG per proses, perbandingan performa produksi antar periode
- Non-halusinasi: setiap angka di jawaban WAJIB berasal dari tool call result, bukan dari teks bebas model
- `.env`: `GEMINI_API_KEY` — jangan pernah upload `.env` asli ke hosting (ikuti aturan project yang sudah ada)

---

## Arsitektur

```
User (widget) → POST /chatbot/message → ChatbotController
   → simpan pesan user ke chat_messages
   → GeminiChatbotService::ask(conversation)
        → kirim system prompt + histori + tool schema ke Gemini REST API
        → Gemini balikin function_call ATAU jawaban teks final
        → kalau function_call: panggil ChatbotTools::{fungsi}(...params)
             → jalankan query Eloquent asli (reuse service yang sudah ada)
             → kirim hasil balik ke Gemini sebagai tool response
             → ulangi (max 5 iterasi) sampai Gemini kasih jawaban teks final
   → simpan jawaban assistant ke chat_messages
   → return JSON ke frontend
```

Middleware `role:admin,manager` melindungi seluruh route grup `/chatbot/*`, mengikuti pola `role:admin` yang sudah dipakai di grup Master Data (`routes/web.php`).

## Komponen

**File baru:**
- `app/Services/GeminiChatbotService.php` — HTTP call ke Gemini API, tool-calling loop (hard cap 5 iterasi untuk cegah infinite loop)
- `app/Services/ChatbotTools.php` — kumpulan fungsi tool MVP:
  - `getMttrMtbf(string $diesOrMesin, string $bulan): array` — reuse `MaintenanceDiesDashboardService`/mesin equivalent
  - `getRekapNg(string $proses, string $periode): array` — reuse query dari `DashboardService`/QC data
  - `bandingkanProduksi(string $periode1, string $periode2): array` — rekap qty aktual & SPH dua periode
- `app/Http/Controllers/ChatbotController.php`
  - `index()` — render widget/halaman riwayat (opsional)
  - `sendMessage(Request $request)` — terima pesan user, panggil service, return jawaban
  - `history(Request $request)` — ambil riwayat percakapan user
- `app/Models/ChatConversation.php` — `belongsTo(User)`, `hasMany(ChatMessage)`
- `app/Models/ChatMessage.php` — `belongsTo(ChatConversation)`, kolom `role` (`user`|`assistant`), `content`
- Migration `create_chat_conversations_table` — `id`, `user_id` (FK), `timestamps`
- Migration `create_chat_messages_table` — `id`, `conversation_id` (FK), `role` (enum: user, assistant), `content` (text), `created_at`
- `resources/views/components/chatbot-widget.blade.php` — floating widget Alpine.js, di-include di `layouts/app.blade.php` dengan guard `@if(auth()->user()?->hasRole(['admin','manager']))`
- Route baru di `routes/web.php`, grup `Route::middleware('role:admin,manager')->prefix('chatbot')->name('chatbot.')`

**Config:**
- `.env` — `GEMINI_API_KEY=...` (ditambahkan manual di hosting, tidak lewat upload file `.env`)
- `config/services.php` — tambah entry `gemini.key` (baca dari env, pola standar Laravel)

## Data Flow (contoh: "Berapa MTBF dies BT-091A-3 bulan ini?")

1. Frontend POST `/chatbot/message` dengan `{ conversation_id, message }` (`conversation_id` null kalau percakapan baru → controller buat baris `chat_conversations` baru)
2. Controller simpan pesan user ke `chat_messages` (role=user)
3. `GeminiChatbotService::ask()` kirim ke Gemini: system prompt (konteks OPTIMA + instruksi "jawab HANYA berdasarkan hasil tool call, jangan mengarang angka") + histori percakapan + daftar tool schema (nama, deskripsi, parameter JSON schema per 3 fungsi MVP)
4. Gemini balikin `function_call: getMttrMtbf(dies: "BT-091A-3", bulan: "2026-07")`
5. Service panggil `ChatbotTools::getMttrMtbf(...)` — query asli lewat service dashboard yang sudah ada, hasil array angka MTBF/MTTR real
6. Hasil dikirim balik ke Gemini sebagai tool response (role: function/tool)
7. Gemini susun jawaban natural language dari angka tersebut (bukan generate angka sendiri)
8. Controller simpan jawaban ke `chat_messages` (role=assistant), return JSON `{ conversation_id, reply }` ke frontend
9. Widget render jawaban di UI chat

## Error Handling

- Gemini API timeout/gagal connect → catch exception, balas fallback: *"Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti."* — jangan expose exception message mentah ke user
- Tool dipanggil dengan parameter yang tidak ketemu di data (misal nama dies salah) → tool return struktur `{ error: "Dies X tidak ditemukan" }`, dikirim balik ke Gemini sebagai tool response supaya Gemini yang menyampaikan ke user secara natural
- Tool-calling loop melebihi 5 iterasi tanpa jawaban final → hentikan paksa, balas fallback yang sama seperti timeout
- Role selain admin/manager mengakses endpoint chatbot → 403 (ditangani otomatis oleh middleware `role:admin,manager`)
- Rate limit Gemini (meski di tier gratis) → catch response error, fallback message yang sama seperti timeout

## Testing

- Unit test `ChatbotTools` — mock/seed data breakdown & produksi di DB test, pastikan angka yang dikembalikan tiap fungsi akurat (paling penting — ini jaminan non-halusinasi)
- Feature test `ChatbotController` — mock response Gemini API (jangan hit API asli di test suite), pastikan tool-calling loop tereksekusi dan hasil tersimpan ke `chat_messages`
- Manual test: login sebagai `staff_produksi`/`staff_qc`, pastikan endpoint `/chatbot/*` return 403
- Manual test: tanya 3 contoh pertanyaan MVP di widget, verifikasi jawaban cocok dengan angka asli di dashboard yang sudah ada
