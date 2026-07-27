# Desain: Akun Guest Read-Only

**Tanggal:** 2026-07-27
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

Pihak eksternal (calon customer, auditor, tamu) perlu bisa melihat alur kerja OPTIMA
tanpa kemungkinan mengubah data. Saat ini setiap role yang ada punya kewenangan tulis
di area masing-masing, jadi tidak ada satu pun akun yang aman diserahkan ke luar.

Spec ini menambahkan role baru `guest`: hanya bisa membaca, tidak bisa menulis apa pun,
tidak bisa mengunduh data keluar, dan tidak bisa melihat master data.

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| Halaman yang boleh dilihat | VCB (semua), analitik Maintenance, daftar LHP Produksi/QC/Dandori |
| Daftar breakdown mesin & dies | **Tidak** boleh — dianggap terlalu sensitif |
| Master data (karyawan, mesin, part, shift, jenis NG, users) | Tidak boleh |
| Isi data | Data asli, semua periode, tanpa masking |
| Bentuk akun | Satu akun shared, dibuat admin lewat Master > Users. Tidak ada self-registration |
| Export Excel | Tidak boleh |
| Checksheet PDF preventive | Tidak boleh |
| Chatbot AI | Tidak boleh (hemat kuota DeepSeek berbayar) |

## Prinsip Arsitektur

**Default-deny.** Guest hanya masuk ke grup route yang menyebut `guest` secara eksplisit.
Route baru yang dibuat di kemudian hari otomatis tertutup untuk guest tanpa perlu diingat.

**Dua lapis.** Lapis pertama route-level (`role:...,guest`), lapis kedua middleware global
`DenyGuestWrite` yang menolak semua request non-GET dari guest. Kalau suatu saat ada route
tulis yang keliru dimasukkan ke grup guest, lapis kedua yang menangkap.

## 1. Role & Database

### Migration

File baru `database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php`,
mengikuti pola `2026_07_15_000002_add_staff_maintenance_to_users_role_enum.php`:

- `up()`: `ALTER TABLE users MODIFY role ENUM('admin','staff_produksi','staff_qc','manager','staff_maintenance','guest') NOT NULL DEFAULT 'staff_produksi'`
- `down()`: kembalikan enum tanpa `'guest'`
- Bercabang untuk driver non-MySQL (sqlite di test) seperti migration sebelumnya
- Bungkus dengan pengecekan driver + try/catch sesuai pola defensif repo ini

Migration dijalankan otomatis di hosting oleh GitHub Actions saat merge ke `main`.

### Model

`app/Models/User.php` — tambah helper:

```php
public function isGuest(): bool
{
    return $this->role === 'guest';
}
```

Nama `isGuest()` dipilih untuk dipakai di Blade sebagai `@if(! auth()->user()->isGuest())`.
**Jangan** pakai direktif Blade `@guest` bawaan Laravel — artinya berbeda (belum login).

### Validasi & Form

- `app/Http/Requests/StoreMasterUserRequest.php:29` — tambah `guest` ke aturan `in:`
- `resources/views/master/users/create.blade.php`, `edit.blade.php`, `index.blade.php` —
  tambah `<option value="guest">Guest (Read-Only)</option>` di dropdown role

Tidak ada seeder. Akun guest dibuat admin lewat UI.

## 2. Route — Restrukturisasi Default-Deny

`routes/web.php` dipecah: route GET yang boleh guest dipindah ke grup baru, sisanya tetap
di grup lama tanpa `guest`.

### Grup yang boleh guest

| Middleware | Route |
|---|---|
| `role:admin,staff_produksi,manager,guest` | `vcb.produksi`, `vcb.produksi.data`, `vcb.produksi.operator-data`, `vcb.mesin`, `vcb.mesin.data`, `vcb.dandori`, `vcb.dandori.data` |
| `role:admin,staff_qc,manager,guest` | `vcb.quality`, `vcb.quality.data`, `vcb.quality.operator-data` |
| `role:admin,staff_maintenance,manager,guest` | `maintenance.mttr`, `maintenance.mttr.data`, `maintenance.mtbf`, `maintenance.mtbf.data`, `maintenance.availability`, `maintenance.availability.data`, `maintenance.mttr-dies`, `maintenance.mttr-dies.data`, `maintenance.mtbf-dies`, `maintenance.mtbf-dies.data`, `maintenance.availability-dies`, `maintenance.availability-dies.data`, `maintenance.control-stroke-mesin`, `maintenance.control-stroke-mesin.data`, `maintenance.control-stroke-mesin.history`, `maintenance.control-stroke-dies`, `maintenance.control-stroke-dies.data`, `maintenance.control-stroke-dies.history` |
| `role:admin,staff_produksi,guest` | `input.produksi.index`, `input.dandori.index` |
| `role:admin,staff_qc,guest` | `input.qc.index` |

`vcb.dandori.export` **tetap di grup lama** (tanpa guest) meskipun satu prefix dengan
`vcb.dandori` — ini titik paling gampang keliru saat implementasi.

### Tetap tertutup untuk guest

