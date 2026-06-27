# In-App Notification System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah bell icon di topbar dengan polling 30s — admin dapat notif saat staff kirim note, staff dapat notif saat admin resolve note (3 modul: Produksi, QC, Dandori).

**Architecture:** Custom `app_notifications` table + `AppNotification` model. `NotificationService` di-inject ke 3 controller input yang sudah punya `storeNote`/`resolveNote`. `NotificationController` melayani polling JSON + mark-read. Alpine component di topbar poll tiap 30s.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Bootstrap 5.3.3, Alpine.js 3.14.1, SQLite (unit tests only).

## Global Constraints

- SoftDeletes: tidak dipakai di `app_notifications` (notif cukup di-mark read, tidak dihapus)
- Deployment ke hosting via FTP + phpMyAdmin (tidak ada artisan migrate di hosting)
- Setiap migration file JUGA disertai SQL CREATE TABLE manual untuk phpMyAdmin
- No websockets / queue — polling only
- Role yang terima notif: `admin` (note_submitted), user yg tulis note (note_resolved)
- Manager, staff_maintenance: tidak terima notif (out of scope)

---

## File Map

| File | Status | Responsibility |
|------|--------|----------------|
| `database/migrations/2026_06_27_000000_create_app_notifications_table.php` | CREATE | Migration untuk local/test |
| `app/Models/AppNotification.php` | CREATE | Eloquent model, scopes |
| `app/Services/NotificationService.php` | CREATE | insertNoteSubmitted, insertNoteResolved |
| `tests/Unit/NotificationServiceTest.php` | CREATE | Unit tests via SQLite in-memory |
| `app/Http/Controllers/NotificationController.php` | CREATE | poll(), markRead(), markAllRead() |
| `routes/web.php` | MODIFY | +3 notification routes |
| `app/Http/Controllers/Input/ProduksiController.php` | MODIFY | Wire notifSvc di storeNote + resolveNote |
| `app/Http/Controllers/Input/QcController.php` | MODIFY | Wire notifSvc di storeNote + resolveNote |
| `app/Http/Controllers/Input/DandoriController.php` | MODIFY | Wire notifSvc di storeNote + resolveNote |
| `resources/views/layouts/app.blade.php` | MODIFY | Bell icon HTML + CSS + Alpine JS |

---

## Task 1: DB Migration + AppNotification Model

**Files:**
- Create: `database/migrations/2026_06_27_000000_create_app_notifications_table.php`
- Create: `app/Models/AppNotification.php`

**Interfaces:**
- Produces: `AppNotification::create([...])`, `AppNotification::scopeUnread()`, `AppNotification::scopeForUser(int $id)`

- [ ] **Step 1: Buat migration file**

```php
<?php
// database/migrations/2026_06_27_000000_create_app_notifications_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('actor_id');
            $table->string('notifiable_type', 20);
            $table->unsignedBigInteger('notifiable_id');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'read_at']);
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('actor_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
```

- [ ] **Step 2: Jalankan migration di local**

```bash
php artisan migrate
```

Expected: `Migrating: 2026_06_27_000000_create_app_notifications_table` → `Migrated`

- [ ] **Step 3: Buat AppNotification model**

