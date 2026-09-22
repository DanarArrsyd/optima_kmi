# Ping/Latency Indicator

**Tanggal:** 2026-09-23

## Latar Belakang

User mau indikator kecil di topbar yang nunjukin latency (ms) koneksi user ke
server — mirip indikator koneksi yang lazim di aplikasi enterprise/ERP
(Slack, Zoom, dsb): dot warna + angka ms, polling berkala, ganti warna kalau
lambat, tampilin "Offline" kalau putus. Bagian pertama dari 3 fitur
performa/UX yang diminta (ping indicator → loading state → audit performa
umum) — dikerjakan satu-satu, spec/plan/implementasi terpisah per fitur.

**Tidak ada migrasi** — fitur ini murni endpoint tipis + widget frontend,
nol tabel baru.

## Scope

- Endpoint baru `GET /ping` — thin, nol query DB, return 204 No Content.
- Widget Alpine baru di topbar (`layouts/app.blade.php`), sebelah item
  tanggal (`tb-tanggal`): dot warna + angka ms.
- Polling tiap 15 detik, ukur round-trip pakai `performance.now()`.
- Threshold warna: 🟢 <150ms, 🟡 150–400ms, 🔴 >400ms.
- Fetch gagal/timeout → tampilin "Offline" (merah), state beda dari "lambat
  tapi nyambung".
- Berlaku semua role yang login (termasuk `guest`) — murni indikator jaringan,
  nol berhubungan sama akses fitur.

## Perubahan Backend

### `routes/web.php`

Route baru di dalam group `Route::middleware('auth')->group(...)` (baris
46-348 saat ini), diletakkan berdekatan dengan `/notifications/poll` (baris
310) karena sama-sama endpoint polling ringan tanpa role restriction:

```php
Route::get('/ping', [PingController::class, 'ping'])->name('ping');
```

### `app/Http/Controllers/PingController.php` (baru)

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

Satu method, nol dependency, nol query — waktu respons endpoint ini murni
overhead framework (auth middleware + routing), itu justru yang mau diukur
(bukan waktu query DB yang nggak relevan buat "ping").

## Perubahan Frontend

### `resources/views/layouts/app.blade.php`

Widget baru di `.topbar-right`, ditaruh sebelum `.tb-tanggal` (baris ~1335
saat ini):

```blade
<div class="topbar-item tb-ping" x-data="pingIndicator()">
    <span class="ping-dot" :class="dotClass"></span>
    <span x-text="label"></span>
</div>
```

CSS baru (di blok `<style>` layout, dekat definisi `.topbar-item` lain):

```css
.tb-ping { gap: 6px; }
.ping-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #6b7280; /* default abu-abu sebelum ping pertama */
    flex-shrink: 0;
}
.ping-dot.is-good { background: #22c55e; }
.ping-dot.is-fair { background: #eab308; }
.ping-dot.is-poor,
.ping-dot.is-offline { background: #ef4444; }
```

JS component baru di `@push('scripts')` (dekat `notifBell()`), didaftar ke
`KomponenBersamaTest` sesuai konvensi komponen shared:

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
```

`init()` Alpine dipanggil otomatis begitu komponen di-mount (`x-data` dengan
method `init` adalah hook bawaan Alpine) — nol perlu wiring tambahan di
markup.

## Testing

`tests/Feature/PingTest.php` (baru):

- Request ke `/ping` tanpa login → redirect ke halaman login (konsisten
  default-deny seluruh app).
- Request ke `/ping` dengan login (role apa aja, termasuk `guest`) →
  `assertNoContent()` (HTTP 204), body kosong.

`tests/Feature/Layout/KomponenBersamaTest.php` — tambah entri
`'function pingIndicator('` ke const `KOMPONEN`.

Tambahkan satu test method baru ke `tests/Feature/PingTest.php` yang sama
(bukan file terpisah) — pakai halaman `vcb.mesin` sebagai anchor (sudah
dites luas di suite lain, `extends('layouts.app')`, admin selalu bisa akses):

```php
public function test_widget_ping_muncul_di_layout(): void
{
    $admin = User::factory()->create(['role' => 'admin']);
    $html = $this->actingAs($admin)->get(route('vcb.mesin'))->assertOk()->getContent();

    $this->assertStringContainsString('x-data="pingIndicator()"', $html);
}
```