- Seluruh grup `master.*` (karyawan, mesin, part, losstime, shift, users, jenis-ng)
- Semua create/store/edit/update/destroy di semua modul
- Semua import, import-template, dan export (`input.produksi.export`, `input.qc.export`,
  `input.dandori.export`, `vcb.dandori.export`, `master.part.export`,
  `maintenance.breakdown.export`, `maintenance.breakdown-dies.export`)
- `maintenance.breakdown.index` dan `maintenance.breakdown-dies.index`
- `maintenance.control-stroke-dies.preventive.checksheet` (PDF)
- `maintenance.control-stroke-{mesin,dies}.preventive.create`
- `chatbot.*`
- `admin.anomali`

### Dashboard

`/dashboard` (routes/web.php:34) tambah cabang eksplisit:

```php
$role === 'guest' => '/vcb/produksi',
```

Sekarang guest tertangkap `default`, tapi cabang eksplisit membuat niatnya terbaca.

## 3. Middleware `DenyGuestWrite`

File baru `app/Http/Middleware/DenyGuestWrite.php`:

- Kalau user login dan `role === 'guest'` dan method **bukan** `GET`/`HEAD` → `abort(403, 'Akun tamu hanya bisa melihat data.')`
- Pengecualian tunggal: route bernama `logout` (POST) — guest tetap harus bisa keluar

Didaftarkan di `bootstrap/app.php` sebagai middleware web global:

```php
$middleware->web(append: [\App\Http\Middleware\DenyGuestWrite::class]);
```

Dipasang global (bukan cuma di grup `auth` milik `routes/web.php`) supaya route di
`routes/auth.php` ikut tercakup.

Konsekuensi yang disengaja: `password.update` (PUT) ikut tertolak untuk guest, jadi tamu
tidak bisa mengganti password akun shared dan mengunci tamu lain. Ganti password hanya
lewat admin di Master > Users.

## 4. Perubahan UI

### Sidebar

`resources/views/layouts/app.blade.php` (blok navigasi mulai baris ~923) — tambah `'guest'`
ke whitelist item: 4 menu VCB, MTTR/MTBF/Availability (mesin + dies), Control Stroke
(mesin + dies), dan 3 index LHP.

Blok maintenance saat ini satu `@if` gabungan yang juga membungkus link breakdown. Blok itu
dipecah per item supaya link breakdown tetap tertutup untuk guest sementara link analitik
terbuka.

### Tombol tulis yang perlu disembunyikan

Halaman-halaman ini belum meng-gate tombolnya sama sekali, karena selama ini semua yang
bisa membuka halaman juga berhak menulis:

| View | Yang disembunyikan untuk guest |
|---|---|
| `resources/views/input/produksi/index.blade.php` | tombol "Input Baru" + drawer form, panel Import Excel, panel Export Excel |
| `resources/views/input/qc/index.blade.php` | sama |
| `resources/views/input/dandori/index.blade.php` | sama |
| `resources/views/vcb/produksi.blade.php` | tombol Export |
| `resources/views/vcb/quality.blade.php` | tombol Export |
| `resources/views/vcb/mesin.blade.php` | tombol Export |
| `resources/views/vcb/dandori.blade.php` | tombol Export |
| `resources/views/maintenance/control-stroke-dies-history.blade.php` | link checksheet PDF |

Sudah aman tanpa perubahan (gate role eksplisit yang tidak akan cocok dengan `guest`):
tombol edit/hapus LHP (`role === 'admin'`), tombol Input Preventive di control-stroke
mesin & dies (`in_array(..., ['admin','staff_maintenance'])`).

### Badge mode tamu

Di topbar, untuk guest saja: badge `MODE TAMU — READ ONLY`. Gaya mengikuti desain
industrial monochrome yang ada (tanpa shadow, Arial). Tujuannya supaya tamu paham kenapa
tombol aksi tidak muncul.

## 5. Testing

Menambah kasus ke `tests/Feature/AuthorizationTest.php`:

1. **Akses diizinkan** — guest GET setiap route pada tabel grup guest → 200 (data provider).
2. **Akses ditolak** — guest GET → 403 untuk: semua route master, 7 route export,
   semua import-template, checksheet PDF, chatbot, `admin.anomali`, breakdown index
   mesin + dies, semua form create/edit.
3. **Jaring pengaman** — guest POST/PUT/DELETE ke route apa pun → 403, termasuk kasus
   route tulis yang sengaja didaftarkan ke grup guest (menguji `DenyGuestWrite` berdiri
   sendiri, bukan cuma efek route group).
4. **Logout tetap jalan** — guest POST `logout` → 302.
5. **Ganti password ditolak** — guest PUT `password` → 403.
6. **Sidebar** — render halaman sebagai guest, pastikan tidak ada link master data,
   tidak ada link breakdown, tidak ada tombol Input Baru / Import / Export.

Seluruh suite (309 test saat ini) harus hijau sebelum PR.

## Di Luar Scope

Tombol "Export Excel" di `vcb/produksi.blade.php:157`, `vcb/quality.blade.php:155`, dan
`vcb/mesin.blade.php:36` menunjuk ke `/export/produksi` dan `/export/qc` — route tersebut
tidak ada di `routes/web.php`, jadi tombolnya menghasilkan 404 untuk semua role, bukan
cuma guest. Bug lama yang berdiri sendiri; tidak diperbaiki di sini, cukup disembunyikan
dari guest bersama tombol export lain.