```php
<?php
// app/Models/AppNotification.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppNotification extends Model
{
    public $timestamps = false;

    protected $table = 'app_notifications';

    protected $fillable = [
        'type', 'user_id', 'actor_id', 'notifiable_type',
        'notifiable_id', 'data', 'read_at', 'created_at',
    ];

    protected $casts = [
        'data'       => 'array',
        'read_at'    => 'datetime',
        'created_at' => 'datetime',
    ];

    public function scopeUnread($query)
    {
        return $query->whereNull('read_at');
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

- [ ] **Step 4: Verifikasi via tinker**

```bash
php artisan tinker
>>> \App\Models\AppNotification::count()
```

Expected: `= 0` (tabel ada, kosong)

- [ ] **Step 5: Simpan SQL untuk hosting (phpMyAdmin)**

SQL ini dijalankan di phpMyAdmin hosting SETELAH upload semua file:

```sql
CREATE TABLE app_notifications (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type             VARCHAR(50)  NOT NULL,
    user_id          BIGINT UNSIGNED NOT NULL,
    actor_id         BIGINT UNSIGNED NOT NULL,
    notifiable_type  VARCHAR(20)  NOT NULL,
    notifiable_id    BIGINT UNSIGNED NOT NULL,
    data             JSON         NOT NULL,
    read_at          TIMESTAMP    NULL DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_read (user_id, read_at),
    CONSTRAINT fk_appnotif_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_appnotif_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_27_000000_create_app_notifications_table.php app/Models/AppNotification.php
git commit -m "feat(notif): app_notifications table migration + AppNotification model"
```

---

## Task 2: NotificationService + Unit Tests

**Files:**
- Create: `app/Services/NotificationService.php`
- Create: `tests/Unit/NotificationServiceTest.php`

**Interfaces:**
- Consumes: `AppNotification::create(array)`, `User::where('role','admin')->pluck('id')`
- Produces:
  - `NotificationService::insertNoteSubmitted(Model $record, string $notifiableType, string $tanggal): void`
  - `NotificationService::insertNoteResolved(Model $record, string $notifiableType, string $tanggal): void`

- [ ] **Step 1: Tulis failing tests**

```php
<?php
// tests/Unit/NotificationServiceTest.php
namespace Tests\Unit;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $name = 'Test User'): User
    {
        return User::create([
            'name'     => $name,
            'username' => 'u_' . uniqid(),
            'email'    => uniqid() . '@test.com',
            'password' => bcrypt('password'),
            'role'     => $role,
        ]);
    }

    private function fakeRecord(int $id, string $note, int $noteBy): object
    {
        return (object)[
            'id'            => $id,
            'staff_note'    => $note,
            'staff_note_by' => $noteBy,
        ];
    }

    public function test_insert_note_submitted_creates_notif_for_each_admin(): void
    {
        $staff = $this->makeUser('staff_produksi', 'Budi');
        $admin1 = $this->makeUser('admin', 'Admin Satu');
        $admin2 = $this->makeUser('admin', 'Admin Dua');
        $this->makeUser('manager'); // tidak dapat notif

        Auth::login($staff);

        $record = $this->fakeRecord(99, 'ada masalah', $staff->id);
        (new NotificationService())->insertNoteSubmitted($record, 'produksi', '2026-06-27');

        $this->assertSame(2, AppNotification::count());
        $this->assertTrue(AppNotification::where('user_id', $admin1->id)->exists());
        $this->assertTrue(AppNotification::where('user_id', $admin2->id)->exists());

        $notif = AppNotification::first();
        $this->assertSame('note_submitted', $notif->type);
        $this->assertSame('produksi', $notif->notifiable_type);
        $this->assertSame(99, $notif->notifiable_id);
        $this->assertSame($staff->id, $notif->actor_id);
        $this->assertSame('2026-06-27', $notif->data['tanggal']);
        $this->assertSame('Budi', $notif->data['nama_actor']);
        $this->assertSame('ada masalah', $notif->data['preview_note']);
        $this->assertNull($notif->read_at);
    }

    public function test_insert_note_submitted_skipped_when_note_empty(): void
    {
        $staff = $this->makeUser('staff_produksi');
        $this->makeUser('admin');
        Auth::login($staff);

        $record = $this->fakeRecord(1, '', $staff->id);
        (new NotificationService())->insertNoteSubmitted($record, 'produksi', '2026-06-27');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_insert_note_resolved_creates_notif_for_note_author(): void
    {
        $staff = $this->makeUser('staff_produksi', 'Siti');
        $admin = $this->makeUser('admin', 'Admin');
        Auth::login($admin);

        $record = $this->fakeRecord(55, 'masalah', $staff->id);
        (new NotificationService())->insertNoteResolved($record, 'qc', '2026-06-26');

        $this->assertSame(1, AppNotification::count());
        $notif = AppNotification::first();
        $this->assertSame('note_resolved', $notif->type);
        $this->assertSame('qc', $notif->notifiable_type);
        $this->assertSame(55, $notif->notifiable_id);
        $this->assertSame($staff->id, $notif->user_id);
        $this->assertSame($admin->id, $notif->actor_id);
        $this->assertSame('2026-06-26', $notif->data['tanggal']);
        $this->assertSame('Admin', $notif->data['nama_actor']);
    }
}
```

- [ ] **Step 2: Jalankan test — pastikan FAIL**

```bash
php artisan test tests/Unit/NotificationServiceTest.php
```

Expected: FAIL — `App\Services\NotificationService not found`

- [ ] **Step 3: Buat NotificationService**

```php
<?php
// app/Services/NotificationService.php
namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    public function insertNoteSubmitted(object $record, string $notifiableType, string $tanggal): void
    {
        if (empty($record->staff_note)) {
            return;
        }

        $actor  = Auth::user();
        $admins = User::where('role', 'admin')->pluck('id');
        $data   = [
            'tanggal'      => $tanggal,
            'nama_actor'   => $actor->name,
            'preview_note' => mb_substr($record->staff_note, 0, 80),
        ];

        foreach ($admins as $adminId) {
            AppNotification::create([
                'type'            => 'note_submitted',
                'user_id'         => $adminId,
                'actor_id'        => $actor->id,
                'notifiable_type' => $notifiableType,
                'notifiable_id'   => $record->id,
                'data'            => $data,
                'created_at'      => now(),
            ]);
        }
    }

    public function insertNoteResolved(object $record, string $notifiableType, string $tanggal): void
    {
        if (!$record->staff_note_by) {
            return;
        }

        $actor = Auth::user();

        AppNotification::create([
            'type'            => 'note_resolved',
            'user_id'         => $record->staff_note_by,
            'actor_id'        => $actor->id,
            'notifiable_type' => $notifiableType,
            'notifiable_id'   => $record->id,
            'data'            => [
                'tanggal'    => $tanggal,
                'nama_actor' => $actor->name,
            ],
            'created_at'      => now(),
        ]);
    }
}
```

- [ ] **Step 4: Jalankan test — pastikan PASS**

```bash
php artisan test tests/Unit/NotificationServiceTest.php
```

Expected: 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/NotificationService.php tests/Unit/NotificationServiceTest.php
git commit -m "feat(notif): NotificationService with unit tests"
```

