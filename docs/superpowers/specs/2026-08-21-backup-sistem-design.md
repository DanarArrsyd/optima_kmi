# Desain: Halaman Backup Sistem

**Tanggal:** 2026-08-21
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

OPTIMA memegang seluruh catatan produksi pabrik — 22.869 baris dandori, 6.550 baris LHP
produksi, dan seluruh master data — tanpa satu pun jalur backup yang dikendalikan dari dalam
aplikasi. Satu `DELETE` yang salah, satu import yang menimpa, atau satu masalah di hosting,
dan tidak ada titik pulih selain apa pun yang kebetulan disimpan penyedia hosting.

Spec ini menambahkan halaman Backup Sistem: tombol **Backup Now** untuk kebutuhan mendadak
(sebelum import besar, sebelum migrasi berisiko) plus **backup harian terjadwal**, karena
backup yang hanya jalan kalau ada yang ingat adalah backup yang berhenti setelah minggu
pertama.

## Hasil Probe Prod (2026-08-21, read-only)

Diukur lebih dulu lewat `debug-log.yml` mode `backupcheck`, karena bentuk implementasinya
bercabang di fakta yang tidak bisa diketahui dari mesin lokal:

| Fakta | Nilai | Konsekuensi |
|---|---|---|
| `mysqldump` | **ADA** di `/usr/bin/mysqldump` | Tidak perlu menulis dumper PHP sendiri |
| `disabled_functions` | **kosong** | `proc_open` hidup, Symfony Process jalan |
| `memory_limit` / `max_execution_time` | 512M / **0** | Sinkron saat klik aman, tanpa antrian |
| Ukuran DB | **20,47 MB**, 31 tabel | Dump ±15–25 MB, terkompresi ±2–4 MB |
| `storage/app/private` | **12K** | Berkas unggahan praktis masih kosong |

⚠️ `df` melaporkan `2.1T avail`, tapi itu disk fisik host bersama — **bukan kuota akun**.
Kuota sebenarnya hanya terlihat di panel Hostinger. Retensi di bawah dipilih supaya angka itu
tidak pernah jadi masalah.

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| Pemicu | Tombol **Backup Now** + terjadwal harian lewat GitHub Actions |
| Cakupan | Dump database **dan** berkas unggahan |
| Retensi | Simpan **30 terbaru**, sisanya dipangkas otomatis |
| Penempatan menu | **Dropdown di topbar**, bukan sidebar |
| Restore | **Tidak dibuat** — lihat di bawah |

### Kenapa bukan di sidebar

Sidebar role `admin` sudah mentok **dua-duanya**: 9 baris tingkat atas dari plafon 9, dan
9 + 7 = 16 dari plafon 16, dijaga `SidebarMenuTest`. Menambah entri di mana pun untuk admin
membuat penjaga itu merah. Menaikkan plafon bukan pilihan — sisa napas tinggi sidebar 28px
sementara satu baris anak butuh 36px.

Blok user di topbar karena itu menjadi dropdown kecil: **Profil · Backup Sistem · Logout**.
Nol biaya budget sidebar, nol navigasi yang diacak, dan tempatnya masuk akal — Backup adalah
halaman admin yang jarang dibuka, bukan alat kerja harian.

### Kenapa TIDAK ada tombol Restore

Satu salah klik menimpa seluruh produksi dan tidak bisa ditarik. Pemulihan dilakukan dengan
mengunduh berkasnya lalu me-restore lewat phpMyAdmin. Gesekan di situ adalah fitur
keamanan, bukan kekurangan.

## Prinsip

**Halaman ini yang paling berbahaya di OPTIMA.** Satu berkas backup memuat seluruh basis
data, termasuk tabel `users` (hash kata sandi) dan seluruh data karyawan. Karena itu:
`admin` saja — bukan `manager` — berkas di disk **private**, dan unduhan lewat route
ber-role, mengikuti pola checksheet PDF preventive yang sudah ada.

**Gagal harus berisik.** Backup yang gagal diam-diam lebih buruk daripada tidak punya
backup sama sekali, karena orang mengira punya. Kegagalan `mysqldump` **tidak boleh**
meninggalkan berkas apa pun — lebih baik nol berkas daripada arsip 0 byte yang terlihat sah
di daftar sampai hari dibutuhkan.

**Umur backup terakhir adalah angka utama halaman ini**, bukan daftarnya. Kartu paling atas
memuat umur backup terbaru; lewat 48 jam, merah.

