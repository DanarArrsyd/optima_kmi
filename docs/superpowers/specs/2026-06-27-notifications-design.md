# In-App Notification System — Design Spec
Date: 2026-06-27

## Overview

Add a bell-icon notification system to the topbar. Staff menerima notif saat note mereka di-resolve oleh admin. Admin menerima notif saat staff mengirim note baru. Notifikasi di-poll otomatis tiap 30 detik via JS fetch.

## Scope

Notif terhubung ke fitur `staff_note` yang sudah ada di 3 modul:
- LHP Produksi (`transaksi_produksi`)
- LHP Quality (`transaksi_qc`)
- Dandori (`transaksi_dandori_rincian`)

## Data Layer

### Tabel `app_notifications`

```sql
CREATE TABLE app_notifications (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type             VARCHAR(50)  NOT NULL,  -- 'note_submitted' | 'note_resolved'
    user_id          BIGINT UNSIGNED NOT NULL,  -- penerima notif
    actor_id         BIGINT UNSIGNED NOT NULL,  -- yang melakukan aksi
    notifiable_type  VARCHAR(20)  NOT NULL,  -- 'produksi' | 'qc' | 'dandori'
    notifiable_id    BIGINT UNSIGNED NOT NULL,
    data             JSON         NOT NULL,  -- {tanggal, nama_actor, preview_note}
    read_at          TIMESTAMP    NULL DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, read_at),
    FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Model `AppNotification`

- `app/Models/AppNotification.php`
- SoftDeletes: tidak perlu
- Fillable: semua kolom
- Casts: `data → array`, `read_at → datetime`
- Scopes: `scopeUnread()` → `whereNull('read_at')`, `scopeForUser($id)`
- Relations: `actor()` belongsTo User, `recipient()` belongsTo User

## Backend

### NotificationService

`app/Services/NotificationService.php` — centralized insertion logic.

```php
insertNoteSubmitted(Model $record, string $notifiableType): void
    // Insert notif ke semua user role='admin'
    // data: {tanggal: $record->tanggal, nama_actor: auth()->user()->name, preview_note: substr($record->staff_note, 0, 80)}

insertNoteResolved(Model $record, string $notifiableType): void
    // Insert notif ke user dengan id = $record->staff_note_by
    // data: {tanggal: $record->tanggal, nama_actor: auth()->user()->name}
