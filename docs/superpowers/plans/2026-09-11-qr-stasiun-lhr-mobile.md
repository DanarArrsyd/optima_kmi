# QR Stasiun LHR Regular + Mobile Fase 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Staff scan QR fisik di stasiun repair → diarahkan langsung ke form input LHR Regular (login dulu kalau belum), dan admin punya menu untuk generate QR stasiun baru kapan pun tanpa kode baru.

**Architecture:** QR statis satu tujuan (`input.repair.create`), dirender SVG on-the-fly lewat `bacon/bacon-qr-code` (pola sama persis dengan `TwoFactorAuthService::qrCodeSvg()`, sudah live di prod). Nol tabel baru — daftar stasiun hidup di `config/qr_stasiun.php`. Redirect pakai mekanisme bawaan Laravel `redirect()->intended()` yang sudah ada di `AuthenticatedSessionController::store()`, nol perubahan di situ. Mobile-ready dibatasi CSS-only ke: login (sudah selesai sebelumnya, nol kerjaan), 403, shell topbar/sidebar, dan form `input/repair/create.blade.php`.

**Tech Stack:** Laravel 13 / PHP 8.4, Blade, `bacon/bacon-qr-code` (sudah di composer.json), Bootstrap 5.3.3, Alpine.js 3.14.1, PHPUnit (SQLite in-memory).

## Global Constraints

- PHP lokal 8.5.4 tapi CI & prod jalan 8.4 — jangan pakai sintaks yang cuma ada di 8.5.
- Setiap migrasi baru wajib didaftarkan `--path` di `.github/workflows/deploy.yml` pada PR yang sama. **Tidak ada migrasi di plan ini** — semua task di bawah nol migrasi baru.
- `vendor/bin/pint app tests` wajib bersih sebelum commit (scope Pint = `app/` + `tests/` saja, bukan `resources/views/`).
- `php artisan test` jalan di SQLite in-memory, tidak butuh MySQL lokal menyala.
- Nol perubahan ke halaman selain yang disebut eksplisit di plan ini (LHP Produksi/QC/Dandori/Breakdown, VCB, Master, Maintenance = Fase 2, di luar plan ini).

---

## Task 1: QrStasiunService (render SVG)

**Files:**
- Create: `app/Services/QrStasiunService.php`
- Test: `tests/Unit/QrStasiunServiceTest.php`

**Interfaces:**
- Produces: `QrStasiunService::svgFor(string $url): string` — dipakai Task 3 (controller).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Services\QrStasiunService;
use Tests\TestCase;

