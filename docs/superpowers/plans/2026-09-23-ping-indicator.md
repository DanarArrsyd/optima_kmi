# Ping/Latency Indicator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah widget ping/latency (dot warna + ms) di topbar, poll `GET /ping` tiap 15 detik.

**Architecture:** Endpoint thin `GET /ping` (nol query DB, 204 No Content) diukur round-trip-nya dari widget Alpine baru (`pingIndicator()`) yang dipromosikan langsung jadi shared component di layout (bukan bespoke — cuma satu halaman yang butuh, yaitu layout itu sendiri, jadi nol siklus promote terpisah).

**Tech Stack:** Laravel 13 (PHP 8.4 CI/prod), Alpine.js 3.14.1, PHPUnit (SQLite in-memory).

## Global Constraints

- CI & prod jalan PHP 8.4 — jangan pakai sintaks yang cuma ada di PHP 8.5.
- Pint scope cuma `app/` + `tests/` — jalankan `vendor/bin/pint app tests` sebelum commit.
- **Tidak ada migrasi baru.**
- `php artisan test` jalan di SQLite in-memory.
- Semua route di app ini default-deny — route baru WAJIB di dalam group `Route::middleware('auth')->group(...)`.
- Komponen Alpine baru yang didefinisikan di `layouts/app.blade.php` WAJIB didaftarkan ke `tests/Feature/Layout/KomponenBersamaTest.php` const `KOMPONEN`.
- Threshold warna: 🟢 <150ms, 🟡 150–400ms, 🔴 >400ms. Fetch gagal/timeout → "Offline" (merah), state terpisah dari "lambat".
- Interval polling: 15000ms.

---

### Task 1: Endpoint `GET /ping`

**Files:**
- Create: `app/Http/Controllers/PingController.php`
- Modify: `routes/web.php:310` (sisip route baru dekat `/notifications/poll`)
- Test: Create `tests/Feature/PingTest.php`

**Interfaces:**
- Produces: route bernama `ping` (`GET /ping`) yang return HTTP 204 No Content untuk user login, redirect ke login untuk tamu belum login — dikonsumsi Task 2 (JS `fetch('/ping')`).

- [ ] **Step 1: Write the failing tests**

Buat `tests/Feature/PingTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint /ping — thin, nol query DB, dipakai widget latency topbar buat
 * ukur round-trip. Default-deny sama kayak seluruh route app ini.
 */
class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_tanpa_login_dialihkan_ke_login(): void
    {
        $this->get(route('ping'))->assertRedirect(route('login'));
    }

    public function test_ping_dengan_login_mengembalikan_204(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('ping'))->assertNoContent();
    }

    public function test_ping_bisa_diakses_role_guest(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->get(route('ping'))->assertNoContent();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PingTest`
Expected: FAIL — semua 3 test gagal dengan `RouteNotFoundException: Route [ping] not defined.` (route dan controller belum ada).

- [ ] **Step 3: Implement — controller & route**

Buat `app/Http/Controllers/PingController.php`:

```php
<?php

namespace App\Http\Controllers;

class PingController extends Controller
{
    public function ping()
    {
        return response()->noContent();
    }
}
```

Di `routes/web.php`, cari baris ini (baris 310 saat ini):

```php
    Route::get('/notifications/poll', [NotificationController::class, 'poll']);
```

Tambahkan baris baru persis setelahnya, di dalam group `Route::middleware('auth')->group(...)` yang sama:

```php
    Route::get('/notifications/poll', [NotificationController::class, 'poll']);
    Route::get('/ping', [PingController::class, 'ping'])->name('ping');
```

Tambahkan juga import di bagian atas `routes/web.php` (sejajar import controller lain, urut alfabetis):

```php
use App\Http\Controllers\PingController;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PingTest`
Expected: PASS (3/3).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app/Http/Controllers/PingController.php tests/Feature/PingTest.php
git add app/Http/Controllers/PingController.php routes/web.php tests/Feature/PingTest.php
git commit -m "feat(ping): endpoint GET /ping thin buat widget latency

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Widget topbar `pingIndicator()`

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (CSS di blok `<style>`, markup di `.topbar-right`, JS di `@push('scripts')`)
- Modify: `tests/Feature/Layout/KomponenBersamaTest.php:21-35` (tambah entri)
- Modify: `tests/Feature/PingTest.php` (tambah 1 test method)

**Interfaces:**
- Consumes: route `ping` (`GET /ping`) dari Task 1.
- Produces: Alpine factory `pingIndicator()` di `layouts/app.blade.php` — nol dikonsumsi task lain di plan ini (dipakai langsung dari markup-nya sendiri), tapi WAJIB unik/satu definisi sesuai `KomponenBersamaTest`.

