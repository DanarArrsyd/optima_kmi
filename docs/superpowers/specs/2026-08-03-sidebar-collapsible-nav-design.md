# Sidebar — Semua Menu Jadi Grup Collapsible — Design

**Tanggal:** 2026-08-03
**Status:** Disetujui, siap masuk rencana implementasi

---

## Latar

Sidebar admin sekarang **±27 baris ≈ 1080px**, sementara area pandang di bawah topbar ~700px.
Konsekuensinya menu Master Data tidak pernah terlihat tanpa scroll, padahal itu tujuan navigasi
harian admin.

Dari empat section yang ada, hanya **Dashboard** yang punya grup collapsible (`vcbGroups`,
accordion single-open + memori `localStorage`). Input Data (6 item) dan Master Data (7 item)
rata tanpa grup.

Spec ini memperluas mekanisme accordion yang sudah terbukti ke seluruh sidebar.

## Tujuan

Sidebar yang **tidak pernah bisa di-scroll**, bukan sekadar lebih pendek.

| Keadaan | Baris | Tinggi |
|---|---|---|
| Semua grup tertutup | 8 | ≈ 320px |
| Kasus terburuk (Master Data terbuka, 7 anak) | 15 | ≈ 600px |

600px < 700px area pandang. Karena accordion single-open, 15 baris itu **plafon keras**, bukan
rata-rata — tidak ada kombinasi klik yang bisa melewatinya.

## Bukan tujuan (sengaja)

- Mengubah hak akses role mana pun. Setiap item mempertahankan daftar role-nya persis.
- Mode rail ikon-saja (sidebar sempit tapi terlihat). Sidebar sekarang biner: 0px atau 220px.
- Menambah, membuang, atau memindah halaman. Hanya penyusunan menu.
- Migrasi database — nol. Murni view + config, jadi gerbang `deploy.yml` tidak tersentuh.
- Sub-grup bertingkat. Struktur dipilih **satu tingkat rata**, lihat di bawah.

---

## Struktur menu

Satu tingkat. Label section (`AI ASSISTANT`, `INPUT DATA`, `DASHBOARD`, `MASTER DATA`) dibuang —
tugasnya diambil alih header grup.

Tampilan admin (paling penuh):

| # | Baris | Tipe | Isi saat terbuka |
|---|---|---|---|
| 1 | AI Assistant | tautan | — |
| 2 | Anomali LHP | tautan | — |
| 3 | Input Data | grup | LHP Produksi, Dandori, LHP Quality, Breakdown Mesin, Breakdown Dies |
| 4 | Produksi | grup | VCB Produksi, VCB Mesin, VCB Dandori, VCB NG Proses, Daftar NG Proses |
| 5 | Quality | grup | VCB Quality, VCB NG Total, Daftar NG Total |
| 6 | MTC Mesin | grup | MTTR, MTBF, Availability, Control Stroke |
| 7 | MTC Dies | grup | MTTR, MTBF, Availability, Control Stroke |
| 8 | Master Data | grup | Karyawan, Mesin, Part/Dies, Loss Time, Shift, Jenis NG, Users |

### Keputusan yang perlu alasan

**AI Assistant dan Anomali LHP tetap tautan tunggal, bukan grup.** Grup berisi satu anak menambah
satu klik tanpa menghemat satu baris pun.

**Anomali LHP di luar grup Input Data.** Item ini berwarna kuning karena memang harus menarik
perhatian; menyembunyikannya di balik header tertutup membalik maksudnya.

**Input Data tidak dipecah jadi Input Produksi / Input Quality / Input Maintenance.** Pecahan itu
menghasilkan grup satu-anak untuk `staff_qc` (hanya LHP Quality) — persis anti-pola di atas.

**Grup ikut hilang kalau kosong untuk role tersebut**, bukan tampil terbuka-kosong.

| Role | Baris yang terlihat |
|---|---|
| `admin` | 8 |
| `manager` | AI Assistant, Input Data (2 breakdown), Produksi, Quality, MTC Mesin, MTC Dies = 6 |
| `staff_produksi` | Input Data (2), Produksi = 2 |
| `staff_qc` | Input Data (1), Quality = 2 |
| `staff_maintenance` | Input Data (2), MTC Mesin, MTC Dies = 3 |
| `guest` | Input Data (3), Produksi, Quality, MTC Mesin, MTC Dies = 5 |

`guest` melihat Input Data berisi LHP Produksi, Dandori, LHP Quality (semuanya read-only) tanpa
Breakdown — sama seperti sekarang.

## Perilaku

**Single-open.** Membuka satu grup menutup yang lain. Ini yang mengubah "lebih pendek" jadi
"tidak pernah scroll", dan konsisten dengan `vcbGroups` yang sudah dipakai pengguna.