class QrStasiunServiceTest extends TestCase
{
    public function test_svg_for_menghasilkan_markup_svg_yang_memuat_url(): void
    {
        $svg = (new QrStasiunService)->svgFor('https://optima.kencomanufactur.co.id/input/repair/create');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_svg_for_url_berbeda_menghasilkan_svg_berbeda(): void
    {
        $qr = new QrStasiunService;

        $svgA = $qr->svgFor('https://optima.kencomanufactur.co.id/input/repair/create');
        $svgB = $qr->svgFor('https://optima.kencomanufactur.co.id/input/repair-produksi/create');

        $this->assertNotSame($svgA, $svgB);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QrStasiunServiceTest`
Expected: FAIL — `Class "App\Services\QrStasiunService" not found`

- [ ] **Step 3: Write minimal implementation**

```php
<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * QR dirender SVG LOKAL, pola sama persis dengan TwoFactorAuthService::qrCodeSvg() —
 * satu definisi cara render QR di seluruh aplikasi, cuma beda ukuran & isi konten.
 * Ukuran 240px (lebih besar dari QR 2FA yang 200px) karena QR stasiun dicetak dan
 * discan dari jarak lebih jauh, bukan dilihat dari layar dekat.
 */
class QrStasiunService
{
    public function svgFor(string $url): string
    {
        $renderer = new ImageRenderer(new RendererStyle(240), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=QrStasiunServiceTest`
Expected: PASS (2 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/QrStasiunService.php tests/Unit/QrStasiunServiceTest.php
git commit -m "feat(qr-stasiun): tambah QrStasiunService render SVG"
```

---

## Task 2: Config daftar stasiun

**Files:**
- Create: `config/qr_stasiun.php`

**Interfaces:**
- Produces: `config('qr_stasiun')` — array list `['label' => string, 'route' => string]`, dikonsumsi Task 3.

- [ ] **Step 1: Buat file config**

```php
<?php

// Daftar stasiun yang QR-nya bisa digenerate dari menu Sistem > Generate QR Stasiun.
// Nambah stasiun baru = nambah satu baris di sini, nol kode baru.
return [
    ['label' => 'LHR Regular', 'route' => 'input.repair.create'],
];
```

- [ ] **Step 2: Verifikasi route name valid**

Run: `php artisan route:list --name=input.repair.create`
Expected: baris `GET|HEAD input/repair/create ... input.repair.create`

- [ ] **Step 3: Commit**

```bash
git add config/qr_stasiun.php
git commit -m "feat(qr-stasiun): tambah config daftar stasiun"
```

---

## Task 3: Route + QrStasiunController

**Files:**
- Create: `app/Http/Controllers/Sistem/QrStasiunController.php`
- Modify: `routes/web.php` — tambah `use` statement dan satu route di grup `role:admin` prefix `sistem`
- Test: `tests/Feature/Sistem/QrStasiunHalamanTest.php`

**Interfaces:**
- Consumes: `QrStasiunService::svgFor(string $url): string` (Task 1), `config('qr_stasiun')` (Task 2)
- Produces: route `sistem.qr-stasiun.index` (GET, admin-only), view data `$stasiun` (Collection of `['label' => string, 'url' => string, 'svg' => string]`) — dikonsumsi Task 4.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Sistem;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrStasiunHalamanTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_buka_halaman_generate_qr_stasiun(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('sistem.qr-stasiun.index'));

        $response->assertOk();
        $response->assertSee('LHR Regular');
        $response->assertSee('<svg', false);
    }

    public static function peranBukanAdmin(): array
    {
        return [
            'manager' => ['manager'],
            'staff_produksi' => ['staff_produksi'],
            'staff_repair' => ['staff_repair'],
            'guest' => ['guest'],
        ];
    }

    #[DataProvider('peranBukanAdmin')]
    public function test_non_admin_tidak_bisa_buka_halaman_generate_qr_stasiun(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('sistem.qr-stasiun.index'))->assertForbidden();
    }

    public function test_guest_belum_login_dilempar_ke_halaman_login(): void
    {
        $this->get(route('sistem.qr-stasiun.index'))->assertRedirect(route('login'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=QrStasiunHalamanTest`
Expected: FAIL — route `sistem.qr-stasiun.index` not defined

- [ ] **Step 3: Tambah controller**

```php
<?php

namespace App\Http\Controllers\Sistem;

use App\Http\Controllers\Controller;
use App\Services\QrStasiunService;

class QrStasiunController extends Controller
{
    public function __construct(private QrStasiunService $qr) {}

    public function index()
    {
        $stasiun = collect(config('qr_stasiun'))->map(function (array $item) {
            $url = route($item['route']);

            return [
                'label' => $item['label'],
                'url' => $url,
                'svg' => $this->qr->svgFor($url),
            ];
        })->values();

        return view('sistem.qr-stasiun.index', compact('stasiun'));
    }
}
```

- [ ] **Step 4: Daftar route**

Di `routes/web.php`, tambah use statement setelah baris 33 (`use App\Http\Controllers\Sistem\LogAktivitasController;`):

```php
use App\Http\Controllers\Sistem\QrStasiunController;
```

Lalu di dalam grup `Route::middleware('role:admin')->prefix('sistem')->name('sistem.')->group(...)` (yang sudah berisi rute `backup.*` dan `log-aktivitas.index`), tambah baris:

```php
        Route::get('qr-stasiun', [QrStasiunController::class, 'index'])->name('qr-stasiun.index');
```

- [ ] **Step 5: Buat view minimal biar test lolos duluan (view lengkap di Task 4)**

Buat folder `resources/views/sistem/qr-stasiun/` dan file `index.blade.php`:

```blade
@extends('layouts.app')
@section('title', 'Generate QR Stasiun')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-qr-code"></i>Generate QR Stasiun</h1>
</div>

@foreach($stasiun as $s)
    <div class="erp-panel" style="max-width:320px;margin-bottom:16px">
        <div class="erp-panel-header">{{ $s['label'] }}</div>
        <div class="erp-panel-body" style="text-align:center">
            {!! $s['svg'] !!}
        </div>
    </div>
@endforeach
@endsection
```

(View ini akan disempurnakan Task 4 — tambah tombol cetak & style print.)

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --filter=QrStasiunHalamanTest`
Expected: PASS (6 tests: 1 admin + 4 non-admin dari data provider + 1 guest)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Sistem/QrStasiunController.php routes/web.php resources/views/sistem/qr-stasiun/index.blade.php tests/Feature/Sistem/QrStasiunHalamanTest.php
git commit -m "feat(qr-stasiun): route + controller + halaman minimal Generate QR Stasiun"
```

---

## Task 4: View lengkap (cetak) + sidebar

**Files:**
- Modify: `resources/views/sistem/qr-stasiun/index.blade.php` (dari Task 3)
- Modify: `config/sidebar.php`
- Reference (nol dimodifikasi kecuali Step 3 menemukan hard-coded count): `tests/Feature/Layout/SidebarNavTest.php`, `tests/Unit/SidebarMenuTest.php`

**Interfaces:**
- Consumes: `$stasiun` dari Task 3 (`label`, `url`, `svg`)

- [ ] **Step 1: Tulis ulang view dengan tombol cetak + style print**

```blade
@extends('layouts.app')
@section('title', 'Generate QR Stasiun')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-qr-code"></i>Generate QR Stasiun</h1>
</div>

<div style="font-size:12.5px;color:var(--text-3);margin-bottom:18px">
    QR di bawah statis — sekali dicetak dan ditempel di stasiun, tidak perlu digenerate ulang.
    Nambah stasiun baru dilakukan lewat kode (`config/qr_stasiun.php`), bukan dari halaman ini.
</div>

<div style="display:flex;flex-wrap:wrap;gap:16px">
    @foreach($stasiun as $s)
        <div class="qr-kartu erp-panel" style="width:280px">
            <div class="erp-panel-header">{{ $s['label'] }}</div>
            <div class="erp-panel-body" style="text-align:center">
                <div class="qr-gambar">{!! $s['svg'] !!}</div>
                <div style="font-size:11px;color:var(--text-3);word-break:break-all;margin:10px 0">{{ $s['url'] }}</div>
                <button type="button" class="btn btn-outline-secondary btn-sm qr-tombol-cetak" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Cetak
                </button>
            </div>
        </div>
    @endforeach
</div>

@push('styles')
<style>
    /* Saat mencetak, cuma kartu QR yang tampak — shell (topbar/sidebar) dan
       kartu lain disembunyikan supaya satu print job = satu QR di satu lembar. */
    @media print {
        #topbar, #sidebar, #sb-overlay, .page-header, .qr-tombol-cetak { display: none !important; }
        #main { padding: 0 !important; margin: 0 !important; }
        .qr-kartu { display: none !important; }
        .qr-kartu:target { display: block !important; border: none !important; }
    }
</style>
@endpush
@endsection
```

- [ ] **Step 2: Tambah baris sidebar**

Di `config/sidebar.php`, grup `Sistem` (baris berisi `Backup`, `Log Aktivitas`), tambah setelah baris `Log Aktivitas`:

```php
            ['label' => 'Generate QR Stasiun', 'icon' => 'bi-qr-code', 'route' => 'sistem.qr-stasiun.index', 'roles' => ['admin'], 'match' => ['sistem.qr-stasiun.*']],
```

- [ ] **Step 3: Baca test sidebar existing biar tau pola assertion-nya**

Run: `grep -n "function test_" tests/Feature/Layout/SidebarNavTest.php tests/Unit/SidebarMenuTest.php`

Kalau ada test yang menghitung jumlah item per grup secara eksplisit (bukan generik "render tanpa error"), sesuaikan angkanya di situ — SidebarMenuTest kemungkinan cuma test generik (baca config, render nol 500), jadi biasanya nol perlu ubahan. Kalau ternyata ada hard-coded count, catat di sini sebelum lanjut.

- [ ] **Step 4: Jalankan test sidebar + qr-stasiun**

Run: `php artisan test --filter=SidebarMenuTest --filter=SidebarNavTest --filter=QrStasiunHalamanTest`
Expected: PASS semua

- [ ] **Step 5: Pint**

Run: `vendor/bin/pint app tests --test`
Expected: `passed` (view/config blade tidak masuk scope Pint, nol yang perlu diformat dari task ini kecuali ada file PHP yang kena)

- [ ] **Step 6: Commit**

```bash
git add resources/views/sistem/qr-stasiun/index.blade.php config/sidebar.php
git commit -m "feat(qr-stasiun): halaman cetak QR + entri sidebar Sistem"
```

---

## Task 5: Halaman 403 mobile-ready

**Files:**
- Create: `resources/views/errors/403.blade.php`
- Test: `tests/Feature/Auth/HalamanAksesDitolakTest.php`

**Interfaces:**
- Tidak ada — halaman error resolve otomatis lewat konvensi nama file Laravel (`resources/views/errors/{status}.blade.php`), nol registrasi tambahan.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanAksesDitolakTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_menampilkan_halaman_kustom_bukan_bawaan_laravel(): void
    {
        $staffProduksi = User::factory()->create(['role' => 'staff_produksi']);

        // staff_produksi tidak boleh akses input.repair.create (role:admin,staff_repair)
        $response = $this->actingAs($staffProduksi)->get(route('input.repair.create'));

        $response->assertForbidden();
        $response->assertSee('Akses Ditolak');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=HalamanAksesDitolakTest`
Expected: FAIL — halaman 403 bawaan Laravel tidak mengandung teks "Akses Ditolak"

- [ ] **Step 3: Buat view error 403**

```blade
@extends('layouts.auth')
@section('title', 'Akses Ditolak')
@section('auth_title', 'Akses Ditolak')
@section('auth_subtitle', 'Akun ini tidak punya izin untuk membuka halaman tersebut.')

@section('content')
<a href="{{ route('dashboard') }}" class="btn-login" style="display:block;text-align:center;text-decoration:none">
    Kembali ke Dashboard
</a>
@endsection
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=HalamanAksesDitolakTest`
Expected: PASS

- [ ] **Step 5: Jalankan seluruh suite auth & role-gate biar nol regresi**

Run: `php artisan test --filter=Auth --filter=AuthorizationTest --filter=GuestWriteBlockTest`
Expected: PASS semua (halaman 403 baru dipakai di banyak tempat — pastikan nol test lain yang justru mengecek teks bawaan Laravel seperti "403" polos)

- [ ] **Step 6: Commit**

```bash
git add resources/views/errors/403.blade.php tests/Feature/Auth/HalamanAksesDitolakTest.php
git commit -m "feat(mobile): halaman 403 kustom mobile-ready (layouts.auth)"
```

---

## Task 6: Test alur scan QR → login → intended redirect

Nol perubahan kode — `redirect()->intended()` di `AuthenticatedSessionController::store()` sudah menangani ini. Task ini murni mengunci perilaku dengan test supaya siapa pun yang mengubah `AuthenticatedSessionController` di masa depan tahu alur ini harus tetap jalan.

**Files:**
- Test: `tests/Feature/Auth/ScanQrRedirectTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simulasi: staff scan QR ke input.repair.create sebelum login. Laravel
 * bawaan (redirect()->intended()) harus melempar ke /login dulu lalu
 * membalikkan persis ke URL yang di-scan setelah autentikasi sukses —
 * nol kode tambahan dibutuhkan, ini murni mengunci perilaku itu.
 */
class ScanQrRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_qr_sebelum_login_diarahkan_balik_ke_url_yang_diminta(): void
    {
        $staff = User::factory()->create(['role' => 'staff_repair', 'password' => bcrypt('rahasia123')]);

        // 1. Scan QR sebelum login → dilempar ke halaman login, intended URL tersimpan di session
        $this->get(route('input.repair.create'))->assertRedirect(route('login'));

        // 2. Login
        $response = $this->post(route('login'), [
            'email' => $staff->email,
            'password' => 'rahasia123',
        ]);

        // 3. Balik persis ke input.repair.create, bukan ke dashboard
        $response->assertRedirect(route('input.repair.create'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_scan_qr_role_salah_setelah_login_kena_403_bukan_500(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc', 'password' => bcrypt('rahasia123')]);

        $this->get(route('input.repair.create'))->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => $qc->email, 'password' => 'rahasia123']);

        $this->get(route('input.repair.create'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test**

Run: `php artisan test --filter=ScanQrRedirectTest`
Expected: PASS — kalau FAIL, itu regresi di `AuthenticatedSessionController` yang harus diperbaiki, bukan di test ini

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Auth/ScanQrRedirectTest.php
git commit -m "test(auth): kunci alur redirect()->intended() untuk scan QR stasiun"
```

---

## Task 7: Mobile — shell topbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php`

**Interfaces:** Tidak ada — CSS + penambahan class HTML, nol perubahan struktur/route.

Topbar sekarang (baris ~1268-1360) menumpuk: hamburger, logo+brand+tag, breadcrumb judul halaman, bell notifikasi, tanggal, blok user (ikon+nama+role), badge tamu — semuanya dalam satu baris flex tanpa `flex-wrap` dan nol media query topbar. Di layar 375px ini overflow horizontal. Sidebar sudah punya media query mobile di baris 361-366; breadcrumb/tanggal/teks user disembunyikan di situ juga supaya cuma satu tempat aturan mobile shell hidup.

- [ ] **Step 1: Tandai elemen yang perlu disembunyikan di mobile**

Di `resources/views/layouts/app.blade.php`, ubah baris (sekitar 1324-1327):

```blade
        <div class="topbar-item">
            <i class="bi bi-calendar3"></i>
            <span>{{ now()->format('d M Y') }}</span>
        </div>
```

jadi:

```blade
        <div class="topbar-item tb-tanggal">
            <i class="bi bi-calendar3"></i>
            <span>{{ now()->format('d M Y') }}</span>
        </div>
```

- [ ] **Step 2: Tambah aturan mobile ke media query yang sudah ada**

Di `resources/views/layouts/app.blade.php`, cari blok (sekitar baris 361-366):

```css
        @media (max-width: 767px) {
            body.sb-mobile-open #sb-overlay { display: block; }
            body.sb-mobile-open #sidebar    { width: var(--sb-exp-w); overflow-y: auto; }
            body:not(.sb-mobile-open) #sidebar { width: 0; }
            #main { margin-left: 0 !important; }
        }
```

Ganti jadi:

```css
        @media (max-width: 767px) {
            body.sb-mobile-open #sb-overlay { display: block; }
            body.sb-mobile-open #sidebar    { width: var(--sb-exp-w); overflow-y: auto; }
            body:not(.sb-mobile-open) #sidebar { width: 0; }
            #main { margin-left: 0 !important; }

            /* Topbar penuh sesak di layar sempit — breadcrumb judul, tanggal,
               teks nama+role user, dan tag "MES" dibuang, sisa ikon inti
               (hamburger, brand, notif, ikon user) yang muat di satu baris
               tanpa overflow horizontal. */
            .topbar-page,
            .tb-tanggal,
            .brand-tag,
            .user-info { display: none; }
            .app-brand { padding: 0 10px; }
            .topbar-item.user-block { padding: 0 12px; }
        }
```

- [ ] **Step 3: Verifikasi tidak ada test yang mengandalkan elemen ini selalu terlihat lewat CSS (test PHPUnit tidak render CSS, jadi assertSee tetap lolos)**

Run: `grep -rn "tb-tanggal\|topbar-page\|user-info" tests/`
Expected: nol hasil, atau kalau ada, baca dulu apa yang dicek sebelum lanjut (assertSee soal teks tetap aman karena elemen tetap ada di DOM, cuma `display:none` di breakpoint tertentu)

- [ ] **Step 4: Jalankan test layout**

Run: `php artisan test --filter=Layout`
Expected: PASS semua (AssetIntegrityTest, ErpWorkspaceMarkupTest, GuestMarkupBalanceTest, dll — nol yang bergantung ke topbar)

- [ ] **Step 5: Verifikasi visual manual (browser resize ke 375px)**

Buka halaman apa saja lewat browser tool, `resize_window` ke `{width: 375, height: 812}` (preset mobile), screenshot topbar — pastikan hamburger, brand, notif bell, ikon user semua muat satu baris tanpa terpotong/overflow.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "fix(mobile): topbar tidak overflow di layar <768px"
```

---

## Task 8: Mobile — form LHR Regular (tombol aksi)

**Files:**
- Modify: `resources/views/input/repair/create.blade.php`

Form ini sudah punya media query `@media (max-width: 767.98px)` yang men-stack field (`.lhp-baris`). Yang belum: tombol SAVE TO DRAFT / SUBMIT DRAFT / CANCEL memakai `d-flex justify-content-end` — di layar sempit tombol-tombol ini berdempetan kecil, susah dijangkau jempol.

- [ ] **Step 1: Tandai kedua baris tombol dengan class**

Ubah (sekitar baris 165-171):

```blade
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

jadi:

```blade
            <div class="d-flex justify-content-end gap-2 mt-3 lhp-aksi">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

Dan (sekitar baris 234-241):

```blade
            <div class="d-flex gap-2 justify-content-end">
                <span class="text-muted small align-self-center" x-show="editIndex !== null" x-cloak>
                    Selesaikan UPDATE BARIS atau Batal Edit dulu sebelum submit.
                </span>
                <button type="submit" class="btn btn-success" :disabled="rows.length === 0 || editIndex !== null">
                    <i class="bi bi-upload me-1"></i>SUBMIT DRAFT
                </button>
                <button type="button" class="btn btn-danger" @click="batal()">CANCEL</button>
            </div>
```

jadi:

```blade
            <div class="d-flex gap-2 justify-content-end lhp-aksi">
                <span class="text-muted small align-self-center" x-show="editIndex !== null" x-cloak>
                    Selesaikan UPDATE BARIS atau Batal Edit dulu sebelum submit.
                </span>
                <button type="submit" class="btn btn-success" :disabled="rows.length === 0 || editIndex !== null">
                    <i class="bi bi-upload me-1"></i>SUBMIT DRAFT
                </button>
                <button type="button" class="btn btn-danger" @click="batal()">CANCEL</button>
            </div>
```

- [ ] **Step 2: Tambah aturan stack ke media query yang sudah ada di file ini**

Cari blok `@push('styles')` di bagian atas file (sekitar baris 27-33):

```css
@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }
}
```

Ganti jadi:

```css
@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }

    /* Tombol aksi jadi tumpuk penuh lebar — target jempol lebih besar
       daripada tombol kecil berdempetan di ujung kanan. */
    .lhp-aksi { flex-direction: column-reverse; align-items: stretch; }
    .lhp-aksi > .btn { width: 100%; min-height: 44px; }
}
```

(`column-reverse` supaya tombol utama — SAVE TO DRAFT / SUBMIT DRAFT — tetap di atas Batal Edit/Cancel karena urutan DOM aslinya sekunder-dulu-primer-kemudian.)

- [ ] **Step 3: Jalankan test batch repair**

Run: `php artisan test --filter=HalamanBatchRepairTest`
Expected: PASS (test cuma cek `formBaris`, `konfigRepair`, route batch — nol yang bergantung ke class CSS)

- [ ] **Step 4: Verifikasi visual manual**

Browser resize 375px, buka `input.repair.create` sebagai `staff_repair`, screenshot — field stack vertikal, tombol full-width, nol overflow horizontal.

- [ ] **Step 5: Commit**

```bash
git add resources/views/input/repair/create.blade.php
git commit -m "fix(mobile): tombol aksi LHR Regular full-width di layar sempit"
```

---

## Task 9: Verifikasi akhir

**Files:** Tidak ada file baru — task ini murni gerbang kualitas sebelum PR.

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: semua PASS, nol regresi di luar file yang disentuh plan ini

- [ ] **Step 2: Pint**

Run: `vendor/bin/pint app tests --test`
Expected: `passed`

- [ ] **Step 3: Gerbang cakupan migrasi (harus tetap hijau meski nol migrasi baru)**

Run: `./scripts/check-migration-coverage.sh`
Expected: PASS — plan ini nol migrasi baru sama sekali

- [ ] **Step 4: `php artisan route:list` — pastikan route baru terdaftar sekali, nol duplikat**

Run: `php artisan route:list --name=qr-stasiun`
Expected: satu baris `GET|HEAD sistem/qr-stasiun ... sistem.qr-stasiun.index`

- [ ] **Step 5: Manual — scan QR asli**

Kalau sudah deploy: buka `sistem.qr-stasiun.index` sebagai admin di browser desktop, scan QR "LHR Regular" pakai kamera HP asli (bukan browser HP), pastikan HP terbuka ke `input.repair.create`, minta login kalau belum, dan form kebuka dengan layout mobile yang benar.

- [ ] **Step 6: Commit (kalau ada file yang berubah dari Pint)**

```bash
git add -A
git commit -m "chore: pint fixes fase 1 QR stasiun + mobile"
```
