# Desain: Input Dandori Satu Alur dengan LHP Produksi

**Tanggal:** 2026-08-22
**Status:** Disetujui, siap diimplementasikan

## Latar Belakang

Dandori dan LHP Produksi diinput di dua halaman terpisah, padahal keduanya memakai **lima
field sesi yang sama persis**: `tanggal`, `id_shift`, `id_karyawan`, `id_mesin`,
`id_part_proses`. Operator mengetik data yang sama dua kali, di dua tempat.

Secara struktur keduanya juga sudah sebangun. `transaksi_dandori_rincian` adalah tabel
datar — satu baris = satu jenis loss time, dan field sesinya diulang di tiap baris. Itu pola
yang identik dengan NG multi-jenis di LHP Produksi: satu sesi, banyak baris rincian.

### Diukur dulu, bukan diasumsikan

Penggabungan ini sempat hampir dibatalkan. Probe pertama (`dandoricek`) menemukan **60% sesi
dandori tanpa pasangan LHP** — angka yang membuat penggabungan tampak merusak. Empat probe
berikutnya menunjukkan angka itu menyesatkan:

| Probe | Temuan |
|---|---|
| `dandorilonggar` | Melonggarkan shift hanya menyelamatkan 92 sesi; melonggarkan part nol. Yatimnya bukan salah cocok |
| `dandoripart` | Satu part (FRAME DR CTR) menyumbang 3.304 dari 4.970 sesi yatim — 66% |
| `dandoripartno` | Part itu cuma punya SATU baris master: 12.283 baris dandori berbanding 51 baris LHP |
| `dandoridup` | **Nol duplikat.** Sebaran per bulan: Jan–Mei 2026 praktis 100% dandori tercatat pada satu part; Juni ke atas beragam |

Batas Juni itu batas antara **data migrasi Excel** dan **data input aplikasi**. Diukur ulang
khusus era aplikasi:

| | Semua data | **Juni 2026 ke atas** |
|---|---|---|
| Sesi dandori | 8.347 | 4.086 |
| Sesi tanpa pasangan LHP | 4.970 (60%) | **709 (17%)** |
| Dicocokkan per part | 44% | **15%** |
| Part penyumbang yatim terbesar | 3.304 sesi | 27 sesi |

**83% sesi dandori pada cara kerja sekarang punya pasangan LHP**, dan sisanya menyebar tipis
(27, 15, 14, 11, 8) tanpa satu pun yang mendominasi. Penggabungan masuk akal.

## Keputusan yang Sudah Diambil

| Pertanyaan | Keputusan |
|---|---|
| Bentuk penggabungan | Centang **"Ada Dandori"** di form LHP Produksi, pola identik NG multi-jenis |
| Tabel `transaksi_dandori_rincian` | **Tidak disentuh sama sekali** |
| Rumus OEE, Achievement, Availability, Loss Time, Overview | **Tidak disentuh sama sekali** |
| Halaman Dandori | Jadi daftar; tombol input tinggal sebagai jalur sekunder untuk dandori tanpa LHP |
| Blok dandori di form **Edit** LHP | **Tidak ada** — lihat §4 |
| Perbaikan atribusi part Jan–Mei | **Di luar lingkup** — dilaporkan, bukan dikerjakan |

## Prinsip

**Gabungkan INPUT-nya, bukan TABELnya.** Baris dandori tetap ditulis ke
`transaksi_dandori_rincian` dengan bentuk yang sama seperti sekarang. Yang berpindah hanya
tempat mengetiknya. Konsekuensinya: **nol migrasi, dan nol kemungkinan angka di Overview
bergeser** — tidak ada satu pun perhitungan yang bacaannya berubah.

**Baris dandori tidak menyimpan tautan ke baris LHP.** Ini bukan kelalaian, ini yang
membuat rancangan ini sederhana: 1.182 dari 5.493 kombinasi tanggal+shift+mesin+proses punya
**lebih dari satu** baris LHP (sampai 5). Kalau dandori harus menunjuk salah satunya, kita
butuh aturan sewenang-wenang. Karena ia hanya menyalin field sesi, pertanyaan "nempel ke
baris yang mana" tidak pernah muncul.

**Satu definisi untuk `std_menit` dan `over_menit`.** Dua jalur input yang menghitungnya
sendiri-sendiri pasti menyimpang; repo ini sudah membuktikannya berkali-kali dengan komponen
yang disalin.

## 1. Yang Ditulis ke Database

Tidak berubah sedikit pun dari sekarang. Untuk tiap baris rincian:

