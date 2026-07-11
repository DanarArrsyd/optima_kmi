# Chatbot Full-DB Revamp — Design

**Date:** 2026-07-10
**Page:** `/chatbot` (https://optima.kencomanufactur.co.id/public/chatbot)
**Status:** Approved, ready for implementation plan

## Goal

Rombak halaman chatbot OPTIMA jadi tiga hal:

1. **Sesi ephemeral** — hapus sidebar riwayat + persistensi. Refresh browser = sesi hilang. Nol simpan ke DB.
2. **UI/UX interaktif** — poles tampilan (industrial monochrome house style) dengan render markdown/tabel, efek streaming/typing, tombol copy + regenerate, chip saran kontekstual.
3. **Backend hybrid "bedah semua data"** — pertahankan 14 tool analitik yang ada + tambah tool query read-only aman + introspeksi schema, supaya bot bisa telusur semua tabel bisnis dan jawab pertanyaan lintas-tabel apa pun.

## Decisions (dari brainstorming)

| Keputusan | Pilihan |
|-----------|---------|
| Pendekatan backend #3 | Hybrid: typed tools + guarded query tool |
| Konteks sesi | Multi-turn in-memory (browser pegang history, server stateless) |
| Tabel chat DB | Biarkan dorman (tak di-drop, stop nulis) |
| Gaya UI | Industrial monochrome dipoles |
| Fitur UI | Markdown+tabel, streaming/typing, copy+regenerate, chip saran |
| Query tool safety | Opsi B — guarded raw read-only SELECT + `describeSchema` |
| Tool master-data bertipe | Skip di v1 — andalkan `queryDatabase` + `describeSchema` |

---

## Bagian 1 — Sesi Ephemeral

### Arsitektur
Server jadi **stateless**. Browser memegang array `messages` di memori Alpine. Tiap `send()` mengirim `{message, history[]}` di mana `history` = N turn terakhir (cap 20). Refresh browser = state Alpine hilang = sesi bersih. Tidak ada penulisan ke DB.

### Perubahan

**`app/Http/Controllers/ChatbotController.php`**
- `sendMessage`: buang pembuatan/penulisan `ChatConversation` & `ChatMessage`.
- Validasi baru:
  - `message`: `required|string|max:2000`
  - `history`: `nullable|array|max:20`
  - `history.*.role`: `required|in:user,assistant`
  - `history.*.content`: `required|string|max:4000`
- Teruskan `history` (array) ke `ask()`. Respons tetap `{reply, suggestions}` (drop `conversation_id`).
- Hapus method `conversations`, `show`, `destroy`, `debugGroq` (utang lama).

**`app/Contracts/ChatbotProviderInterface.php`**
- Ubah signature `ask(ChatConversation $conversation, string $userMessage): ?string`
  → `ask(array $history, string $userMessage): ?string`.

**`app/Services/GeminiChatbotService.php` + `GroqChatbotService.php`**
- `buildHistory()` baca `array $history` (list `{role, content}`), bukan model Eloquent. Peta `role` ke format tiap provider (Gemini: `user`/`model`; Groq: `user`/`assistant`).
- Sisa tool-loop tak berubah.

**`routes/web.php`**
- Grup `chatbot`: sisakan `index` (GET `/`) + `send-message` (POST `message`, throttle:20,1).
- Hapus route `conversations`, `conversations.show`, `conversations.destroy`, `debug-groq`.

**`resources/views/chatbot/index.blade.php`**
- Hapus seluruh `<aside class="cb-side">`.
- Layout single-column.

**`public/js/chatbot.js`**
- Buang state & method sidebar: `conversations`, `loadingConversations`, `activeId`, `selectConversation`, `deleteConversation`, `newChat`.
- `init()`: set greeting + `shuffleSuggestions()`.
- `send()`: kirim `{message, history}` di mana `history` = `messages` (mapping `{role,content}`, buang greeting, cap 20 terakhir). Tak lagi baca `conversation_id`.

**Dorman:** `ChatConversation`, `ChatMessage`, tabel `chat_conversations`/`chat_messages` tidak disentuh.

### Error handling
- History invalid → 422 (validate).
- Provider gagal → fallback string `'Maaf, sistem AI lagi ga bisa diakses, coba lagi nanti.'` (sama seperti sekarang).

---

## Bagian 2 — UI/UX

### Layout
Single-column chat penuh-lebar (tanpa sidebar):
- Header tipis: avatar bot + judul + status "Online".
- Scroll area bubble (user kanan, bot kiri).
- Chip saran di atas input.
- Form input sticky di bawah.
- Responsif mobile: bubble full-width, chip wrap.

### Fitur

**Render markdown + tabel**
- Balasan bot di-render markdown (bold, list, tabel, code).
- Parser markdown **vendored lokal** di `public/js/` (bukan CDN — konsistensi aset lokal + hosting). Kandidat: `marked` versi min, atau micro-parser kecil.
- **Sanitasi**: escape HTML mentah, izinkan hanya tag hasil-parse yang aman (p, ul/ol/li, strong/em, table/thead/tbody/tr/th/td, code/pre, br, a dengan rel noopener). Cegah XSS dari output model.
- Parse gagal → fallback plain text.

**Streaming / typing**
- Efek ketik **client-side** atas teks final (reveal per-kata/karakter dengan interval). Backend tetap non-stream (LLM call blocking + tool-loop). Tidak pakai SSE.
- Markdown di-render setelah teks lengkap muncul (atau progresif — implementasi pilih yang paling stabil; default: reveal plain lalu render markdown di akhir).

**Copy + regenerate**
- Tiap bubble bot: tombol copy (Clipboard API) + regenerate (kirim ulang pertanyaan user terakhir, ganti balasan bot terakhir). Clipboard gagal → senyap/toast kecil.

**Chip saran**
- Pertahankan `ChatbotController::suggestFollowUps` (rule-based) + `shuffleSuggestions` client.

### Style
- `public/css/chatbot.css` ditulis ulang: palet monokrom house (#111827 topbar, #1f2937, abu netral), Arial, no shadow, sudut halus, spacing rapi.

---

## Bagian 3 — Backend Hybrid

### Tetap
14 tool `ChatbotTools` yang ada (analitik cepat & terverifikasi test).

### Tool baru di `ChatbotTools`

**`describeSchema(?string $table = null): array`**
- Tanpa arg: daftar tabel bisnis (nama + deskripsi singkat) dari registry.
- Dengan arg: validasi table ∈ whitelist, lalu kembalikan kolom real via `Schema::getColumnListing($table)` + tipe (`Schema::getColumnType`). Read-only introspection. Defensive terhadap perbedaan kolom di hosting.
- Table non-whitelist → `{error: "..."}`.

**`queryDatabase(string $sql): array`** — guarded raw read-only SELECT:
- **Guard sintaks:**
  - Trim; harus diawali `SELECT` atau `WITH` (case-insensitive).
  - Tolak multi-statement: ada `;` selain trailing tunggal → error.
  - Regex tolak keyword tulis/DDL sebagai kata utuh: `INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|TRUNCATE|CREATE|GRANT|REVOKE|ATTACH|PRAGMA|INTO|LOCK|CALL|EXEC`.
- **Guard tabel (whitelist-only):** ekstrak semua identifier tabel setelah `FROM`/`JOIN` (regex), lalu **setiap** identifier wajib ∈ whitelist bisnis. Jika ada satu saja tabel di luar whitelist (termasuk tabel sistem apa pun atau nama tak dikenal) → tolak. Blacklist tabel sistem hanya backstop tambahan, bukan guard utama. Alias tabel diabaikan (yang dicek nama tabel sesudah FROM/JOIN, bukan alias).
- **Eksekusi aman:**
  - Paksa `LIMIT`: kalau tak ada `LIMIT`, append `LIMIT <cap>` (cap = 200). Kalau ada LIMIT > cap, bungkus/clamp.
  - Bungkus dalam `DB::transaction` yang **selalu rollback** (throw setelah select untuk rollback, atau `DB::beginTransaction()` + `DB::rollBack()` di finally).
  - Timeout pendek (PHP `set_time_limit`/statement timeout jika didukung).
- **Return:** `{columns: [...], rows: [...], row_count: N, truncated: bool}`. Error guard → `{error: "..."}`.
- Semua argumen dari LLM = untrusted.

### Schema registry
Satu sumber whitelist tabel bisnis + deskripsi, dipakai `describeSchema` & guard `queryDatabase`. Lokasi: konstanta/array privat di `ChatbotTools` (atau `config/chatbot_schema.php`).

Tabel bisnis (whitelist):
`master_karyawan`, `master_mesin`, `master_part`, `master_part_proses`, `master_part_proses_sph_log`, `master_shift`, `master_shift_istirahat`, `master_jenis_ng`, `master_losstime`, `transaksi_produksi`, `transaksi_qc`, `transaksi_dandori`, `transaksi_dandori_rincian`, `transaksi_breakdown_mesin`, `transaksi_breakdown_dies`.

Tabel sistem (TIDAK di-whitelist): `users`, `sessions`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `cache`, `cache_locks`, `activity_log`, `app_notifications`, `chat_conversations`, `chat_messages`, `migrations`.

### Schema definitions
Tambah `queryDatabase` + `describeSchema` ke `schemaDefinitions()` (grup baru, mis. `dataExplorerSchemas()`). Otomatis kena guard test `test_every_schema_name_maps_to_a_public_invokable_method` + `test_every_schema_matches_its_method_signature`.

### System prompt (`HasChatbotSystemPrompt`)
Tambah instruksi:
- Untuk pertanyaan di luar cakupan tool analitik (mis. list master data, performa per-operator, per-shift, jenis NG), panggil `describeSchema` dulu untuk tahu kolom real, lalu `queryDatabase` dengan SELECT yang tepat.
- Jangan tebak nama kolom — selalu cek via `describeSchema`.
- Tetap: jangan sebut nama tool/fungsi/SQL/kata "error" ke user; rangkum hasil jadi Bahasa Indonesia singkat & tepat; jangan mengarang angka.

### Dispatch
Tak berubah: `method_exists` + `$this->tools->{$name}(...$args)` di kedua service.

---

## Testing

**Bagian 1**
- `sendMessage` tanpa `conversation_id` → berhasil, `assertDatabaseCount('chat_messages', 0)` & `chat_conversations` = 0.
- Validasi `history` (role/content) ditolak saat invalid.
- Service `ask(array $history, ...)` membangun history provider yang benar (unit, mock HTTP).

**Bagian 3**
- `queryDatabase`: SELECT valid jalan + hasil di-cap ke 200 + `truncated` benar.
- Tolak `UPDATE`/`DROP`/`DELETE`/multi-`;`.
- Tolak referensi tabel non-whitelist: tabel sistem (`users`, `chat_messages`) DAN tabel tak dikenal (`nonexistent_table`).
- Rollback terbukti: query yang (secara teori) menulis tidak meninggalkan efek.
- `LIMIT` dipaksa saat absen; clamp saat > cap.
- `describeSchema()` tanpa arg → daftar tabel bisnis; dengan arg valid → kolom real; arg non-whitelist → error.
- Guard schema↔method existing otomatis mencakup 2 tool baru.

**UI**
- Manual + `/verify` (drive halaman: kirim pesan, cek render markdown/tabel, copy, regenerate, refresh = sesi bersih).

## File tersentuh
- `app/Http/Controllers/ChatbotController.php`
- `app/Contracts/ChatbotProviderInterface.php`
- `app/Services/GeminiChatbotService.php`
- `app/Services/GroqChatbotService.php`
- `app/Services/Concerns/HasChatbotSystemPrompt.php`
- `app/Services/ChatbotTools.php`
- `routes/web.php`
- `resources/views/chatbot/index.blade.php`
- `public/js/chatbot.js`
- `public/css/chatbot.css`
- `public/js/<markdown-parser>.js` (vendored)
- `tests/Unit/ChatbotToolsTest.php`, `tests/Feature/ChatbotControllerTest.php`, tests service

## Out of scope (v1)
- Tool master-data bertipe (andalkan `queryDatabase` + `describeSchema`).
- Drop tabel chat DB (biarkan dorman).
- SSE/streaming server-side nyata (efek ketik client-side saja).
- Persistensi/riwayat lintas-sesi.