**Keadaan awal saat halaman dimuat**, berurutan:

1. Grup yang memuat halaman aktif → terbuka.
2. Kalau URL tidak masuk grup mana pun (mis. halaman profil) → grup terakhir dari `localStorage`.
3. Kalau `localStorage` kosong → semua tertutup.

Poin 3 berbeda dari sekarang: `vcbGroups` jatuh ke default `'produksi'`. Untuk `staff_maintenance`
default itu adalah grup yang tidak dia punya, jadi hasilnya semua tertutup secara kebetulan.
Sekarang jadi eksplisit.

Kunci `localStorage` berganti `optima_vcb_group` → `optima_nav_group`. Nilai lama (`'produksi'`,
`'quality'`, `'mtcmesin'`, `'mtcdies'`) tetap valid sebagai key grup, jadi tidak ada migrasi —
pengguna lama hanya kehilangan satu pilihan terakhir, sekali.

---

## Deteksi `active`: dari URI ke nama route

Ini bukan perbaikan sampingan. Grup yang dibentuk dinamis mustahil dibangun di atas logika
sekarang.

### Yang ada sekarang

Tiga gaya pencocokan tercampur dalam satu file:

```php
str_starts_with($uri, 'input/produksi')                                      // prefiks
$uri === 'vcb/ng-proses'                                                     // persis
str_starts_with($uri,'maintenance/availability')
    && !str_starts_with($uri,'maintenance/availability/control-stroke')      // prefiks + negasi
```

Ditambah `$vcbActive = match(true) {...}` sepanjang 16 baris yang **sensitif urutan**:
`maintenance/mttr-dies` wajib diperiksa sebelum `maintenance/mttr`, kalau terbalik seluruh sisi
Dies salah tanpa error.

Pola prefiks ini sudah tiga kali menyalakan dua entri sekaligus; komentar peringatannya masih
menempel di `resources/views/layouts/app.blade.php:1035` dan `:1058`.

### Gantinya

`request()->routeIs()` dengan pola nama route eksplisit per item:

```php
request()->routeIs('vcb.ng-proses')   // cocok PERSIS — bukan prefiks
```

`vcb.ng-proses` tidak lagi cocok dengan `vcb.ng-proses.daftar`. Semua cek persis, semua negasi,
dan seluruh blok `$vcbActive` **dihapus**, bukan dipindah.

**Grup aktif = ada anak yang aktif.** Turunan otomatis, tidak ada peta terpisah yang bisa
melenceng dari daftar item.

### Aturan pola

Setiap item mendeklarasikan array pola. Wildcard hanya boleh dipakai kalau tidak ada menu lain
yang berbagi prefiks nama.

| Item | Pola | Catatan |
|---|---|---|
| LHP Produksi | `input.produksi.*` | ikut menyala di `create`/`edit`, seperti sekarang |
| Dandori | `input.dandori.*` | |
| LHP Quality | `input.qc.*` | |
| Breakdown Mesin | `maintenance.breakdown.*` | tidak cocok `maintenance.breakdown-dies.*` — setelah `breakdown` ada `-`, bukan `.` |
| Breakdown Dies | `maintenance.breakdown-dies.*` | |
| Anomali LHP | `admin.anomali`, `admin.anomali.approve` | |
| VCB Produksi | `vcb.produksi` | |
| VCB Mesin | `vcb.mesin` | |
| VCB Dandori | `vcb.dandori` | |
| VCB NG Proses | `vcb.ng-proses` | **persis** — wildcard akan menelan `.daftar` |
| Daftar NG Proses | `vcb.ng-proses.daftar` | |
| VCB Quality | `vcb.quality` | |
| VCB NG Total | `vcb.ng-total` | **persis**, alasan sama |
| Daftar NG Total | `vcb.ng-total.daftar` | |
| MTTR / MTBF / Availability (mesin) | `maintenance.mttr`, `maintenance.mtbf`, `maintenance.availability` | persis; `-dies` adalah nama lain, tidak ambigu |
| Control Stroke Mesin | `maintenance.control-stroke-mesin*` | mencakup `history` & `preventive.create` yang keduanya halaman |
| MTTR / MTBF / Availability (dies) | `maintenance.mttr-dies`, `maintenance.mtbf-dies`, `maintenance.availability-dies` | |
| Control Stroke Dies | `maintenance.control-stroke-dies*` | |
| Master · tiap item | `master.karyawan.*`, `master.mesin.*`, `master.part.*`, `master.losstime.*`, `master.shift.*`, `master.jenis-ng.*`, `master.users.*` | |
| AI Assistant | `chatbot.*` | |

Route `*.data`, `*.detail`, `*.import`, `*.export` adalah endpoint JSON/unduhan yang tidak pernah
merender layout, jadi tidak perlu masuk pola.

