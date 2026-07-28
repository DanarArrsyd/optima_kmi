# Dashboard NG — VCB NG Proses & VCB NG Total — Design

**Tanggal:** 2026-07-28
**Status:** Disetujui, siap masuk rencana implementasi
**Tahap:** 2 dari 2. Tahap 1 (fondasi data NG multi-jenis) sudah live — PR #44, merged 2026-07-28.

---

## Latar

Permintaan aslinya dua halaman dashboard NG. Eksplorasi menemukan lapisan datanya belum sanggup menopangnya, sehingga pekerjaan dipecah. Tahap 1 membangun tabel detail NG, service penulis tunggal, form multi-jenis, import/export, dan migrasi data lama. Spec-nya: `docs/superpowers/specs/2026-07-27-ng-detail-multi-jenis-design.md`.

Spec ini menutup permintaan aslinya.

## Tujuan

Dua halaman baru di section **Dashboard** sidebar:

| Menu | Grup sidebar | Sumber data |
|---|---|---|
| **VCB NG Proses** | Produksi | `transaksi_produksi` + `transaksi_produksi_ng` |
| **VCB NG Total** | Quality | `transaksi_qc` + `transaksi_qc_ng` |

Keduanya menjawab dua pertanyaan berurutan: **NG Rate tiap bulan naik atau turun**, lalu **bulan itu NG-nya jenis apa saja dan berapa banyak**.

## Bukan tujuan (sengaja)

- Export Excel — tidak diminta, dan datanya belum ada isinya. Mudah ditambahkan kemudian.
- Pareto kumulatif, filter shift, breakdown per mesin/part/operator, drill sampai baris transaksi.
- Migrasi baru — nol. Semua kolom sudah live sejak Tahap 1.
- Residual "Tanpa jenis" dan label peringatan data historis — lihat "Yang dibatalkan" di bawah.

---

## Bentuk halaman

Dua lapis, mengikuti pola drill-down Mode 1 → Mode 2 yang sudah dipakai VCB Produksi dan VCB Quality, supaya operator tidak perlu mempelajari pola baru.

### Lapis 1 — NG Rate per bulan

- Filter rentang tanggal, default **12 bulan terakhir** agar trennya terlihat.
- Kartu ringkasan: total qty NG, total diperiksa, NG Rate keseluruhan pada rentang itu.
- Grafik batang: satu batang satu bulan, sumbu Y persen NG Rate.

### Lapis 2 — rincian bulan terpilih

Muncul setelah satu batang bulan diklik. Berisi tabel jenis NG bulan itu, diurut qty terbanyak:

| Kolom | Isi |
|---|---|
| Kode | `kode` dari `master_jenis_ng` |
| Jenis NG | `nama_ng` |
| Qty | total pcs NG jenis itu pada bulan itu |
| Porsi | qty jenis itu ÷ total qty NG bulan itu, dalam persen |

Judul lapis 2 menyebut bulannya eksplisit — "Rincian NG — Maret 2026" — agar tidak ada yang salah membaca rincian bulan lain.

### Rumus NG Rate

```
NG Rate = qty_ng ÷ (qty_baik + qty_ng) × 100

  Produksi : qty_baik = qty_aktual
  Quality  : qty_baik = qty_ok
```

Sisi Quality memakai rumus yang sudah dipakai `DashboardService::getVcbQuality()` hari ini. Sisi Produksi memakai bentuk yang sama sehingga kedua angka bisa dibandingkan langsung.

**Bulan mana yang muncul di grafik.** Sebuah bulan muncul bila punya transaksi pada rentang itu, apa pun jumlah NG-nya. Bulan dengan transaksi tapi nol NG tampil sebagai batang **NG Rate 0%** — itu informasi yang berarti (bulan bersih). Bulan tanpa transaksi sama sekali **tidak muncul** — batang 0% di situ akan terbaca sebagai "bulan sempurna" padahal artinya "tidak ada yang dikerjakan". Perbedaan ini penting dan salah satu test menguncinya.

Keputusan yang mendasarinya: `qty_aktual` berisi **barang baik saja**, tidak termasuk NG. Kalau asumsi ini ternyata salah di lapangan, penyebutnya jadi dobel-hitung dan seluruh NG Rate Produksi akan lebih kecil dari yang seharusnya — tanpa error apa pun.

### Keadaan kosong

Bukan hiasan. Data produksi saat ini hanya 15 baris NG, dan sisi QC **nol**.

- Rentang tanpa data NG: tampilkan *"Belum ada data NG pada rentang ini"* — bukan grafik kosong yang membuat orang mengira sistemnya rusak.
- VCB NG Total secara khusus menambahkan keterangan bahwa NG QC memang belum pernah tercatat, agar halaman kosong tidak dilaporkan sebagai bug.

---

## Arsitektur

Mengikuti preseden **VCB Dandori** — analog terdekat yang sudah ada, karena `getVcbDandori()` sudah mengembalikan bentuk agregasi per periode.

### Controller — tipis, nol logika hitung

- `app/Http/Controllers/Vcb/VcbNgProsesController.php`
- `app/Http/Controllers/Vcb/VcbNgTotalController.php`

Masing-masing: `index()` merender view, `data()` mengembalikan JSON lapis 1, `detail()` mengembalikan JSON lapis 2 untuk satu bulan.

