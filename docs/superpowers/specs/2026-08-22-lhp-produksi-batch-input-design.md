# Desain: Halaman Batch Input LHP Produksi

**Tanggal:** 2026-08-22
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

Input LHP Produksi sekarang lewat laci selebar 700px yang menutupi tabel. Formnya sempit,
terpotong, dan harus digulir di dalam laci. Tiap baris berarti satu submit dan satu muat
ulang halaman penuh — padahal operator stamping mengetik banyak baris beruntun pada mesin
dan tanggal yang sama.

Selain sempit, laci itu **form penulis ketiga** untuk modul yang sama (`create`, `edit`,
laci `index`). Ia yang paling sering dipakai operator dan paling sering kelewat saat field
baru ditambahkan — sudah dua kali terjadi (NG multi-jenis di sisi produksi dan QC).

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| Bentuk | Halaman sungguhan, bukan tukar tampilan di tempat |
| Route | Rombak `input.produksi.create`; laci di `index` dicabut |
| Lingkup | LHP Produksi saja; QC, Dandori, Breakdown menyusul terpisah |
| Penyimpanan draft | Browser (`localStorage`), nol tabel dan nol migrasi |
| NG & Dandori | Blok isian inline di form, ringkas di baris draft |
| Baris draft salah | Hapus lalu ketik ulang; tidak ada mode ubah |
| SUBMIT gagal | Semua batal, draft utuh, baris salah ditandai |
| Field lengket | Tanggal, Shift, Operator, Mesin |
| Jam tumpang tindih | Ditolak saat SAVE TO DRAFT |
| Endpoint | `POST input/produksi/batch`; route `store` dipensiunkan |
| Setelah SUBMIT | Balik ke daftar dengan pesan "N baris berhasil disimpan" |

## Prinsip

**Yang berubah tempat mengetiknya, bukan yang tersimpan.** Baris yang masuk
`transaksi_produksi`, `transaksi_produksi_ng_detail`, dan `transaksi_dandori_rincian`
berbentuk sama persis seperti sekarang. Konsekuensinya: **nol migrasi** dan **nol
kemungkinan angka bergeser** — Availability, OEE, Achievement, Performance, Loss Time, dan
seluruh isi Overview membaca hal yang sama.

**Tiga form jadi dua.** Halaman batch dan form Edit. Jebakan "laci kelewat saat menambah
field" hilang karena lacinya tidak ada lagi.

## 1. Bentuk Halaman

`/input/produksi/create` dirombak. Sidebar dan topbar tetap. Isinya dua bagian bertumpuk:

| Bagian | Isi |
|---|---|
| Atas | Form satu baris + tombol **SAVE TO DRAFT** |
| Bawah | Tabel **DRAFT TERSIMPAN** + **SUBMIT DRAFT** dan **CANCEL** |

Tombol **INPUT BARU** di `index.blade.php` berubah dari pembuka laci jadi tautan ke halaman
ini. Seluruh markup laci input dicabut dari `index.blade.php`.

⚠️ Mencabut pembungkus laci adalah cara klasik meninggalkan `</div>` nyasar yang rusaknya
senyap — sudah 6× tertangkap penjaga di repo ini, 1× lolos ke prod. Keseimbangan `<div>`
halaman `index` wajib diperiksa setelah pencabutan, bukan hanya dilihat "tampak normal".

## 2. Form

Field: Tanggal · Shift · Operator · Mesin · Part · Proses · Jam Start–Finish · Qty OK,
ditambah centang **Ada NG Proses?** dan **Ada Dandori?**.

**Shift tetap sebuah field, tidak ditebak dari jam start.** `id_shift` wajib di server dan
menjadi penyebut Availability; shift pabrik nyambung, jadi menebaknya akan salah pada baris
yang jamnya melewati batas shift.

Part memakai typeahead yang sudah ada. Part No terisi otomatis dan readonly; yang dikirim ke
server tetap `id_part_proses`, bukan `part_no`.

⚠️ Typeahead memakai `taField()` — satu definisi di `layouts/app.blade.php`, dijaga
`KomponenBersamaTest`. Jangan membuat varian baru untuk layout lebar.

