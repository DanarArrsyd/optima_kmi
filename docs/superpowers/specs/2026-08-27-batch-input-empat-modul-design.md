# Desain: Halaman Batch Input untuk Empat Modul Sisa

Tanggal: 2026-08-27

## Latar Belakang

PR #114 memindahkan Input LHP Produksi dari laci (drawer) ke halaman batch
sendiri: form satu baris di atas, tabel draft di bawah, satu SUBMIT mengirim
semua baris sekaligus. Empat modul lain masih memakai laci:

| Modul | Laci penulis |
|---|---|
| Input Quality | `resources/views/input/qc/index.blade.php:71` |
| Input Dandori | `resources/views/input/dandori/index.blade.php:79` |
| Breakdown Mesin | `resources/views/maintenance/breakdown/index.blade.php:47` |
| Breakdown Dies | `resources/views/maintenance/breakdown-dies/index.blade.php:59` |

Keempatnya menyusul ke bentuk yang sama.

Dua temuan saat menyurvei, keduanya mengubah lingkup:

1. **Checksheet PDF bukan milik Breakdown Dies.** Berkas itu hidup di Control
   Stroke Dies → preventive (`control-stroke-dies.preventive.create`), yang
   sudah punya halaman sendiri. `StoreBreakdownDiesRequest` nol field berkas.
   Jadi tidak ada halangan upload-vs-draft, dan keempat modul bisa batch penuh.
2. **Breakdown Mesin punya halaman penulis yatim.** Route
   `maintenance.breakdown.create` + `resources/views/maintenance/breakdown/create.blade.php`
   hidup, tapi nol view menautkannya — hanya `AuthorizationTest` yang
   menyentuhnya. Modul itu punya TIGA penulis hari ini: laci, halaman yatim,
   form edit.

## Keputusan yang Sudah Diambil

- Keempat modul dapat halaman batch penuh (tabel draft + `localStorage`),
  bukan sekadar pindah laci ke halaman.