### Service — satu sumber logika

Dibuat di `app/Services/NgDashboardService.php`:

```php
getVcbNg(string $sumber, string $dateFrom, string $dateTo): array
// => ['summary' => ['total_ng' => int, 'total_check' => int, 'ng_rate' => float],
//     'by_month' => [['bulan' => 'Mar 2026', 'bulan_raw' => '2026-03',
//                     'total_ng' => int, 'total_check' => int, 'ng_rate' => float], ...]]

getVcbNgDetail(string $sumber, string $bulan): array
// $bulan format 'YYYY-MM'
// => [['kode' => string, 'nama_ng' => string, 'qty' => int, 'porsi' => float], ...]
```

`$sumber` bernilai `'produksi'` atau `'qc'`. Satu method dengan parameter sumber, bukan dua method kembar — perbedaannya hanya nama tabel, kolom tanggal, dan kolom qty baik.

**Ditaruh di service baru, bukan di `DashboardService`.** `DashboardService` sudah 397 baris dan menangani empat dashboard; dua method ini menambah sekitar 100–120 baris lagi, membawanya ke ~510. Karena itu keduanya ditaruh di **`app/Services/NgDashboardService.php`** sejak awal, bukan ditambahkan dulu lalu dipecah kemudian. `DashboardService` tidak disentuh sama sekali.

### View — satu Blade, dipakai dua kali

`resources/views/vcb/ng.blade.php`, menerima parameter judul, sumber, dan endpoint. Dua halaman ini kembar; dua file identik adalah utang, bukan kejelasan.

### Route — masuk grup role yang sudah ada

- NG Proses → grup `role:admin,staff_produksi,manager,guest` yang sudah ada
- NG Total → grup `role:admin,staff_qc,manager,guest` yang sudah ada

Nol grup baru, nol permukaan akses baru. Semuanya read-only sehingga `DenyGuestWrite` tidak terpengaruh.

### Sidebar

Satu entri di grup Produksi (setelah VCB Dandori), satu di grup Quality (setelah VCB Quality), di `resources/views/layouts/app.blade.php`. Kondisi `@if(in_array($role, ...))` mengikuti grup masing-masing.

### Guard skema

Semua pembacaan tabel detail dibungkus `NgDetailService::available()`. Kode dan skema tidak dijamin tiba bersamaan di produksi — rsync mendahului `artisan migrate`. Bila tabel belum ada, halaman menampilkan keadaan kosong, bukan 500.

---

## Yang dibatalkan dari rencana Tahap 1

Spec Tahap 1 merencanakan dua hal untuk Tahap 2 yang kini **tidak dikerjakan**:

1. **Residual "Tanpa jenis"** — dihitung saat query dari `qty_ng − SUM(detail)`.
2. **Label peringatan data historis** — bahwa data sebelum rilis hanya menyimpan jenis dominan.

Alasannya: migrasi data ke produksi melaporkan **0 baris di-skip** di kedua tabel. Tidak ada satu pun baris dengan `qty_ng > 0` tanpa jenis NG. Keduanya menjawab masalah yang di data nyata tidak ada.

Diskriminator generasi data (`qty_ng > 0 AND nol baris detail`) tetap sahih dan tetap dipakai secara implisit: baris seperti itu tidak ada, jadi `SUM(detail)` selalu sama dengan `qty_ng`.

---

## Testing

| Kasus | Yang dikunci |
|---|---|
| NG Rate per bulan benar untuk sisi Produksi | rumus `NG ÷ (qty_aktual + NG)` |
| NG Rate per bulan benar untuk sisi QC | rumus `NG ÷ (qty_ok + NG)`, konsisten dengan `getVcbQuality()` |
| Bulan dengan transaksi tapi nol NG tampil sebagai NG Rate 0% | bulan bersih adalah informasi |
| Bulan tanpa transaksi sama sekali TIDAK muncul | 0% palsu terbaca sebagai bulan sempurna |
| Rentang tanpa data NG mengembalikan struktur kosong, bukan error | keadaan kosong |
| Rincian satu bulan hanya berisi NG bulan itu | drill-down tidak bocor lintas bulan |
| Porsi tiap jenis berjumlah 100% (atau 0 bila tak ada NG) | pembagi benar |
| Sesi ter-soft-delete tidak ikut terhitung | agregasi lewat relasi induk |
| Guest bisa membuka kedua halaman, tidak bisa menulis apa pun | grup role yang sudah ada |
| Tabel detail belum ada → halaman tetap tampil, keadaan kosong | guard `available()` |

Test suite berjalan di SQLite in-memory. Data uji dibuat langsung lewat model; `created_by` adalah FK non-nullable pada kedua tabel transaksi dan wajib diisi.

---

## Deploy

**Nol migrasi baru**, jadi tidak ada penambahan di `.github/workflows/deploy.yml`. `./scripts/check-migration-coverage.sh` tetap dijalankan sebelum PR untuk memastikan tidak ada migrasi yang tanpa sengaja ikut terbawa.

---

## Urutan implementasi

1. `getVcbNg()` + test unit — kedua sumber, kedua rumus
2. `getVcbNgDetail()` + test unit
3. Route + dua controller + test feature otorisasi
4. Blade bersama + Chart.js, keadaan kosong
5. Entri sidebar
6. Verifikasi menyeluruh + PR