⚠️ `<option>` proses wajib tetap membawa `data-progressive`. Tanpa itu, pratinjau Std/Over
di blok dandori diam-diam selalu memakai `std_menit_default`, dan salahnya hanya terlihat
pada part PROGRESSIVE.

### Field lengket

Setelah SAVE TO DRAFT, yang **tidak** ter-reset: **Tanggal, Shift, Operator, Mesin**.
Keempatnya konstan sepanjang satu sesi kerja.

Yang ter-reset: Part, Proses, Jam Start, Jam Finish, Qty OK, dan kedua centang beserta
seluruh baris NG/dandori di dalamnya.

## 3. Blok NG & Dandori

Memakai partial yang sudah live apa adanya: `input/partials/ng-rows.blade.php` dan
`input/partials/dandori-rows.blade.php`, lengkap dengan typeahead loss time dan pratinjau
Std/Over. Keduanya dibungkus `.input-panel` seperti sekarang.

Saat SAVE TO DRAFT, isi kedua blok ikut menempel pada baris draft.

⚠️ `ng_count` dan `dandori_count` bukan hiasan. Keduanya membuktikan Alpine hidup. Payload
yang kehilangan `ng[]`/`dandori[]` sementara hitungannya tetap terkirim akan lolos aturan
validasi lalu menyimpan **nol** baris rincian tanpa satu pun error — operator mengira sudah
tercatat. Di mode batch penjaga ini dipindahkan per baris draft.

## 4. Tabel Draft

Kolom: Tanggal · Shift · Operator · Mesin · Nama Mesin · Part No · Part Name · ID Proses ·
Proses · Jam · Qty OK · Qty NG · Jenis NG · **Dandori** · SPH Std · SPH Act · W. Kerja ·
CT Act · % · **Aksi**.

Dua kolom ditambahkan dari rancangan awal: **Dandori** (satu baris bisa punya NG dan dandori
sekaligus) dan **Aksi**.

Ringkasan isi: NG tampil `GORES ×2`, dandori tampil `GANTI DIES 50mnt`. Detail penuh tidak
muat di satu baris tabel dan memang tidak perlu.

**Aksi hanya Hapus.** Tidak ada mode ubah: salah = buang barisnya, ketik ulang. Murah karena
empat field lengket tidak perlu diketik lagi, dan menghindari state "sedang mengubah baris
ke-N" beserta pertanyaan turunannya.

⚠️ **SPH Std, SPH Act, W. Kerja, CT Act, dan % adalah pratinjau hitungan browser, bukan
sumber kebenaran.** Server yang menghitung saat submit. Kalau keduanya berbeda, yang salah
pratinjaunya. Aturan ini sama dengan Std/Over pada blok dandori.

## 5. Penyimpanan Draft

State Alpine, dicerminkan ke `localStorage` setiap kali berubah.

| Kejadian | Perilaku |
|---|---|
| Refresh / buka halaman lagi | Draft dipulihkan, dengan pemberitahuan bahwa ia dipulihkan |
| CANCEL saat draft berisi | Konfirmasi dulu; kalau diiyakan, draft dibuang dan kembali ke daftar |
| CANCEL saat draft kosong | Langsung kembali ke daftar |
| SUBMIT berhasil | Draft dibersihkan |
| SUBMIT gagal | Draft tetap utuh |

Kunci `localStorage` dipisah per pengguna agar draft tidak bocor antar akun pada komputer
bersama di lantai produksi.

## 6. Validasi

### Saat SAVE TO DRAFT (browser)

- Semua field wajib terisi
- Jam finish setelah jam start
- Qty OK ≥ 1
- **Jam tidak tumpang tindih** dengan baris draft lain pada tanggal + mesin yang sama.
  Ditolak, dengan pesan menunjuk baris lawannya.

Baris yang ditolak tidak masuk tabel draft.

### Saat SUBMIT DRAFT (server)

Aturan `StoreProduksiRequest` yang ada dipakai **per baris**, ditambah pemeriksaan tumpang
tindih antar baris. Browser bisa dilewati; server tidak.