- [ ] **Step 1: Write the failing test**

Tambahkan di `tests/Feature/PingTest.php`, sebelum `}` penutup class:

```php
    public function test_widget_ping_muncul_di_layout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString('x-data="pingIndicator()"', $html);
        $this->assertStringContainsString('pingOnce()', $html);
    }
```

Juga tambahkan di `tests/Feature/Layout/KomponenBersamaTest.php`, baris 27-28 (setelah `'function saringOrangNonaktif(',`, sebelum `'function mesinFilterComp(',`):

```php
        'function saringOrangNonaktif(',
        'function pingIndicator(',
        'function mesinFilterComp(',
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PingTest,KomponenBersamaTest`
Expected: `test_widget_ping_muncul_di_layout` FAIL (`pingIndicator()` belum ada di HTML). `KomponenBersamaTest`'s data set `'function pingIndicator('` FAIL (belum ada di `layouts/app.blade.php`).

- [ ] **Step 3: Implement — CSS, markup, JS di layout**

**3a. CSS** — di `resources/views/layouts/app.blade.php`, cari blok `<style>` yang berisi `.topbar-item { ... }` (sekitar baris 218). Sisipkan aturan baru persis setelah blok `.topbar-item i { ... }` (baris ~228):

```css
        .topbar-item i { font-size: 15px; color: #9ca3af; }
        .tb-ping { gap: 6px; }
        .ping-dot {
            width: 8px; height: 8px; border-radius: 50%;
            background: #6b7280;
            flex-shrink: 0;
        }
        .ping-dot.is-good { background: #22c55e; }
        .ping-dot.is-fair { background: #eab308; }
        .ping-dot.is-poor,
        .ping-dot.is-offline { background: #ef4444; }
```

**3b. Markup** — cari blok ini di `.topbar-right` (baris ~1335):

```blade
        <div class="topbar-item tb-tanggal">
            <i class="bi bi-calendar3"></i>
            <span>{{ now()->format('d M Y') }}</span>
        </div>
```

Tambahkan widget baru PERSIS SEBELUM blok itu:

```blade
        <div class="topbar-item tb-ping" x-data="pingIndicator()">
            <span class="ping-dot" :class="dotClass"></span>
            <span x-text="label"></span>
        </div>

        <div class="topbar-item tb-tanggal">
            <i class="bi bi-calendar3"></i>
            <span>{{ now()->format('d M Y') }}</span>
        </div>
```

**3c. JS** — di `@push('scripts')`, cari definisi `function notifBell() {` (baris ~1428). Sisipkan fungsi baru PERSIS SEBELUM baris itu:

```js
function pingIndicator() {
    return {
        ms: null,
        offline: false,
        get label() {
            if (this.offline) return 'Offline';
            if (this.ms === null) return '—';
            return this.ms + ' ms';
        },
        get dotClass() {
            if (this.offline) return 'is-offline';
            if (this.ms === null) return '';
            if (this.ms < 150) return 'is-good';
            if (this.ms < 400) return 'is-fair';
            return 'is-poor';
        },
        async pingOnce() {
            const start = performance.now();
            try {
                const res = await fetch('/ping', { cache: 'no-store' });
                if (!res.ok) throw new Error('bad status');
                this.ms = Math.round(performance.now() - start);
                this.offline = false;
            } catch (e) {
                this.offline = true;
            }
        },
        init() {
            this.pingOnce();
            setInterval(() => this.pingOnce(), 15000);
        },
    };
}

function notifBell() {
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PingTest,KomponenBersamaTest`
Expected: PASS — `PingTest` 4/4, `KomponenBersamaTest` semua data set termasuk `'function pingIndicator('`.

Run full regression: `php artisan test`
Expected: PASS (seluruh suite — layout disentuh semua halaman, cek nol halaman lain kena dampak tak terduga).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/PingTest.php tests/Feature/Layout/KomponenBersamaTest.php
git add resources/views/layouts/app.blade.php tests/Feature/PingTest.php tests/Feature/Layout/KomponenBersamaTest.php
git commit -m "feat(ping): widget pingIndicator() di topbar, poll tiap 15 detik

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Verifikasi Manual (Browser)

Setelah 2 task selesai, sebelum PR:

1. Login, lihat topbar — dot abu-abu awal ("—"), dalam <1 detik berubah warna + angka ms muncul (biasanya hijau di localhost).
2. Buka DevTools → Network, filter `ping` — pastikan request `/ping` muncul tiap ~15 detik, response 204.
3. Matikan koneksi (DevTools → Network → Offline), tunggu satu siklus poll — dot jadi merah, label "Offline".
4. Nyalakan lagi koneksi, tunggu satu siklus — dot balik warna sesuai ms, label balik jadi angka.