```php
TransaksiDandoriRincian::create([
    'tanggal'        => (dari form LHP),
    'id_shift'       => (dari form LHP),
    'id_karyawan'    => (dari form LHP, bisa diubah — lihat §3),
    'id_mesin'       => (dari form LHP),
    'id_part_proses' => (dari form LHP),
    'id_losstime'    => (input),
    'actual_menit'   => (input),
    'std_menit'      => snapshot master; PROGRESSIVE memakai std_menit_progressive,
    'over_menit'     => max(0, actual_menit - std_menit),
    'created_by'     => auth()->id(),
]);
```

Logika `std_menit`/`over_menit` diangkat dari `DandoriController::store()` ke satu layanan
baru `app/Services/DandoriRincianService.php`, lalu **dipakai kedua jalur** — form LHP dan
form Dandori. `DandoriController` beralih memanggilnya; perilakunya tidak berubah, dan
test dandori yang ada harus tetap hijau tanpa disentuh.

## 2. Validasi Form

Mengikuti bentuk NG multi-jenis yang sudah ada di `StoreProduksiRequest`:

```php
'ada_dandori'                  => 'nullable|boolean',
'dandori_count'                => 'required|integer|min:0',
'dandori'                      => 'array|required_if:ada_dandori,1',
'dandori.*.id_losstime'        => 'required|integer|exists:master_losstime,id|distinct',
'dandori.*.actual_menit'       => 'required|numeric|min:0.01',
```

`prepareForValidation()` mengosongkan `dandori` kalau centangnya lepas.

⚠️ **Penjaga `dandori_count` wajib ada**, meniru `ng_count`. Alasannya tercatat di
`StoreProduksiRequest`: hitungan yang dikirim membuktikan Alpine hidup, bukan bahwa
baris-barisnya benar-benar sampai. Payload dengan `dandori_count=2` tapi `dandori[]` hilang
akan lolos aturan di atas dan menyimpan **nol baris dandori tanpa satu pun error** —
operator mengira sudah tercatat. `distinct` pada `id_losstime` mencegah satu jenis loss time
tercatat dua kali dalam satu sesi.

## 3. Form yang Disentuh

⚠️ **Tiap modul Input punya TIGA form yang menulis**, bukan dua:

| Berkas | Peran |
|---|---|
| `resources/views/input/produksi/create.blade.php` | Form penuh |
| `resources/views/input/produksi/index.blade.php` | **Laci input harian — yang paling sering dipakai operator** |
| `resources/views/input/produksi/edit.blade.php` | **TIDAK diberi blok dandori** (§4) |

Melewatkan laci di `index.blade.php` berarti jalur yang paling sering dipakai diam-diam
tidak bisa menyimpan dandori, sementara `create` yang jarang dibuka terlihat baik-baik saja.
Itu sudah pernah terjadi persis begitu pada NG multi-jenis.

Blok dandori memakai partial tersendiri (mengikuti `input/partials/ng-rows`), supaya kedua
form memanggil satu definisi, bukan menyalin markup.

Isi tiap baris: **Jenis Loss Time** (pilihan dari `master_losstime`, tampil `kode` +
`jenis_losstime`) dan **Actual Menit**. Operator dandori mengikuti operator LHP secara
otomatis, dan **bisa diubah** — probe menemukan 250 sesi dengan operator berbeda, jadi
mewarisi tanpa bisa diubah akan memaksa data yang salah.

## 4. Edit dan Hapus LHP Tidak Menyentuh Dandori

Blok dandori **hanya ada di form pembuatan** (`create` dan laci `index`), tidak di `edit`.

Alasannya langsung dari rancangan: baris dandori tidak menyimpan tautan ke baris LHP, jadi
saat membuka Edit tidak ada cara yang jujur untuk tahu baris dandori mana yang "milik" baris
LHP itu — apalagi ketika satu sesi punya sampai 5 baris LHP. Menebaknya berarti menampilkan
baris milik sesi lain, dan menyimpannya berarti menimpa data yang bukan miliknya.

Menghapus baris LHP juga **tidak** menghapus baris dandori. Keduanya memang catatan yang
berdiri sendiri.

Mengubah atau menghapus dandori tetap dilakukan di halaman Dandori, yang untuk itu
mempertahankan Edit dan Hapus seperti sekarang.

## 5. Halaman Dandori

Berubah peran: dari tempat input utama menjadi **daftar**. Kolom, filter, Edit, Hapus, dan
Import tetap.