## 1. Penyimpanan & Bentuk Berkas

Direktori: `storage/app/private/backups/` (disk `local`). `storage/` dikecualikan dari rsync
deploy, jadi arsipnya selamat melewati tiap deploy.

Satu backup = satu berkas ZIP:

```
optima-2026-08-21_2300.zip
├── database.sql        dump mysqldump penuh
├── manifest.json       metadata (lihat di bawah)
└── berkas/             salinan storage/app/private, TANPA backups/
```

Nama berkas memakai **jam pabrik (WIB)**, bukan UTC. Aplikasi berjalan UTC; nama berkas
dibaca manusia, jadi ia ikut jam manusia.

⚠️ **`berkas/` WAJIB mengecualikan `backups/` itu sendiri.** Tanpa itu, tiap backup memuat
seluruh backup sebelumnya dan ukurannya menggelembung berlipat — arsip ke-10 memuat
sembilan pendahulunya. Ini kesalahan yang tidak terlihat sampai disknya penuh.

`manifest.json` memuat: waktu WIB dan UTC, sumber (`manual` / `terjadwal`), pengguna pemicu
(null untuk terjadwal), jumlah tabel, jumlah baris per tabel, ukuran dump mentah, versi
aplikasi (`git` sha kalau tersedia). Dipakai halaman untuk menampilkan isi tanpa membuka
ZIP-nya, dan menjadi bukti bahwa arsipnya waras.

## 2. Layanan

`app/Services/BackupService.php`:

| Method | Tugas |
|---|---|
| `jalankan(string $sumber, ?int $userId): BackupHasil` | Dump → manifest → zip → pangkas |
| `daftar(): array` | Baca direktori + manifest, urut terbaru dulu |
| `hapus(string $namaBerkas): void` | Hapus satu arsip |
| `pangkas(int $simpan = 30): int` | Buang yang paling lama, kembalikan jumlah terbuang |
| `umurTerakhir(): ?CarbonInterval` | Untuk kartu di atas halaman |

Proses `mysqldump` dibungkus di balik satu antarmuka kecil (`Contracts\PembuatDump`) supaya
test bisa memasang implementasi palsu. Tanpa itu seluruh layanan ini mustahil diuji: CI
tidak punya MySQL, dan `mysqldump` tidak akan pernah jalan di sana.

### ⚠️ Kata sandi tidak boleh lewat argumen perintah

`mysqldump -p<sandi>` membuat kata sandi database **terlihat di daftar proses seluruh
mesin** — dan ini shared hosting, mesinnya dipakai bersama penyewa lain. Kata sandi ditulis
ke berkas sementara mode `0600`, diberikan lewat `--defaults-extra-file`, lalu berkas itu
dihapus di blok `finally`.

Kredensial juga tidak boleh masuk log. Kalau perintahnya gagal, yang dicatat pesan galat dan
kode keluarnya, bukan barisnya.

### Kunci berjalan

Dua klik beruntun tidak boleh menjalankan dua dump sekaligus. Dipakai `Cache::lock('backup',
600)`; pemanggil kedua mendapat pesan "Backup sedang berjalan", bukan antrean diam-diam.

## 3. Halaman

Route, seluruhnya `role:admin`:

| Metode | Path | Nama |
|---|---|---|
| GET | `/sistem/backup` | `sistem.backup.index` |
| POST | `/sistem/backup/jalankan` | `sistem.backup.jalankan` |
| GET | `/sistem/backup/{berkas}/unduh` | `sistem.backup.unduh` |
| DELETE | `/sistem/backup/{berkas}` | `sistem.backup.hapus` |

`{berkas}` **wajib divalidasi ketat** terhadap pola `optima-YYYY-MM-DD_HHmm.zip` sebelum
menyentuh filesystem. Nama berkas dari URL yang dipakai apa adanya adalah lubang path
traversal, dan direktori di atasnya memuat `.env` server.

Tata letak mengikuti pola `.erp-ws` seperti 27 halaman lain: toolbar (tombol Backup Now),
dock filter **tidak dipakai** di halaman ini (tidak ada yang perlu disaring), grid, status
bar. Di atas grid: satu kartu **Backup Terakhir** berisi umurnya.

Kolom grid: Waktu (WIB) · Sumber · Ukuran · Jumlah Tabel · Dipicu Oleh · Aksi
(Unduh / Hapus).

Hapus memakai konfirmasi. Unduh memakai `response()->download()` dari disk private —
berkasnya tidak pernah punya URL publik.