---

## Task 3: NotificationController + Routes

**Files:**
- Create: `app/Http/Controllers/NotificationController.php`
- Modify: `routes/web.php`

**Interfaces:**
- Consumes: `AppNotification::scopeUnread()`, `AppNotification::scopeForUser(int $id)`
- Produces:
  - `GET /notifications/poll` → JSON `{count: int, items: array}`
  - `POST /notifications/{id}/read` → JSON `{ok: true}`
  - `POST /notifications/read-all` → JSON `{ok: true}`

- [ ] **Step 1: Buat NotificationController**

```php
<?php
// app/Http/Controllers/NotificationController.php
namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function poll(): JsonResponse
    {
        $userId = auth()->id();

        $items = AppNotification::forUser($userId)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn($n) => [
                'id'         => $n->id,
                'type'       => $n->type,
                'text'       => $this->buildText($n),
                'url'        => $this->buildUrl($n),
                'created_at' => $n->created_at->format('d M H:i'),
                'read_at'    => $n->read_at,
            ]);

        $count = AppNotification::forUser($userId)->unread()->count();

        return response()->json(['count' => $count, 'items' => $items]);
    }

    public function markRead(int $id): JsonResponse
    {
        AppNotification::where('id', $id)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        AppNotification::forUser(auth()->id())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function buildText(AppNotification $n): string
    {
        $map   = ['produksi' => 'LHP Produksi', 'qc' => 'LHP Quality', 'dandori' => 'Dandori'];
        $label = $map[$n->notifiable_type] ?? ucfirst($n->notifiable_type);

        return match ($n->type) {
            'note_submitted' => ($n->data['nama_actor'] ?? 'Staff') . ' mengirim note di ' . $label,
            'note_resolved'  => 'Note kamu di ' . $label . ' sudah di-feedback admin',
            default          => 'Notifikasi baru',
        };
    }

    private function buildUrl(AppNotification $n): string
    {
        $paths = [
            'produksi' => '/input/produksi',
            'qc'       => '/input/qc',
            'dandori'  => '/input/dandori',
        ];
        $path = $paths[$n->notifiable_type] ?? '/';
        $tgl  = $n->data['tanggal'] ?? null;

        return $tgl ? $path . '?date_from=' . $tgl . '&date_to=' . $tgl : $path;
    }
}
```

