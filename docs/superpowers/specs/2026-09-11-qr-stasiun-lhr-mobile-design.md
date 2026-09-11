# Desain: QR Stasiun LHR Regular + Mobile-Ready Fase 1

**Tanggal:** 2026-09-11
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

Staff repair harus buka laptop/PC buat input LHR Regular. Tujuannya: staff cukup scan
QR Code dari HP di stasiun kerja, otomatis diarahkan ke form input LHR Regular
(`input.repair.create`), login kalau belum, langsung input dari HP.

Scope mobile-responsive "seluruh aplikasi" sengaja **dipecah jadi 2 proyek**:
- **Fase 1 (spec ini):** QR generator + halaman yang benar-benar dipakai lewat scan QR
  (login, 403, LHR Regular batch form, shell topbar/sidebar).
- **Fase 2 (spec terpisah, menyusul):** sisa aplikasi (LHP Produksi/QC/Dandori/Breakdown,
  VCB dashboard+chart, Master Data, Maintenance) — dikerjakan bertahap per grup halaman.

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| QR arahnya | Statis, satu tujuan: `input.repair.create` (LHR Regular). Bukan QR generik multi-role |
| Kalau belum login | Laravel `redirect()->intended()` bawaan (sudah ada, nol perubahan) — lempar ke `/login`, abis submit balik ke URL asal |
| Kalau role salah scan | 403 standar Laravel (perlu dipastikan halamannya nggak somplak di HP) |
| Generate QR stasiun lain nanti | Menu admin baru: `Sistem > Generate QR Stasiun`, daftar station dari config, bukan generate manual satu-satu |
| Cara render QR | `bacon/bacon-qr-code` (sudah ada di composer.json, dipakai 2FA), SVG, render lokal — nol dependency baru, nol API pihak ketiga |
| Nyimpen QR di DB? | Tidak. QR dihasilkan on-the-fly dari config setiap halaman dibuka — URL station tidak pernah berubah |
| Cetak/download | Tombol Cetak (browser print dialog, bisa Save as PDF dari situ) — nol endpoint download terpisah |
| Scope mobile Fase 1 | Login, 403, `input.repair.create`, shell topbar/sidebar. Bukan seluruh app |

## 1. Menu Generate QR Stasiun

### Config

File baru `config/qr_stasiun.php`:

```php
return [
    ['label' => 'LHR Regular', 'route' => 'input.repair.create'],
];
```

Nambah stasiun baru nanti = nambah baris di sini, nol kode baru. Isi awal cuma 1 baris
sesuai scope Fase 1.

### Route & Controller

- `GET /sistem/qr-stasiun` → `Sistem\QrStasiunController@index`, middleware
  `role:admin` (pola sama seperti `sistem.backup.*`, `sistem.log-aktivitas.*`)
- Controller loop `config('qr_stasiun')`, tiap entri: `route($item['route'], absolute: true)`
  lalu render SVG lewat method baru `QrStasiunService::svgFor(string $url): string` —
  isinya persis pola `TwoFactorAuthService::qrCodeSvg()` (`ImageRenderer` +
  `SvgImageBackEnd` + `Writer`), size 240px (lebih besar dari QR 2FA yang 200px, karena
  ini discan dari jarak lebih jauh — ditempel di dinding/meja, bukan di layar).

### View

`resources/views/sistem/qr-stasiun/index.blade.php`, pola `.erp-ws` seperti halaman
Sistem lain. Grid kartu, tiap kartu: label + SVG QR + tombol "Cetak" (`onclick="window.print()"`
dibungkus CSS `@media print` yang cuma nampilin kartu itu, sembunyiin sidebar/topbar/kartu lain).

### Sidebar

Tambah 1 baris di `config/sidebar.php`, grup Sistem:

```php
['label' => 'Generate QR Stasiun', 'icon' => 'bi-qr-code', 'route' => 'sistem.qr-stasiun.index', 'roles' => ['admin'], 'match' => ['sistem.qr-stasiun.*']],
```

## 2. Alur Redirect (nol perubahan kode)

`AuthenticatedSessionController::store()` sudah pakai `redirect()->intended(...)` —
mekanisme bawaan Laravel yang otomatis simpan URL yang diminta sebelum auth-redirect,
lalu kembalikan ke situ setelah login sukses. Scan QR ke `input.repair.create` saat
belum login otomatis:

1. Middleware `auth` tangkap, redirect ke `/login`, simpan intended URL di session
2. User login, `LoginRequest::authenticate()` jalan seperti biasa (termasuk 2FA kalau aktif)
3. `redirect()->intended(route('dashboard'))` balikin ke `input.repair.create` (bukan dashboard)

Role gate (`role:admin,staff_repair`) tetap jalan seperti biasa — kalau role salah, 403
muncul setelah login, bukan sebelum. Nol perubahan di controller/middleware ini.

## 3. Mobile-Ready Fase 1

CSS-only, nol restrukturisasi Blade kalau bisa dihindari. File yang disentuh:

- `resources/views/layouts/auth.blade.php` — halaman login. Cek input/button jadi
  full-width & touch-friendly (≥44px, contek pola `@media (pointer: coarse)` yang sudah
  ada di `layouts/app.blade.php`).
- `resources/views/layouts/app.blade.php` — shell topbar/sidebar, perbaiki kalau ada
  yang masih somplak di breakpoint `<768px` (sidebar overlay sudah ada, tinggal audit).
- `resources/views/input/repair/create.blade.php` (batch form LHR Regular) — field jadi
  stack full-width di layar sempit, toolbar `erp-tb` wrap kalau kepanjangan, tombol
  submit gampang dijangkau jempol.
- Halaman 403 — pakai default Laravel (`resources/views/errors/` belum ada override).
  Buat `resources/views/errors/403.blade.php` baru, `@extends('layouts.auth')` biar
  konsisten gaya + otomatis kebagian perbaikan mobile dari `layouts/auth.blade.php`,
  bukan halaman polos bawaan framework.

**Nol perubahan ke halaman lain** (LHP Produksi/QC/Dandori/Breakdown, VCB, Master,
Maintenance) — itu Fase 2.

## 4. Error Handling

- Role salah scan QR → 403 standar (sudah ada, cuma dipastikan mobile-friendly tampilannya)
- Config `qr_stasiun.php` isi route name yang salah/dihapus → `route()` throw
  `RouteNotFoundException` saat render halaman admin. Nol guard tambahan — ini halaman
  admin-only, kesalahan config ketahuan langsung pas dibuka, bukan disembunyikan pengguna akhir.

## 5. Testing

- Feature test baru: `sistem.qr-stasiun.index` cuma bisa diakses admin (403 untuk role
  lain, redirect login untuk guest), halaman render 200 dan mengandung tag `<svg>`
- Feature test: scan flow — request `input.repair.create` tanpa login → redirect
  `/login` → login sebagai `staff_repair` → redirect balik ke `input.repair.create`
  (pakai `assertRedirect` berantai, pola yang sama dipakai test 2FA/guest existing)
- Manual: resize browser ke lebar HP (375px) buat login, 403, dan form LHR Regular;
  kalau memungkinkan scan QR asli dari HP fisik setelah deploy

## Yang Sengaja Tidak Dikerjakan Sekarang

- Generate QR untuk stasiun lain selain LHR Regular (tinggal tambah baris config kapan
  perlu, nol kerjaan kode)
- Mobile-responsive halaman selain yang disebut di atas (Fase 2)
- Landing page per-role otomatis setelah login (opsi B yang tidak dipilih)
- Download QR sebagai file gambar terpisah (cukup lewat dialog print browser)
