# Desain: Tanggal Nonaktif Karyawan & Penyaringan Operator VCB

**Tanggal:** 2026-08-21
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

Master Karyawan sudah punya tombol Non-Aktifkan sejak migrasi `2026_06_10_000001`, tetapi
yang tercatat hanya keadaannya (`is_aktif`), bukan **kapan** orang itu berhenti aktif.
Akibatnya tidak ada dasar untuk menjawab pertanyaan "pada periode ini, siapa saja yang
masih bekerja".

Dampaknya kelihatan di VCB Produksi. `VcbProduksiController::index()` memuat karyawan
**tanpa** `->aktif()` — satu-satunya dari 15 tempat pemuat karyawan di repo ini yang
melewatkannya. Dropdown Operator karena itu memuat semua orang yang pernah bekerja, dan
daftarnya bertambah panjang selamanya seiring karyawan keluar. VCB Quality punya cacat
yang sama persis pada dropdown Inspector.

Spec ini menambahkan tanggal nonaktif dan memakainya untuk menyaring dropdown VCB
mengikuti rentang tanggal yang sedang dilihat.

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| Batas tampil operator nonaktif | Ikut rentang tanggal VCB — muncul selama `date_from <= tanggal_nonaktif` |
| Data karyawan nonaktif yang sudah ada | Diisi dari tanggal transaksi terakhirnya (LHP produksi / QC / dandori) |
| Cara admin mengisi tanggal | Diminta saat menonaktifkan, sudah terisi hari ini, boleh dimundurkan |
| VCB Quality | Ikut dibetulkan di PR yang sama |
| Tabel ringkasan VCB (mode 1) | **Tidak** disentuh |
| Riwayat keluar-masuk berulang | Di luar lingkup — hanya tanggal terakhir yang disimpan |

## Prinsip

**Kolom ini hanya berarti saat `is_aktif = false`.** Setiap jalur yang mengaktifkan
kembali seseorang wajib mengosongkannya. Kalau tidak, tanggal basi menempel pada orang
yang sudah aktif dan menyaringnya keluar dari dropdown tanpa ada yang sadar.

**NULL berarti selalu tampil.** Fail-open disengaja: kolom kosong tidak boleh
menghilangkan orang dari dropdown. Yang menghilangkan hanya tanggal yang benar-benar ada.
Karyawan nonaktif yang nol transaksi (salah input, lalu dinonaktifkan) tetap tampil, dan
itu jauh lebih murah daripada operator yang hilang diam-diam dari analitik.

**Penyaringan hanya di pemilih, bukan di data.** Riwayat orang yang sudah keluar tetap
utuh di tabel ringkasan dan di seluruh perhitungan. Yang disaring hanya daftar nama yang
bisa dipilih.

## 1. Database

### Migration kolom

`database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php`

```php
$table->date('tanggal_nonaktif')->nullable()->after('is_aktif')
      ->comment('Tanggal karyawan dinonaktifkan; NULL = masih aktif atau tidak diketahui');
```

`down()` melakukan `dropColumn`.

### Migration backfill

`database/migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php` — file
terpisah, mengikuti pola `2026_07_28_000004_migrate_existing_ng_to_detail_tables.php`.

Untuk tiap karyawan dengan `is_aktif = false` dan `tanggal_nonaktif` masih NULL, isi
dengan yang **terbaru** di antara tiga tanggal:

| Tabel | Kolom tanggal | Kolom karyawan |
|---|---|---|
| `transaksi_produksi` | `tanggal_produksi` | `id_karyawan` |
| `transaksi_qc` | `tgl_pengecekan` | `id_karyawan_qc` |
| `transaksi_dandori_rincian` | `tanggal` | `id_karyawan` |

Tiga `max()` terpisah lalu dibandingkan **di PHP**, bukan `GREATEST()` di SQL. Jumlah
karyawan nonaktif kecil (puluhan), jadi tidak ada alasan menukar keterujian dengan
kecepatan yang tidak terasa: `GREATEST` tidak ada di SQLite dan akan membuat migrasi ini
mustahil diuji di suite. Lihat catatan `feedback-raw-sql-dialect-gap`.

Karyawan nonaktif tanpa satu pun transaksi dibiarkan NULL. `down()` mengembalikan seluruh
kolom ke NULL.