- [ ] **Step 2: Tambah routes ke `routes/web.php`**

Cari blok `Route::middleware('auth')->group(...)` yang sudah ada. Tambahkan 3 baris ini di dalam group:

```php
// Notifications
Route::get('/notifications/poll', [\App\Http\Controllers\NotificationController::class, 'poll']);
Route::post('/notifications/read-all', [\App\Http\Controllers\NotificationController::class, 'markAllRead']);
Route::post('/notifications/{id}/read', [\App\Http\Controllers\NotificationController::class, 'markRead']);
```

Catatan: `read-all` HARUS di atas `{id}/read` agar Laravel tidak menganggap `read-all` sebagai `{id}`.

- [ ] **Step 3: Verifikasi routes terdaftar**

```bash
php artisan route:list --path=notifications
```

Expected output (3 baris):
```
GET    notifications/poll
POST   notifications/read-all
POST   notifications/{id}/read
```

- [ ] **Step 4: Test manual via browser/curl**

Login sebagai admin di browser, buka `/notifications/poll` — expected JSON `{"count":0,"items":[]}`.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/NotificationController.php routes/web.php
git commit -m "feat(notif): NotificationController + poll/read routes"
```

---

## Task 4: Wire Insertion Points (3 Controllers)

**Files:**
- Modify: `app/Http/Controllers/Input/ProduksiController.php`
- Modify: `app/Http/Controllers/Input/QcController.php`
- Modify: `app/Http/Controllers/Input/DandoriController.php`

**Interfaces:**
- Consumes: `NotificationService::insertNoteSubmitted(object, string, string)`, `NotificationService::insertNoteResolved(object, string, string)`

Tanggal field per model:
- Produksi: `$produksi->tanggal_produksi`
- QC: `$qc->tgl_pengecekan`
- Dandori: `$dandori->tanggal`

- [ ] **Step 1: Modifikasi ProduksiController**

Tambah `use` + inject `NotificationService` di constructor, lalu wire storeNote + resolveNote.

Di bagian `use` (atas file), tambah:
```php
use App\Services\NotificationService;
```

Di class, tambah constructor (jika belum ada) atau tambah property:
```php
public function __construct(private NotificationService $notifSvc) {}
```

Cari method `storeNote`. Setelah baris `$produksi->update([...])` (yang set staff_note), tambah:
```php
if ($request->input('staff_note')) {
    $produksi->refresh();
    $this->notifSvc->insertNoteSubmitted(
        $produksi, 'produksi',
        $produksi->tanggal_produksi->format('Y-m-d')
    );
}
```

Cari method `resolveNote`. Setelah baris `$produksi->update([...])` (yang set resolved_at), tambah:
```php
$produksi->refresh();
$this->notifSvc->insertNoteResolved(
    $produksi, 'produksi',
    $produksi->tanggal_produksi->format('Y-m-d')
);
```

- [ ] **Step 2: Modifikasi QcController**

Sama persis dengan ProduksiController, tapi:
- Variable: `$qc` (bukan `$produksi`)
- Notifiable type: `'qc'`
- Tanggal field: `$qc->tgl_pengecekan->format('Y-m-d')`

```php
use App\Services\NotificationService;
// ...
public function __construct(private NotificationService $notifSvc) {}
```

Di `storeNote` setelah update:
```php
if ($request->input('staff_note')) {
    $qc->refresh();
    $this->notifSvc->insertNoteSubmitted(
        $qc, 'qc',
        $qc->tgl_pengecekan->format('Y-m-d')
    );
}
```

Di `resolveNote` setelah update:
```php
$qc->refresh();
$this->notifSvc->insertNoteResolved(
    $qc, 'qc',
    $qc->tgl_pengecekan->format('Y-m-d')
);
```

- [ ] **Step 3: Modifikasi DandoriController**

Variable: `$dandori`, type: `'dandori'`, tanggal: `$dandori->tanggal->format('Y-m-d')`.

```php
use App\Services\NotificationService;
// ...
public function __construct(private NotificationService $notifSvc) {}
```

Di `storeNote` setelah update:
```php
if ($request->input('staff_note')) {
    $dandori->refresh();
    $this->notifSvc->insertNoteSubmitted(
        $dandori, 'dandori',
        $dandori->tanggal->format('Y-m-d')
    );
}
```

Di `resolveNote` setelah update:
```php
$dandori->refresh();
$this->notifSvc->insertNoteResolved(
    $dandori, 'dandori',
    $dandori->tanggal->format('Y-m-d')
);
```

- [ ] **Step 4: Test manual**

1. Login sebagai `staff_produksi`
2. Buka LHP Produksi, klik note pada record → isi note → kirim
3. Login sebagai `admin`, buka `/notifications/poll` → expected: `count: 1`, item type `note_submitted`
4. Sebagai admin, resolve note di halaman produksi
5. Login kembali sebagai staff, buka `/notifications/poll` → expected: `count: 1`, item type `note_resolved`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Input/ProduksiController.php \
        app/Http/Controllers/Input/QcController.php \
        app/Http/Controllers/Input/DandoriController.php
git commit -m "feat(notif): wire NotificationService into storeNote/resolveNote (3 controllers)"
```