Tombol inputnya **tidak dicabut**, tapi turun jadi jalur sekunder dan diberi label yang
jujur: **"Dandori tanpa LHP"**. 709 sesi (17%) pada era aplikasi memang tidak punya baris
LHP — ganti dies yang produksinya batal, atau yang produksinya jalan di shift berikutnya.
Mencabut tombolnya berarti kejadian itu tidak bisa dicatat sama sekali, dan hilangnya tidak
akan terlihat sampai ada yang mencarinya.

Sebagian dari 17% itu mungkin justru akibat alur dua form yang sekarang: operator mencatat
dandori, LHP-nya tidak sempat diisi. Alur baru bisa menguranginya sendiri — tapi itu dugaan,
bukan alasan untuk menghapus jalannya sekarang.

## 6. Test

| Test | Membuktikan |
|---|---|
| `DandoriDariLhpTest::menyimpan_rincian_dandori_bersama_lhp` | Satu submit menghasilkan baris LHP **dan** baris dandori dengan field sesi yang sama |
| `…::tanpa_centang_tidak_menulis_dandori` | Centang lepas → nol baris dandori |
| `…::std_dan_over_menit_sama_dengan_jalur_dandori` | Nilai `std_menit`/`over_menit` identik dengan yang dihasilkan form Dandori untuk masukan yang sama — penjaga terhadap dua definisi yang menyimpang |
| `…::proses_progressive_memakai_std_progressive` | Cabang PROGRESSIVE ikut terpakai lewat jalur baru |
| `…::hitungan_tidak_cocok_ditolak` | `dandori_count` ≠ jumlah baris terkirim → ditolak, bukan menyimpan nol baris diam-diam |
| `…::jenis_loss_time_kembar_ditolak` | `distinct` bekerja |
| `…::operator_dandori_bisa_beda_dari_lhp` | Operator bisa diubah, tidak dipaksa mewarisi |
| `…::laci_index_punya_blok_dandori` | Laci di `index.blade.php` ikut punya bloknya — jalur yang paling sering dipakai |
| `…::form_edit_tidak_punya_blok_dandori` | Edit sengaja tanpa blok dandori |
| `…::mengubah_lhp_tidak_menyentuh_dandori` | Update LHP tidak menambah, mengubah, atau menghapus baris dandori |
| `…::menghapus_lhp_tidak_menghapus_dandori` | Sama untuk hapus |

Asersi markup dijangkarkan ke elemen yang diuji, bukan ke seluruh HTML — nama field muncul
di lebih dari satu tempat pada halaman yang sama.

Seluruh test Dandori dan Produksi yang sudah ada **harus tetap hijau tanpa disentuh**. Kalau
ada yang merah, itu tanda perilaku lama ikut berubah — dan itu di luar yang diminta.

## 7. Yang TIDAK Berubah

- **Nol migrasi.** Tidak ada kolom, tabel, atau indeks baru
- **Nol perubahan pada perhitungan.** `MaintenanceDashboardService`, `OeeService`,
  `OverviewService`, `KalenderKerjaService`, `DashboardService` tidak disentuh. Angka
  Availability, OEE, Achievement, Performance, Loss Time, dan seluruh isi Overview harus
  identik sebelum dan sesudah
- Halaman VCB Dandori, import Excel Dandori, dan Edit/Hapus dandori tidak berubah
- Bentuk baris di `transaksi_dandori_rincian` tidak berubah

## 8. Di Luar Lingkup — dan Satu Temuan yang Dilaporkan

**Atribusi part dandori Januari–Mei 2026 salah.** 11.976 baris — seluruh dandori pada
periode itu — tercatat pada satu part (FRAME DR CTR), sisa migrasi Excel. Nol duplikat, jadi
**menitnya utuh**; yang salah part-nya.

| | Dampak |
|---|---|
| Availability, OEE, jam produktif | **Kemungkinan besar tidak terpengaruh** — dihitung dari menit per mesin per tanggal, bukan per part |
| Loss Time & VCB Dandori yang dipecah **per part** | **Salah untuk Jan–Mei** — lima bulan kerugian dandori menumpuk di satu part |

Ini **tidak dikerjakan di spec ini**. Membetulkannya butuh keputusan tersendiri dan
kepastian bahwa Excel sumbernya masih ada untuk pemetaan ulang. Dicatat di sini supaya tidak
hilang.

Juga di luar lingkup: menautkan baris dandori ke baris LHP, mengubah halaman VCB mana pun,
dan menyentuh satu pun rumus.