- Halaman Dandori tetap dapat batch penuh walau sudah jadi jalur sekunder
  (input utamanya centang "Ada Dandori" di form LHP Produksi, PR #111).
  Alasannya keseragaman: operator tidak perlu hafal dua model form.
- Kerangka batch **diekstrak jadi komponen bersama** lebih dulu, bukan disalin
  per modul.
- Halaman yatim `maintenance.breakdown.create` dicabut, digantikan halaman
  batch di posisi route yang sama.

## Prinsip

**Yang tersalin adalah yang paling mahal kalau salah.** Logika draft
`localStorage`, pemulihan `KUNCI_BACKUP`, pembersihan draft yang hanya boleh
jalan setelah server membuktikan tersimpan, dan penjaga bfcache — empat hal itu
nol hubungannya dengan QC atau breakdown. Tiap salinan adalah tempat baru untuk
menyimpang diam-diam, dan mode gagalnya senyap: draft hilang tidak melempar
error, hanya menyisakan tabel kosong yang operator kira memang belum diisi.

Repo ini sudah pernah membayar harga itu. `taField` pernah tersebar di 18
berkas dengan 5 varian sebelum disatukan; sekarang `taField`,
`filterSearchComp`, `partGroupComp`, `erpToggleDock`, dan
`saringOrangNonaktif` masing-masing punya satu definisi di layout dan dijaga
`KomponenBersamaTest`.

## 1. Pembagian: Kerangka vs Isian

`lhpBatch()` di `resources/views/input/produksi/create.blade.php` mencampur dua
hal. Yang **generik**, tidak ada hubungannya dengan LHP:

- `KUNCI` / `KUNCI_BACKUP`, dipisah per pengguna (komputer lantai produksi
  dipakai bergantian; kunci bersama membuat draft operator A muncul di layar
  operator B)
- `mulai()`: pulihkan backup → pulihkan draft → `$watch` menulis balik →
  baca `barisGagal` dari `$errors`
- `pindahkanKeBackup()` beserta `try/finally` yang memastikan `KUNCI`
  dikosongkan walau `setItem` gagal karena kuota penuh
- Pembaca form: `form()`, `nilai()`, `teksTerpilih()`, `blok()`
- `hapus(i)`, `batal()`, state `pesan` / `galat` / `barisGagal`
- Penjaga bfcache di `window.addEventListener('pageshow')`

Yang **milik modul**: field apa yang dikumpulkan, mana yang wajib, pemeriksaan
antar-baris, pratinjau hitungan, bentuk payload yang dikirim, field mana yang
direset setelah baris masuk draft, dan kolom tabel draft.

Kerangka menjadi satu factory di `resources/views/layouts/app.blade.php`:

```js
batchDraft({
  kunci,        // string dasar kunci localStorage (ditambah id pengguna)
  indexUrl,     // tujuan tombol CANCEL
  kumpulkan,    // () => calon: baca form, hasilkan objek baris
  wajib,        // { namaField: 'Label' } untuk pesan "X wajib diisi"
  periksa,      // (calon, rows) => string|null: pemeriksaan antar-baris
  pratinjau,    // (calon) => void: isi kolom hitungan (opsional)
  payload,      // (r) => objek yang dikirim ke server
  resetField,   // [nama field] yang dikosongkan setelah baris masuk draft
  resetBlok,    // [selector blok Alpine] yang dikosongkan
})
```

`partPicker()` juga naik ke layout. Hari ini ada dua salinan yang mengerjakan
hal sama: `partPicker()` di `input/produksi/create.blade.php` dan
`qcPartSelector()` di `input/qc/index.blade.php`. Dua halaman batch akan
sama-sama membutuhkannya, jadi disatukan sekarang, bukan disalin jadi tiga.

`KomponenBersamaTest` mendapat penjaga baru: `batchDraft` dan `partPicker`
masing-masing hanya boleh punya satu definisi di seluruh `resources/views`.

## 2. Per Modul

### 2.1 Quality

Field: `tgl_pengecekan`, `tgl_produksi_part`, `id_shift`, `id_karyawan_qc`,
part + `id_part_proses`, `jam_start`, `jam_finish`, `qty_ok`, blok NG.

Pemeriksaan antar-baris: **tidak ada**. QC tidak punya konsep tabrakan jam
seperti mesin — dua inspector boleh memeriksa part yang sama di jam yang sama.

Field lengket (tidak direset setelah baris masuk draft): tanggal pengecekan,
tanggal produksi part, shift, inspector. Keempatnya konstan sepanjang satu sesi
kerja.

### 2.2 Dandori

Field: `tanggal`, `id_shift`, `id_karyawan`, `id_mesin`, part +
`id_part_proses`, dan rincian `{ id_losstime, actual_menit }`.

Modul ini sudah punya lapis penulisnya: `DandoriRincianService::simpan($sesi, $rincian)`
dipakai `DandoriController::store()` hari ini. **Tidak ada service baru yang
dibuat** — endpoint batch memanggil yang sama, satu kali per baris draft.

Catatan semantik: satu "baris" di Dandori adalah satu SESI yang berisi banyak
rincian, dan satu sesi sudah menulis N baris tabel. Jadi batch di sini berarti
banyak sesi per submit.

Std/Over di tabel draft adalah pratinjau, sama seperti sekarang. Sumber
kebenarannya `DandoriRincianService`; jembatan PROGRESSIVE lewat
`data-progressive` di `<option>` tetap dipakai.

Field lengket: tanggal, shift, operator, mesin.

### 2.3 Breakdown Mesin & Breakdown Dies

Keduanya kembar: 12 field teks/tanggal, nol berkas. Bedanya cuma sasaran
(`id_mesin` vs `id_part_proses`), nama kolom problem (`problem_mesin` vs
`problem_dies`), dan awalan nomor (LKM vs LKD).

Satu hal yang tidak dipunyai modul lain: **`nomor_urut`**. Angka itu diubah
jadi No LKM oleh `BreakdownMesinService::generateNoLkm()` dengan bentuk
`5/LKM/MTC/KMI/VIII/26` (nomor / awalan / bulan romawi / dua digit tahun).
Hari ini tabrakannya ditolak per baris di controller
(`BreakdownMesinController.php:139`).

Di mode batch, tabrakan bisa datang dari **dua arah**:

1. Lawan baris yang sudah ada di database.
2. **Antar baris di dalam satu draft** — arah yang tidak ada padanannya di
   jalur lama, dan yang paling mungkin lolos kalau pola cuma disalin.

Keduanya diperiksa di `Store<X>BatchRequest` sebelum satu baris pun ditulis,
mengikuti pola `cariBentrok()` di `StoreProduksiBatchRequest`.

Di browser, `nomor_urut` menaik sendiri tiap baris masuk draft (nilai awal dari
`nextNomorUrut()`), jadi operator tidak mengetik ulang. Angka di layar tetap
bisa disunting — dia usulan, bukan kunci; server yang memutuskan.

Field lengket: tanggal breakdown, PIC maintenance.

Halaman yatim `maintenance/breakdown/create.blade.php` **dihapus**, dan route
`maintenance.breakdown.create` menunjuk halaman batch yang baru. Modul itu
turun dari tiga penulis jadi dua (batch + edit), sama seperti produksi.

## 3. Sisi Server

Tiap modul mendapat tiga potong, bentuknya sama dengan produksi:

| Potong | Isi |
|---|---|
| `Store<X>BatchRequest` | `rows_json` → `rows.*`, aturan per baris, pemeriksaan antar-baris |
| `<X>WriteService::simpan(array $baris)` | menulis satu baris + rinciannya |
| `<X>Controller::batch()` | `DB::transaction` + loop + flash `batch_tersimpan` |

`<X>WriteService` **sengaja tidak membuka transaksinya sendiri** — pemanggil
yang menentukan batas, supaya satu batch berisi banyak baris bisa dibatalkan
utuh. Docblock `ProduksiWriteService` sudah mencatat alasan ini; service baru
mengikutinya.

Logika tulis yang hari ini menempel di controller dipindah ke service:
`QcController::store()` merakit `qty_ng`, menghitung `actual_cycle_time`, lalu
membuka `DB::transaction` sendiri (`QcController.php:180`). Dandori tidak perlu
service baru (lihat 2.2).

`batch_tersimpan` bukan hiasan: itu satu-satunya bukti dari server yang membuat
halaman daftar berani membuang `KUNCI_BACKUP`. Tanpanya draft dibuang
berdasarkan "tidak ada galat validasi", yang juga benar saat request tidak
pernah sampai server sama sekali (419 sesi habis, 500, jaringan putus).

Payload dikirim sebagai satu field `rows_json`, bukan input per baris.
`max_input_vars` bawaan PHP (1000) memotong `$_POST` diam-diam kalau terlampaui
— hanya menulis warning ke log, sementara batch tampak ditolak tanpa alasan
yang bisa ditindaklanjuti operator, atau diterima tapi terpotong pas di batas
baris.

### Route

| Pensiun | Pengganti |
|---|---|
| `input.qc.store` | `input.qc.batch` + `input.qc.create` |
| `input.dandori.store` | `input.dandori.batch` + `input.dandori.create` |
| `maintenance.breakdown.store` | `maintenance.breakdown.batch` (route `create` tetap, isinya halaman batch) |
| `maintenance.breakdown-dies.store` | `maintenance.breakdown-dies.batch` + `maintenance.breakdown-dies.create` |

`update`, `destroy`, `import`, `export`, dan seluruh route preventive tidak
disentuh.

## 4. Test

Tiap modul mendapat dua berkas, cerminan `HalamanBatchProduksiTest` (bentuk
halaman) dan `ProduksiBatchTest` (perilaku endpoint):

- satu submit menulis semua baris
- satu baris gagal membatalkan semuanya
- gagal di tengah transaksi tidak menyisakan baris
- rincian menempel pada barisnya masing-masing
- `rows_json` rusak ditolak, bukan meledak
- `rows` kosong ditolak
- guest tidak bisa menyimpan
- halaman daftar tidak lagi punya form penulis
- tombol "Input Baru" menautkan ke halaman batch
- `<div>` seimbang di halaman batch
- kunci draft dipisah per pengguna

Khusus breakdown, dua yang tidak ada padanannya di produksi:

- No LKM/LKD bentrok dengan data yang sudah ada ditolak
- **No LKM/LKD kembar antar baris dalam satu draft ditolak**

Untuk PR 1 (ekstraksi), test yang sudah ada — `HalamanBatchProduksiTest` dan
`ProduksiBatchTest` — adalah gerbangnya. Keduanya harus hijau tanpa diubah.
Kalau salah satunya perlu disunting agar lolos, itu tanda ekstraksinya mengubah
perilaku, dan perubahan itu harus dijelaskan atau dibatalkan.

## 5. Urutan Kerja

Empat PR berurutan, bukan satu:

1. **Ekstrak kerangka** — `batchDraft()` + `partPicker()` naik ke layout,
   produksi dipindah ke config. Nol perubahan perilaku. Plus penjaga
   satu-definisi di `KomponenBersamaTest`.
2. **Quality** — paling mirip produksi, sekaligus membuktikan kerangkanya
   benar-benar bisa dipakai ulang.
3. **Dandori**
4. **Breakdown Mesin + Breakdown Dies** — sekalian, karena keduanya kembar;
   termasuk mencabut halaman yatim.

Alasan dipecah: kalau kerangkanya salah bentuk, ketahuan di PR 2 saat masih
satu modul menumpang di atasnya, bukan setelah empat halaman ditulis.

Risiko terbesar ada di PR 1 — ia menyentuh halaman yang dipakai operator tiap
hari. Karena itu PR 1 nol perubahan perilaku dan bersandar penuh pada test yang
sudah ada.

## 6. Yang TIDAK Berubah

- **Nol migrasi.** `deploy.yml` tidak berubah.
- Sidebar tidak berubah. Entri yang ada memakai `match => ['input.qc.*']` dan
  sejenisnya, jadi halaman baru otomatis menyalakan menu yang benar. Invarian
  plafon (`tingkat_atas ≤ 9`, `tingkat_atas + anak_terbesar ≤ 16`) aman karena
  nol entri baru.
- Form edit tiap modul tidak disentuh.
- Laci filter (`erp-dock`) dan laci note tidak disentuh — yang dicabut hanya
  laci penulis. Laci di `input/produksi/index.blade.php:573` adalah laci NOTE,
  bukan penulis.
- Import/export tiap modul tidak disentuh.
- Batas 50 baris per batch tetap.

## 7. Di Luar Lingkup

- Menggabungkan form edit ke halaman batch.
- Mengubah bentuk penyimpanan tabel mana pun.
- Menyentuh Control Stroke (mesin maupun dies) beserta alur checksheet PDF-nya.
- Menyeragamkan gaya halaman edit lama yang belum memakai `.erp-ws`.