Pemeriksaan tumpang tindih ini **perilaku baru** — sampai sekarang repo tidak memeriksanya
sama sekali. Ia hanya berlaku antar baris dalam satu batch, tidak terhadap data yang sudah
tersimpan sebelumnya. Memperluasnya ke seluruh tabel adalah keputusan terpisah: baris lama
yang tumpang tindih sudah ada di prod dan menolaknya bisa mengunci operator dari mengoreksi
data lamanya.

## 7. Endpoint

`POST /input/produksi/batch` → `input.produksi.batch`.

Payload: `rows[]`, tiap baris berisi field yang sama persis dengan payload `store` sekarang
(termasuk `ng[]`, `ng_count`, `dandori[]`, `dandori_count`, `dandori_karyawan_id`).

Satu `DB::transaction` untuk seluruh baris. Per baris memanggil layanan yang sudah ada:

| Layanan | Peran |
|---|---|
| `ProduksiService::calculateActualCycleTime()` | Cycle time aktual |
| `NgDetailService::syncProduksi()` | Baris NG |
| `DandoriRincianService::simpan()` | Baris dandori |

**Nol rumus baru.** Batch hanya mengulang jalur yang sudah live.

Gagal satu baris = seluruh transaksi dibatalkan. Respons membawa indeks baris yang gagal
beserta pesannya, supaya tabel draft di browser bisa menandai baris itu tanpa kehilangan
sembilan lainnya.

Route `input.produksi.store` **dicabut** karena tidak ada lagi yang memanggilnya. 24
pemanggilan di tiga berkas test (`ProduksiStoreTest`, `NgMultiJenisProduksiTest`,
`DandoriDariLhpTest`) dipindah ke bentuk payload batch.

Berhasil → redirect ke `/input/produksi` dengan pesan "N baris berhasil disimpan".

## 8. Test

| Test | Nasib |
|---|---|
| 24 pemanggilan `input.produksi.store` di 3 berkas | Dipindah ke payload batch |
| `test_laci_index_punya_blok_dandori` | **Dipindah** ke halaman batch, bukan dihapus |
| `test_blok_ng_dan_dandori_memakai_kemasan_yang_sama` | **Dipindah** ke halaman batch |
| `test_form_edit_tidak_punya_blok_dandori` | Tidak disentuh |
| Seluruh test Edit, import, export, VCB | Tidak disentuh, harus tetap hijau |

Test baru:

- Satu submit banyak baris menghasilkan sejumlah baris LHP yang sama, dengan NG dan dandori
  masing-masing menempel pada barisnya sendiri
- Satu baris gagal → **nol** baris tersimpan (transaksi benar-benar dibatalkan, bukan hanya
  urutan validasi)
- Respons gagal menyebut indeks baris yang salah
- Jam tumpang tindih antar baris dalam satu batch ditolak
- Halaman batch memuat blok NG dan blok dandori, keduanya dibungkus `.input-panel`
- `index.blade.php` **tidak lagi** memuat form penulis (`name="qty_aktual"` hilang dari sana)

⚠️ Penjaga yang dihapus diam-diam sama saja dengan tidak pernah ada. Test yang menjangkar ke
laci wajib pindah, bukan lenyap.

## 9. Yang TIDAK Berubah

- **Nol migrasi.** Tidak ada kolom, tabel, atau indeks baru
- **Nol perubahan pada perhitungan.** `MaintenanceDashboardService`,
  `MaintenanceDiesDashboardService`, `OeeService`, `OverviewService`, `KalenderKerjaService`,
  `DashboardService` tidak disentuh
- Bentuk baris di ketiga tabel transaksi tidak berubah
- Form Edit LHP Produksi tidak disentuh — termasuk ketiadaan blok dandori di sana
- Import Excel, export, halaman VCB mana pun, dan modul QC/Dandori/Breakdown tidak disentuh

## 10. Di Luar Lingkup

- Modul QC, Dandori, Breakdown Mesin, dan Breakdown Dies tetap memakai laci
- Pemeriksaan tumpang tindih terhadap data yang sudah tersimpan di database
- Mode ubah untuk baris draft
- Draft yang selamat berpindah komputer (butuh tabel di database — ditolak karena ongkos
  migrasinya tidak sebanding)