---

## Bentuk kode

### `config/sidebar.php` — definisi

Array polos. **Tanpa closure dan tanpa panggilan `route()`** supaya aman kalau kelak
`config:cache` dipakai (deploy sekarang belum, hanya `composer --optimize-autoloader`).

```php
return [
    ['type' => 'link', 'key' => 'chatbot', 'label' => 'AI Assistant',
     'icon' => 'bi-robot', 'route' => 'chatbot.index',
     'roles' => ['admin', 'manager'], 'match' => ['chatbot.*']],

    ['type' => 'group', 'key' => 'input', 'label' => 'Input Data', 'icon' => 'bi-clipboard-data',
     'items' => [
         ['label' => 'LHP Produksi', 'icon' => 'bi-clipboard-data',
          'route' => 'input.produksi.index',
          'roles' => ['admin', 'staff_produksi', 'guest'],
          'match' => ['input.produksi.*']],
         // ...
     ]],
];
```

Role grup **tidak dideklarasikan** — diturunkan dari gabungan role anaknya. Satu tempat saja yang
bisa salah.

### `app/Support/SidebarMenu.php` — logika

Kelas kecil, bisa diuji tanpa merender HTML:

| Metode | Tugas |
|---|---|
| `forRole(string $role): array` | saring item & grup menurut role, buang grup kosong |
| `isActive(array $item): bool` | `request()->routeIs(...$item['match'])` |
| `activeGroup(string $role): ?string` | key grup yang punya anak aktif, `null` kalau tidak ada |

### `resources/views/partials/sidebar.blade.php` — render

Loop ~30 baris atas hasil `forRole()`. Dipanggil dari `layouts/app.blade.php` lewat `@include`.

Alasan dipecah: `app.blade.php` 1374 baris, 240 di antaranya sidebar. Menambah satu grup sekarang
berarti menyunting tiga tempat terpisah (markup, `$vcbActive`, CSS).

### CSS

Kelas yang ada dipakai ulang apa adanya: `.nav-group-header`, `.nav-group-body`, `.nav-caret`,
`.nav-item-link`, `.has-active`. Yang berubah hanya `.nav-section-label` jadi tidak terpakai
(dihapus) dan `.nav-section` menyusut jadi satu pembungkus.

### Alpine

`vcbGroups()` diganti `navGroups()` — logika sama persis, hanya nama kunci `localStorage` dan
cakupannya yang berbeda.

---

## Test

Koreksi atas anggapan awal: sidebar **sudah punya** test, yaitu
`tests/Feature/Layout/NgListSidebarTest.php` (6 test). Cakupannya sempit — khusus empat entri NG.

### Yang harus diperbarui

`NgListSidebarTest::test_grup_sidebar_tetap_benar_saat_daftar_dibuka` menegaskan string literal
`vcbGroups('produksi')`. Refactor akan mematahkannya. Ganti jadi `navGroups('produksi')`.
Lima test lainnya menguji perilaku, bukan implementasi, jadi harus **tetap hijau tanpa disunting** —
itu sekaligus bukti refactor tidak mengubah arti.

### Yang ditambahkan — `tests/Feature/Layout/SidebarNavTest.php`

| Test | Isi |
|---|---|
| Tepat satu aktif | Untuk tiap role, buka tiap halaman menu tanpa parameter → hitung `nav-item-link ... active` di HTML, harus **tepat 1** |
| Grup aktif ikut halaman | Buka `vcb.ng-total.daftar` → `navGroups('quality')` |
| Plafon baris | Untuk tiap role, jumlah baris yang selalu tampak (tautan + header grup) ≤ 8 |
| Saringan role | `staff_qc` tidak melihat href Master Data maupun Breakdown; `guest` tidak melihat AI Assistant |
| Grup kosong hilang | `staff_produksi` tidak melihat header `MTC Dies` sama sekali |

Test "tepat satu aktif" itu yang menutup kelas bug prefiks untuk selamanya, bukan hanya empat
entri NG yang kebetulan pernah ketahuan.

---

## Risiko

| Risiko | Penanganan |
|---|---|
| Pola route salah ketik → menu tidak pernah menyala | Test "tepat satu aktif" gagal (0 ≠ 1) |
| Wildcard terlalu longgar → dua menu menyala | Test yang sama gagal (2 ≠ 1) |
| Item hilang saat pindah ke config | Test saringan role memeriksa href per role |
| Pengguna kehilangan grup terakhir yang dibuka | Sekali, saat deploy, karena kunci `localStorage` ganti. Diterima |

Nol migrasi, nol perubahan route, nol perubahan middleware. Yang berpindah hanya cara sidebar
merender dirinya.