---

## Task 5: Frontend — Bell Icon di Topbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:**
- Consumes: `GET /notifications/poll`, `POST /notifications/{id}/read`, `POST /notifications/read-all`

- [ ] **Step 1: Tambah CSS bell icon**

Di dalam blok `<style>` yang sudah ada di `layouts/app.blade.php` (sebelum `</style>`), tambah:

```css
/* ══ Notification Bell ══════════════════════════════════ */
.notif-btn { background: none; border: none; color: #9ca3af; font-size: 1.05rem; position: relative; cursor: pointer; padding: 5px 7px; border-radius: 4px; display:flex;align-items:center; }
.notif-btn:hover { color: #fff; background: rgba(255,255,255,.08); }
.notif-badge { position: absolute; top: 2px; right: 2px; background: #ef4444; color: #fff; font-size: 0.6rem; font-weight: 700; border-radius: 999px; padding: 1px 4px; min-width: 15px; text-align: center; line-height: 1.4; }
.notif-dropdown { position: absolute; right: 0; top: calc(100% + 6px); width: 310px; background: #fff; border: 1px solid var(--border); border-radius: 4px; box-shadow: 0 6px 20px rgba(0,0,0,.14); z-index: 1060; }
.notif-dd-header { display: flex; justify-content: space-between; align-items: center; padding: 9px 13px; border-bottom: 1px solid var(--border); font-size: 0.82rem; font-weight: 700; color: var(--text); }
.notif-read-all { font-size: 0.75rem; color: #3b82f6; background: none; border: none; cursor: pointer; padding: 0; }
.notif-read-all:hover { text-decoration: underline; }
.notif-dd-body { max-height: 360px; overflow-y: auto; }
.notif-item { display: flex; gap: 9px; padding: 9px 13px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background .1s; }
.notif-item:last-child { border-bottom: none; }
.notif-item:hover { background: #f9fafb; }
.notif-item.is-unread { background: #fefce8; }
.notif-item.is-unread:hover { background: #fef9c3; }
.notif-item-icon { font-size: 0.95rem; flex-shrink: 0; padding-top: 2px; }
.notif-item-text { font-size: 0.81rem; color: var(--text); line-height: 1.4; }
.notif-item-time { font-size: 0.73rem; color: var(--text-2); margin-top: 2px; }
.notif-empty { padding: 22px 13px; text-align: center; color: var(--text-2); font-size: 0.82rem; }
```

