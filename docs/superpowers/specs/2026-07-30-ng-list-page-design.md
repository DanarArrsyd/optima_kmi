# Daftar NG — Halaman Daftar Baris NG per Jenis — Design

**Tanggal:** 2026-07-30
**Status:** Disetujui, siap masuk rencana implementasi

---

## Latar

Dashboard NG (Tahap 2, PR #45–#50) berhenti di **agregat**: grafik NG Rate per bulan, lalu rincian per jenis NG untuk bulan terpilih. Yang tidak ada: cara melihat **baris demi baris** — tanggal berapa, mesin apa, part apa, siapa yang mencatat.

Pengguna mencari daftar itu dan tidak menemukannya, karena memang belum pernah dibuat. Spec ini membuatnya.

## Tujuan

Dua halaman daftar, satu baris = **satu jenis NG dalam satu sesi**. Kalau satu LHP mencatat tiga jenis NG, muncul tiga baris. Granularitas itu yang membuat filter per jenis NG bekerja tepat.

| Menu | Grup sidebar | Route |
|---|---|---|
| **Daftar NG Proses** | Produksi, setelah VCB NG Proses | `/vcb/ng-proses/daftar` |
| **Daftar NG Total** | Quality, setelah VCB NG Total | `/vcb/ng-total/daftar` |

**Tetap dua halaman, bukan satu dengan pemilih sumber.** Alasannya model akses, bukan estetika: grup role kedua sisi berbeda — `staff_produksi` tidak boleh melihat data QC dan sebaliknya. Satu halaman gabungan hanya bisa masuk satu grup, dan itu merusak pemisahan yang sudah ada.

## Bukan tujuan (sengaja)

- Filter mesin dan filter shift — tidak diminta.
- Grafik apa pun di halaman ini. Ini daftar; grafiknya sudah ada di dashboard.
- Tautan balik dari dashboard ke daftar.
- Migrasi baru — nol. Semua kolom sudah live sejak Tahap 1.

---

## Bentuk halaman

### Kolom

```
Produksi : tanggal · shift · mesin · customer · part no · part name · proses · operator  · kode NG · nama NG · qty
Quality  : tanggal · shift ·   —   · customer · part no · part name · proses · inspector · kode NG · nama NG · qty
```

Kolom **mesin hanya ada di sisi Produksi**. Bukan pilihan desain: `transaksi_qc` tidak punya `id_mesin`. Itu batas data, dan satu-satunya perbedaan struktural antara kedua halaman.

Label kolom orang mengikuti sisinya: **operator** di Produksi (`karyawan`), **inspector** di Quality (`karyawanQc`).

### Filter

| Filter | Bentuk | Berlaku di |
|---|---|---|
| Rentang tanggal | dua input date | keduanya |
| Jenis NG | dropdown dari `master_jenis_ng` | keduanya |
| Part / Customer | satu input teks, mencari `part_no`, `part_name`, atau `customer` | keduanya |
| Operator / Inspector | dropdown karyawan sesuai departemen | keduanya |

Default rentang tanggal: **12 bulan terakhir**, sama dengan dashboard NG, dihitung `first day of -11 months` sampai hari ini. Pemakaian `first day of` disengaja — `-11 months` biasa meluap pada tanggal 29–31 dan menghasilkan rentang 11 bulan.

Filter kosong berarti tidak menyaring. Filter aktif digabung dengan AND.

### Urutan & paginasi

Tanggal terbaru lebih dulu, lalu qty terbesar. Paginasi **50 baris**, dengan `Paginator::useBootstrapFive()` yang sudah wajib di `AppServiceProvider` — default Laravel merender SVG Tailwind yang rusak di layout ini.

Paginasi mempertahankan filter yang aktif di query string.

### Export Excel

Tombol Export mengikuti **filter yang aktif**, bukan seluruh tabel. Satu baris Excel = satu baris tabel, kolom sama.

**Guest tidak bisa export**, sama seperti export lain di aplikasi ini.

### Keadaan kosong

Dua keadaan yang berbeda, dan harus terlihat berbeda:

- **Filter tidak menghasilkan apa-apa** — "Tidak ada NG yang cocok dengan filter ini."
- **Sisi Quality memang belum punya data** — prod tercatat nol NG QC sejak sistem berjalan, jadi halaman Daftar NG Total akan lahir kosong. Halamannya menyebut itu, bukan menampilkan tabel kosong yang terbaca sebagai kerusakan.

---

## Arsitektur

### Service baru

`app/Services/NgListService.php`. **Bukan** ditambahkan ke `NgDashboardService` — yang itu agregasi per bulan, yang ini query terfilter berpaginasi. Tanggung jawab berbeda, dan `NgDashboardService` sudah memikul dua method.

```php
paginate(string $sumber, array $filter, int $perPage = 50): LengthAwarePaginator
rows(string $sumber, array $filter): Collection      // untuk export, tanpa paginasi
```

`$sumber` bernilai `'produksi'` atau `'qc'`; nilai lain melempar `InvalidArgumentException`, mengikuti pola `NgDashboardService`.

Bentuk `$filter`:

```php
[
    'date_from'   => 'YYYY-MM-DD',
    'date_to'     => 'YYYY-MM-DD',
    'jenis_ng_id' => int|null,
    'q'           => string|null,   // part_no / part_name / customer
    'karyawan_id' => int|null,
]
```

Query berangkat dari tabel detail (`transaksi_produksi_ng` / `transaksi_qc_ng`), di-join ke induk dan master. Penyaringan induk lewat relasi `transaksi`, sehingga **global scope SoftDeletes ikut berlaku** — sesi yang dihapus tidak boleh muncul.

Semua pembacaan dibungkus `NgDetailService::available()`. Bila tabel detail belum ada — jendela antara rsync dan `artisan migrate` — kembalikan hasil kosong, bukan 500.

### Controller

Tambah dua method ke controller yang **sudah ada**: `daftar()` dan `export()` pada `VcbNgProsesController` dan `VcbNgTotalController`. Nol controller baru — halaman ini domain yang sama, dan kedua controller masih ~40 baris.

`daftar()` memvalidasi filter lalu merender view. `export()` memvalidasi filter yang sama lalu mengembalikan unduhan Excel.

### View

Satu Blade `resources/views/vcb/ng-list.blade.php`, dipakai kedua halaman. `$sumber` menentukan kolom mesin dan label operator/inspector. Mengikuti preseden `vcb/ng.blade.php`, yang sudah dipakai dua kali dengan cara yang sama.

Mengikuti bahasa visual ERP yang ada: `.erp-panel` / `.erp-panel-header` / `.erp-panel-body` / `.erp-table`. Nol font baru, nol bayangan, nol warna di luar token layout.

**Catatan:** kelas yang benar adalah `.erp-panel-header`. `.erp-panel-head` **tidak ada** di layout — memakainya membuat header panel dirender tanpa gaya sama sekali, dan itu pernah terjadi di `vcb/ng.blade.php` (diperbaiki PR #47).

### Export

Satu kelas `app/Exports/NgListExport.php`, berparameter `$sumber` dan `$filter`. Mengambil baris lewat `NgListService::rows()` — satu sumber kebenaran dengan yang tampil di layar.

### Route

```
grup role:admin,staff_produksi,manager,guest   → ng-proses/daftar               (grup sudah ada, baris 141)
grup role:admin,staff_produksi,manager         → ng-proses/daftar/export        (grup sudah ada, baris 155)
grup role:admin,staff_qc,manager,guest         → ng-total/daftar                (grup sudah ada, baris 160)
grup role:admin,staff_qc,manager               → ng-total/daftar/export         ← GRUP BARU
```

Nama route: `vcb.ng-proses.daftar`, `vcb.ng-proses.daftar.export`, `vcb.ng-total.daftar`, `vcb.ng-total.daftar.export`.

**Satu grup route baru diperlukan.** Sisi Produksi sudah punya grup tanpa guest (dipakai `dandori/export`); sisi QC belum. Grup baru itu mencerminkan grup Produksi persis: `role:admin,staff_qc,manager`, berisi satu route GET export.

Itu satu-satunya penambahan permukaan akses di spec ini, dan ia menambah nol kemampuan tulis — semuanya GET, `DenyGuestWrite` tidak tersentuh.

`AuthorizationTest` akan merah sampai daftar putihnya diperbarui: test itu menurunkan daftar route yang dapat dijangkau guest dari route table yang hidup. Itu gerbangnya bekerja, bukan rusak. Perbarui daftarnya, jangan longgarkan assertion-nya.

### Sidebar

Satu entri di grup Produksi (setelah VCB NG Proses), satu di grup Quality (setelah VCB NG Total), di `resources/views/layouts/app.blade.php`. Kondisi `@if(in_array($role, ...))` mengikuti grup masing-masing.

Blok `$vcbActive = match(true)` di atas nav harus memetakan URI baru ke grup yang benar, jika tidak membuka halaman daftar akan menutup accordion-nya sendiri. Prefiks `vcb/ng-proses` dan `vcb/ng-total` yang sudah terdaftar di sana **sudah mencakup** `/daftar`, karena `str_starts_with` — jadi tidak ada perubahan yang dibutuhkan di blok itu. Ini dicatat supaya tidak dikira terlewat.

---

## Testing

| Kasus | Yang dikunci |
|---|---|
| Satu sesi dengan 3 jenis NG menghasilkan 3 baris | granularitas per jenis NG |
| Filter jenis NG hanya mengembalikan jenis itu | inti halaman ini |
| Filter teks mencocokkan `part_no`, `part_name`, DAN `customer` | ketiganya, bukan satu saja |
| Filter operator/inspector menyaring orang yang benar per sisi | Produksi pakai `karyawan`, Quality pakai `karyawanQc` |
| Beberapa filter aktif digabung dengan AND | bukan OR |
| Sesi ter-soft-delete tidak muncul | penyaringan lewat relasi induk |
| Sisi `'produksi'` tidak pernah mengembalikan baris QC dan sebaliknya | tidak bocor lintas sumber |
| `$sumber` tak dikenal melempar | bukan hasil kosong yang senyap |
| Tabel detail belum ada → hasil kosong, bukan 500 | guard `available()` |
| Urutan: tanggal terbaru dulu, lalu qty terbesar | urutan yang dijanjikan |
| Guest bisa membuka kedua daftar | grup role yang sudah ada |
| Guest **tidak** bisa export kedua sisi | grup tanpa guest |
| `staff_qc` tidak bisa membuka daftar Produksi, dan sebaliknya | pemisahan role |
| Export memakai filter yang aktif, bukan seluruh tabel | export dan layar sepakat |

Test suite berjalan di SQLite in-memory. `created_by` adalah FK non-nullable pada kedua tabel transaksi; setiap test yang membuat model itu langsung wajib mengisinya.

---

## Deploy

**Nol migrasi baru**, jadi tidak ada penambahan di `.github/workflows/deploy.yml`. `./scripts/check-migration-coverage.sh` tetap dijalankan sebelum PR untuk memastikan tidak ada migrasi yang tanpa sengaja ikut terbawa.

---

## Urutan implementasi

1. `NgListService::paginate()` + `rows()` + test unit
2. Route (termasuk grup baru) + method controller + test feature otorisasi, termasuk perbaikan daftar putih `AuthorizationTest`
3. Blade bersama + filter + paginasi + keadaan kosong
4. `NgListExport` + tombol export + test
5. Entri sidebar + test
6. Verifikasi menyeluruh + PR