**Kedua migration wajib didaftarkan di `.github/workflows/deploy.yml` pada PR yang sama**,
di step "Migrate (targeted)". Lupa = deploy hijau, mencetak `Nothing to migrate`, kode live
tanpa kolomnya. Sudah tiga korban di repo ini. Dijaga `scripts/check-migration-coverage.sh`.

## 2. Model

`app/Models/MasterKaryawan.php`:

- `$fillable` tambah `'tanggal_nonaktif'`
- `$casts` tambah `'tanggal_nonaktif' => 'date'`

**Tidak ada scope baru.** Penyaringan mengikuti rentang tanggal terjadi di klien (§5), jadi
scope Eloquent untuk itu akan menjadi kode mati sejak hari pertama.

Scope `aktif()` yang sudah ada tidak diubah — 14 pemakainya (form input, PIC maintenance)
memang harus menyembunyikan orang nonaktif sepenuhnya, tanpa ikut rentang tanggal.

## 3. Master Karyawan

### Menonaktifkan

Tombol Non-Aktifkan sekarang membuka **laci kecil satu kolom**: satu input `type="date"`
sudah terisi hari ini, tombol Simpan, tombol Batal. Menggantikan `confirm()` yang ada
sekarang di `resources/views/master/karyawan/index.blade.php:258`.

Route `master.karyawan.toggle-aktif` (PATCH) dipertahankan; body-nya kini boleh memuat
`tanggal_nonaktif`.

`KaryawanController::toggleAktif()`:

- Menonaktifkan → simpan `is_aktif = false` + `tanggal_nonaktif` dari input
- Mengaktifkan kembali → `is_aktif = true` + `tanggal_nonaktif = null`, tetap satu klik
- Validasi: `nullable|date|before_or_equal:today`. Tanggal masa depan ditolak — orang tidak
  bisa berhenti besok menurut catatan hari ini, dan tanggal maju akan membuat dropdown VCB
  memunculkannya di periode yang salah
- Kalau `tanggal_nonaktif` tidak dikirim (mis. permintaan lama), jatuh ke hari ini
- Pola `try/QueryException` yang sudah ada di method itu dipertahankan

### Tabel

Kolom baru **Nonaktif Sejak** setelah kolom Status, lebar 120px, format `d/m/Y`, isi `—`
untuk yang aktif. Dibaca lewat guard `Schema::hasColumn('master_karyawan',
'tanggal_nonaktif')` supaya halaman tetap hidup kalau kode mendarat lebih dulu dari kolom.

### Form Edit

`tanggal_nonaktif` **tidak** ditambahkan sebagai field bebas di form Edit. Satu jalur tulis
saja (tombol nonaktifkan) membuat aturan "kosong saat aktif" mudah dijaga; dua jalur
membuatnya gampang menyimpang. Salah tanggal dibetulkan dengan mengaktifkan lalu
menonaktifkan ulang.

## 4. Import

`app/Imports/KaryawanImport.php` sudah memaksa `is_aktif = true` pada tiap baris, sehingga
re-import master **mengaktifkan kembali semua orang**. Tambahkan satu baris di sebelahnya:

```php
$record->is_aktif = true;
$record->tanggal_nonaktif = null;   // aktif = tak boleh menyimpan tanggal nonaktif
```

Tanpa ini, orang yang di-import ulang menjadi aktif sambil tetap membawa tanggal nonaktif
lama — tepat keadaan yang dilarang Prinsip di atas.

Template import tidak menambah kolom: tanggal nonaktif bukan data yang dikelola lewat Excel.

## 5. VCB Produksi & Quality

### Controller

`VcbProduksiController::index()` dan `VcbQualityController::index()` tetap memuat seluruh
karyawan departemennya (dropdown disaring di klien, lihat di bawah), tetapi kini menyertakan
`tanggal_nonaktif` supaya view bisa menempelkannya ke tiap `<option>`.

Tidak ada endpoint baru. Rentang tanggal dipilih di browser, jadi mengambil ulang daftar ke
server berarti satu perjalanan bolak-balik tiap kali tanggal berubah tanpa menambah apa pun.

### View

Tiap `<option>` mendapat `data-nonaktif="YYYY-MM-DD"` (kosong untuk yang aktif). Daftar
lengkapnya disimpan sekali sebagai array JS, lalu `<select>` **dibangun ulang** setiap
`date_from` berubah:

- `data-nonaktif` kosong → selalu ikut
- selain itu → ikut hanya kalau `tanggal_nonaktif >= date_from`

Membangun ulang, bukan menyembunyikan `<option>` dengan `display:none` — perilaku
`display:none` pada `<option>` tidak seragam antar peramban.

Penyaringan dijalankan juga **saat halaman pertama dimuat**, bukan hanya saat tanggal
diubah. Kalau tidak, tampilan pertama memuat semua orang dan baru bersih setelah pengguna
menyentuh tanggal — persis kebalikan dari yang diminta.

Kalau operator yang **sedang terpilih** jatuh keluar rentang setelah tanggal diubah,
pilihannya dikosongkan dan sebaris pesan muncul: *"Operator X tidak aktif pada periode ini."*
Diam-diam mengosongkan pilihan akan membuat grafik menampilkan orang lain tanpa penjelasan.

Tabel ringkasan (mode 1), grafik, dan seluruh perhitungan `DashboardService` **tidak
disentuh**.

## 6. Test

| Test | Membuktikan |
|---|---|
| `MasterKaryawanNonaktifTest::menonaktifkan_menyimpan_tanggal` | PATCH toggle menyimpan tanggal yang dikirim |
| `…::mengaktifkan_kembali_mengosongkan_tanggal` | Aktif kembali → kolom NULL |
| `…::tanggal_masa_depan_ditolak` | Validasi `before_or_equal:today` |
| `…::tanpa_tanggal_jatuh_ke_hari_ini` | Permintaan tanpa field tetap aman |
| `…::import_mengosongkan_tanggal_nonaktif` | Celah `KaryawanImport` tertutup |
| `…::kolom_nonaktif_sejak_tampil_di_tabel` | Kolom baru terlihat, yang aktif `—` |
| `VcbOperatorAktifTest::dropdown_memuat_data_nonaktif` | Tiap `<option>` membawa `data-nonaktif` |
| `…::operator_nonaktif_tidak_ikut_saat_rentang_lewat` | Aturan penyaringan, dijangkarkan ke `<select>`-nya |
| `…::operator_aktif_selalu_ikut` | Sisi sebaliknya |
| `…::berlaku_juga_di_vcb_quality` | Bug kembar tertutup |
| `BackfillTanggalNonaktifTest` | Backfill mengambil transaksi terbaru dari tiga tabel; nol transaksi tetap NULL |

Asersi dropdown dijangkarkan ke isi `<select id="one_karyawan_id">`, bukan ke seluruh HTML —
nama operator juga muncul di tempat lain di halaman, sehingga asersi atas seluruh HTML tidak
akan pernah bisa gagal. Lihat `feedback-asersi-yang-tak-bisa-gagal`.

Tiap penjaga harus terbukti **merah lebih dulu** sebelum perbaikannya ditulis.

## 7. Daftar Periksa Deploy

- [ ] Dua migration terdaftar di `.github/workflows/deploy.yml` step "Migrate (targeted)"
- [ ] `./scripts/check-migration-coverage.sh` hijau
- [ ] Guard `Schema::hasColumn` di pembaca kolom baru
- [ ] `php artisan test` hijau
- [ ] `vendor/bin/pint app tests` bersih
- [ ] Sapuan direktif Blade: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/` kosong
- [ ] Halaman Master Karyawan dan kedua halaman VCB tetap memenuhi susunan `.erp-ws`
      (`ErpWorkspaceMarkupTest`)

## 8. Di Luar Lingkup

- **Riwayat nonaktif berulang.** Karyawan keluar-masuk-keluar hanya menyimpan tanggal
  terakhir. Butuh tabel riwayat tersendiri, dan belum ada yang memintanya.
- **14 pemuat karyawan lain** (form input LHP, PIC maintenance, control stroke) tetap memakai
  `aktif()`. Di sana orang nonaktif memang harus hilang total.
- **Dropdown VCB Dandori dan VCB Mesin.** Dandori memilih mesin, bukan orang; Mesin tidak
  memuat karyawan sama sekali.
- **Mencabut `is_aktif`.** Kolom keadaan tetap sumber kebenaran; tanggal hanya melengkapinya.