### Dropdown topbar

Blok user di `layouts/app.blade.php` (`.topbar-item.user-block`) yang sekarang statis
menjadi dropdown Alpine: **Profil** (`profile.edit`) · **Backup Sistem** (`admin` saja) ·
**Logout**. Tombol Logout yang berdiri sendiri sekarang pindah ke dalamnya.

Dua hal yang tidak boleh ikut berubah: penanda "MODE TAMU — READ ONLY" tetap tampil di
topbar untuk `guest`, dan Logout tetap POST ber-CSRF, bukan tautan GET.

⚠️ Entri Backup Sistem dirender hanya untuk `admin`. Itu kenyamanan, bukan pengaman —
pengamannya `role:admin` di route.

## 4. Penjadwalan

Perintah artisan `backup:jalankan` (sumber `terjadwal`), dipanggil workflow
`.github/workflows/backup.yml` lewat SSH, sejalur dengan `deploy.yml` dan `debug-log.yml`
yang sudah ada:

```yaml
on:
  schedule:
    - cron: '0 16 * * *'   # 16:00 UTC = 23:00 WIB, sesudah shift 2
  workflow_dispatch:        # bisa dipicu manual untuk menguji
```

⚠️ **Jadwal GitHub Actions tidak tepat waktu dan tidak dijamin.** Ia bisa telat puluhan
menit saat antrean padat, dan **dinonaktifkan otomatis setelah 60 hari repositori tidak ada
aktivitas**. Untuk backup harian ketelatan tidak masalah; penonaktifan setelah repo sepi
masalah nyata, dan itulah gunanya kartu umur backup di halaman — ia yang memberi tahu
jadwalnya berhenti, bukan orang yang kebetulan curiga.

Workflow harus **merah kalau perintahnya gagal**, bukan hijau dengan pesan galat di
keluarannya.

## 5. Test

| Test | Membuktikan |
|---|---|
| `BackupServiceTest::menyusun_arsip_lengkap` | ZIP memuat `database.sql`, `manifest.json`, `berkas/` |
| `…::berkas_mengecualikan_direktori_backup` | Arsip tidak memuat arsip lain — penjaga pertumbuhan berlipat |
| `…::dump_gagal_tidak_meninggalkan_berkas` | Nol arsip 0 byte saat `mysqldump` gagal |
| `…::pangkas_menyisakan_tiga_puluh_terbaru` | Retensi membuang yang paling lama, bukan yang salah |
| `…::sandi_tidak_pernah_masuk_argumen` | Perintah yang disusun tidak memuat kata sandi |
| `…::manifest_memuat_jumlah_tabel_dan_sumber` | Isi manifest |
| `BackupHalamanTest::hanya_admin` | `manager`, `staff_*`, `guest` mendapat 403 di keempat route |
| `…::nama_berkas_di_luar_pola_ditolak` | Path traversal (`../../.env`) ditolak sebelum menyentuh disk |
| `…::kartu_umur_merah_setelah_48_jam` | Ambang kartu |
| `…::unduh_mengirim_berkas_dari_disk_private` | Bukan dari `public/` |
| `BackupPerintahTest::menandai_sumber_terjadwal` | Perintah artisan menandai sumbernya benar |

Dumper palsu dipasang di seluruh test — `mysqldump` tidak pernah dijalankan di CI. Yang
diuji: perakitan arsip, retensi, manifest, penyusunan argumen perintah, dan otorisasi.
**Yang TIDAK terbukti dari suite: bahwa `mysqldump` sungguhan menghasilkan dump yang bisa
di-restore.** Itu hanya bisa dibuktikan dengan mengunduh satu arsip dan me-restore-nya ke
database uji — sekali, manual, sesudah deploy.

## 6. Di Luar Lingkup

- **Restore dari dalam aplikasi.** Alasannya di atas.
- **Backup ke luar server** (S3, Google Drive). Arsip yang hidup di server yang sama tidak
  menolong kalau servernya yang hilang. Layak jadi tahap berikutnya, butuh kredensial dan
  keputusan tersendiri.
- **Tabel database untuk riwayat backup.** Daftarnya dibaca dari filesystem + manifest, jadi
  tidak ada migrasi di PR ini — dan karenanya tidak ada risiko lupa mendaftarkannya di
  `deploy.yml`.
- **Backup sebagian** (per tabel, per rentang tanggal). Belum ada yang meminta.