```

### NotificationController

`app/Http/Controllers/NotificationController.php`

| Method | Route | Deskripsi |
|--------|-------|-----------|
| `poll()` | `GET /notifications/poll` | Return JSON `{count, items[]}` max 15 terbaru |
| `markRead($id)` | `POST /notifications/{id}/read` | Set `read_at = now()` |
| `markAllRead()` | `POST /notifications/read-all` | Bulk update semua unread milik user |

**`poll()` response shape:**
```json
{
  "count": 3,
  "items": [
    {
      "id": 42,
      "type": "note_submitted",
      "text": "Staff A mengirim note di LHP Produksi",
      "url": "/input/produksi?date_from=2026-06-27&date_to=2026-06-27",
      "created_at": "27 Jun 09:15",
      "read_at": null
    }
  ]
}
```

**URL destination:**
- `note_submitted` / `note_resolved` → `/input/{produksi|qc|dandori}?date_from={tgl}&date_to={tgl}`
- Tanggal dari `data->tanggal` yang disimpan saat insert

### Insertion Points (6 titik)

| Controller | Method | Event | Call |
|-----------|--------|-------|------|
| ProduksiController | storeNote | note ada / diupdate | `insertNoteSubmitted` |
| ProduksiController | resolveNote | admin resolve | `insertNoteResolved` |
| QcController | storeNote | note ada / diupdate | `insertNoteSubmitted` |
| QcController | resolveNote | admin resolve | `insertNoteResolved` |
| DandoriController | storeNote | note ada / diupdate | `insertNoteSubmitted` |
| DandoriController | resolveNote | admin resolve | `insertNoteResolved` |

Catatan: `storeNote` hanya insert notif jika `staff_note` tidak kosong (bukan hapus note).

### Routes

```php
Route::middleware('auth')->group(function () {
    Route::get('/notifications/poll', [NotificationController::class, 'poll']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});
```

## Frontend

### Alpine Component `notifBell()`

Ditambahkan di `resources/views/layouts/app.blade.php`, dipasang di topbar.

```js
function notifBell() {
    return {
        count: 0,
        items: [],
        open: false,
        init() { this.poll(); setInterval(() => this.poll(), 30000); },
        async poll() {
            const r = await fetch('/notifications/poll');
            const d = await r.json();
            this.count = d.count;
            this.items = d.items;
        },
        async clickItem(id, url) {
            await fetch(`/notifications/${id}/read`, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            window.location.href = url;
        },
        async readAll() {
            await fetch('/notifications/read-all', { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } });
            this.count = 0;
            this.items = this.items.map(i => ({...i, read_at: 'now'}));
        },
    };
}
```

### Bell Icon HTML (topbar)

```html
<div x-data="notifBell()" class="position-relative me-3">
    <button @click="open = !open" class="notif-bell-btn">
        <i class="bi bi-bell-fill"></i>
        <span x-show="count > 0" x-text="count" class="notif-badge"></span>
    </button>
    <div x-show="open" x-cloak @click.outside="open = false" class="notif-dropdown">
        <div class="notif-dropdown-header">
            Notifikasi
            <button x-show="count > 0" @click="readAll()" class="notif-read-all">Tandai Semua Dibaca</button>
        </div>
        <div class="notif-dropdown-body">
            <template x-if="items.length === 0">
                <div class="notif-empty">Tidak ada notifikasi.</div>
            </template>
            <template x-for="item in items" :key="item.id">
                <div class="notif-item" :class="{'notif-unread': !item.read_at}" @click="clickItem(item.id, item.url)">
                    <div class="notif-item-icon">
                        <i :class="item.type === 'note_submitted' ? 'bi bi-chat-left-text-fill text-warning' : 'bi bi-check-circle-fill text-success'"></i>
                    </div>
                    <div class="notif-item-body">
                        <div class="notif-item-text" x-text="item.text"></div>
                        <div class="notif-item-time" x-text="item.created_at"></div>
                    </div>
                </div>
            </template>
        </div>
    </div>
</div>
```

### CSS

```css
.notif-bell-btn    { background: none; border: none; color: #d1d5db; font-size: 1.1rem; position: relative; cursor: pointer; padding: 4px 6px; }
.notif-bell-btn:hover { color: #fff; }
.notif-badge       { position: absolute; top: 0; right: 0; background: #ef4444; color: #fff; font-size: 0.65rem; font-weight: 700; border-radius: 999px; padding: 1px 5px; min-width: 16px; text-align: center; }
.notif-dropdown    { position: absolute; right: 0; top: calc(100% + 8px); width: 320px; background: #fff; border: 1px solid var(--border); border-radius: 4px; box-shadow: 0 4px 16px rgba(0,0,0,.12); z-index: 1060; }
.notif-dropdown-header { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 0.85rem; color: var(--text); }
.notif-read-all    { font-size: 0.78rem; color: #3b82f6; background: none; border: none; cursor: pointer; }
.notif-dropdown-body { max-height: 380px; overflow-y: auto; }
.notif-item        { display: flex; gap: 10px; padding: 10px 14px; cursor: pointer; border-bottom: 1px solid var(--border); }
.notif-item:hover  { background: #f3f4f6; }
.notif-unread      { background: #fefce8; }
.notif-item-icon   { font-size: 1rem; flex-shrink: 0; margin-top: 2px; }
.notif-item-text   { font-size: 0.83rem; color: var(--text); line-height: 1.4; }
.notif-item-time   { font-size: 0.75rem; color: var(--text-2); margin-top: 2px; }
.notif-empty       { padding: 20px 14px; text-align: center; color: var(--text-2); font-size: 0.85rem; }
```

## Deployment

1. Jalankan SQL `CREATE TABLE app_notifications ...` di phpMyAdmin hosting
2. Upload file:
   - `app/Models/AppNotification.php` (baru)
   - `app/Services/NotificationService.php` (baru)
   - `app/Http/Controllers/NotificationController.php` (baru)
   - `app/Http/Controllers/Input/ProduksiController.php` (modifikasi)
   - `app/Http/Controllers/Input/QcController.php` (modifikasi)
   - `app/Http/Controllers/Input/DandoriController.php` (modifikasi)
   - `routes/web.php` (tambah 3 route)
   - `resources/views/layouts/app.blade.php` (bell icon + CSS + JS)
3. Hapus `storage/framework/views/*.php` di hosting

## Out of Scope

- Real-time (websockets/pusher)
- Notif untuk role manager
- Halaman dedicated "semua notifikasi"
- Email / push notification
- Notif untuk tipe event selain note_submitted / note_resolved