- [ ] **Step 2: Tambah Alpine component `notifBell()`**

Di dalam blok `<script>` yang sudah ada di layout (tempat `vcbGroups`, `taField`, dll), tambah fungsi ini:

```js
function notifBell() {
    return {
        count: 0,
        items: [],
        open: false,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        init() {
            this.poll();
            setInterval(() => this.poll(), 30000);
        },
        async poll() {
            try {
                const r = await fetch('/notifications/poll');
                const d = await r.json();
                this.count = d.count ?? 0;
                this.items = d.items ?? [];
            } catch (e) { /* silent — network hiccup */ }
        },
        async clickItem(id, url) {
            try {
                await fetch('/notifications/' + id + '/read', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
            } catch (e) { /* ignore */ }
            window.location.href = url;
        },
        async readAll() {
            try {
                await fetch('/notifications/read-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                this.count = 0;
                this.items = this.items.map(i => ({ ...i, read_at: 'now' }));
            } catch (e) { /* ignore */ }
        },
    };
}
```

- [ ] **Step 3: Tambah bell icon HTML di topbar**

Cari baris ini di topbar:
```html
    <div class="topbar-right">
```

Tepat setelah baris itu (sebelum blok `.topbar-item` pertama), sisipkan:

```html
        {{-- Notification Bell --}}
        <div x-data="notifBell()" class="position-relative" style="display:flex;align-items:center">
            <button class="notif-btn" @click="open = !open" title="Notifikasi">
                <i class="bi bi-bell-fill"></i>
                <span class="notif-badge" x-show="count > 0" x-text="count" x-cloak></span>
            </button>
            <div class="notif-dropdown" x-show="open" x-cloak @click.outside="open = false">
                <div class="notif-dd-header">
                    <span>Notifikasi</span>
                    <button class="notif-read-all" x-show="count > 0" @click.stop="readAll()">Tandai Semua Dibaca</button>
                </div>
                <div class="notif-dd-body">
                    <template x-if="items.length === 0">
                        <div class="notif-empty">Tidak ada notifikasi.</div>
                    </template>
                    <template x-for="item in items" :key="item.id">
                        <div class="notif-item" :class="{'is-unread': !item.read_at}" @click="clickItem(item.id, item.url)">
                            <div class="notif-item-icon">
                                <i :class="item.type === 'note_submitted' ? 'bi bi-chat-left-text-fill text-warning' : 'bi bi-check-circle-fill text-success'"></i>
                            </div>
                            <div>
                                <div class="notif-item-text" x-text="item.text"></div>
                                <div class="notif-item-time" x-text="item.created_at"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
```

- [ ] **Step 4: Test manual end-to-end**

1. Login sebagai `staff_produksi`, kirim note di LHP Produksi
2. Login sebagai `admin` — bell icon di topbar harus tampil badge merah angka `1`
3. Klik bell — dropdown terbuka, item `"[nama staff] mengirim note di LHP Produksi"`, bg kuning
4. Klik item → halaman `/input/produksi?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`, item jadi read (bg putih)
5. Admin resolve note → notif di-create ke staff
6. Login kembali sebagai staff → bell badge muncul
7. Klik "Tandai Semua Dibaca" → count jadi 0, badge hilang
8. Tunggu 30 detik tanpa interaksi → badge tidak muncul kembali (karena sudah read)

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(notif): bell icon di topbar dengan Alpine polling 30s"
```

---

## Deployment Checklist (Hosting)

Setelah semua task selesai dan tested:

1. **phpMyAdmin** — jalankan SQL CREATE TABLE dari Task 1 Step 5
2. **FTP upload:**
   - `app/Models/AppNotification.php`
   - `app/Services/NotificationService.php`
   - `app/Http/Controllers/NotificationController.php`
   - `app/Http/Controllers/Input/ProduksiController.php`
   - `app/Http/Controllers/Input/QcController.php`
   - `app/Http/Controllers/Input/DandoriController.php`
   - `routes/web.php`
   - `resources/views/layouts/app.blade.php`
3. **Hapus view cache** — hapus semua file di `storage/framework/views/*.php` di hosting
