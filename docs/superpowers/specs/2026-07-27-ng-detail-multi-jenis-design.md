# Fondasi Data NG Multi-Jenis — Design

**Tanggal:** 2026-07-27
**Status:** Disetujui, siap masuk rencana implementasi
**Tahap:** 1 dari 2 — fondasi data. Dashboard NG (Tahap 2) dapat spec sendiri.

---

## Latar

Permintaan awal: tambah dua halaman dashboard — **VCB NG Proses** di grup Produksi dan **VCB NG Total** di grup Quality, masing-masing berisi Pareto per jenis NG plus tren waktu.

Eksplorasi kode menemukan penghalang di lapisan data. `transaksi_produksi` dan `transaksi_qc` masing-masing menyimpan **satu** `id_jenis_ng` per baris, sementara di lapangan satu sesi produksi/pengecekan sering menemukan beberapa jenis NG sekaligus.

Akibatnya:

- Data yang ada sekarang **sudah under-report**. Operator terpaksa memilih satu jenis dominan; sisanya tidak tercatat di mana pun. Ini bukan bug yang bisa diperbaiki belakangan — informasinya tidak pernah masuk.
- Pareto di atas data itu akan **bias secara sistematis**, bukan acak: jenis NG yang jarang menjadi dominan akan konsisten tampak lebih kecil dari kenyataan.
- Pareto dipakai untuk menentukan prioritas perbaikan. Pareto bias berarti tim mengerjakan masalah yang salah, sementara halamannya berjalan mulus tanpa satu pun error — pola gagal-senyap yang sama seperti kasus `checksheet_path`.

Karena itu pekerjaan dipecah: fondasi data dulu, dashboard menyusul di atas data yang benar.

## Tujuan

Membuat satu sesi produksi atau pengecekan bisa mencatat **beberapa jenis NG beserta qty masing-masing**, tanpa merusak satu pun dari 16 file yang membaca `qty_ng` hari ini.

## Bukan tujuan (sengaja di luar scope)

- Dashboard VCB NG Proses & VCB NG Total → Tahap 2
- Multi-NG lewat import Excel → format Excel tidak berubah
- Pencabutan kolom `id_jenis_ng` → dibiarkan sebagai jejak data lama
- Rekonstruksi data historis → tidak mungkin, informasinya tidak pernah tersimpan

---

## Keputusan & alasannya

### Dua tabel detail terpisah, bukan polymorphic

```
transaksi_produksi_ng
  id
  id_transaksi_produksi   FK → transaksi_produksi   cascadeOnDelete
  id_jenis_ng             FK → master_jenis_ng      restrictOnDelete
  qty                     unsignedInteger, min 1
  timestamps
  UNIQUE (id_transaksi_produksi, id_jenis_ng)

transaksi_qc_ng           ← bentuk identik, induknya transaksi_qc
```

Alternatif yang ditolak: satu tabel polymorphic (`ng_able_type` / `ng_able_id`), dan satu tabel dengan dua FK nullable.

**Alasan menolak polymorphic:** chatbot OPTIMA menembak SQL mentah, dijaga `SqlGuard` + `BusinessTableCatalog` yang mem-whitelist tabel bisnis. Relasi polymorphic hanya hidup di level Eloquent — dari SQL, `ng_able_type` adalah string yang harus dicocokkan manual, dan tidak ada foreign key yang bisa ditegakkan database. Setiap capability chatbot yang menyentuh NG jadi harus tahu konvensi morph.

**Alasan menolak dua FK nullable:** selalu ada kolom NULL, butuh CHECK constraint agar tidak ada baris mengisi keduanya, dan setiap query harus ingat menyaring kolom mana yang dipakai.

Ongkos yang diterima: dua model berbentuk kembar. Duplikasi yang terlihat, bukan abstraksi yang menyembunyikan ketidakcocokan. Logika bersama ditaruh di satu trait.

`UNIQUE` memastikan satu jenis NG tidak bisa dobel dalam satu sesi — ditegakkan database, bukan hanya validasi form.

Catatan soal `restrictOnDelete` pada `id_jenis_ng`: `master_jenis_ng` memakai SoftDeletes, sehingga penghapusan jenis NG lewat UI tidak pernah memicu constraint ini. `restrictOnDelete` dipasang sebagai pagar untuk penghapusan keras langsung di database — bukan mekanisme yang dipakai alur normal.

### Tabel detail TIDAK memakai SoftDeletes

Penyimpangan sengaja dari konvensi repo ("SoftDeletes semua model").

Setiap simpan, baris detail ditulis ulang seluruhnya: hapus semua → insert baru. Dengan SoftDeletes, baris yang terhapus masih menempati slot `UNIQUE`, sehingga operator yang menghapus "dented" lalu menambahkannya kembali akan tertabrak duplicate key — friksi yang sama persis dengan yang membuat jalur Import harus memakai `firstOrNew()` + set `deleted_at` manual.

Baris detail juga tidak punya arti lepas dari induknya; tidak ada skenario "restore satu baris NG". Induk tetap SoftDeletes, dan detail hanya dibaca lewat induk — sesi yang ter-soft-delete otomatis hilang dari semua agregasi.

### `qty_ng` menjadi angka turunan, `id_jenis_ng` pensiun

`qty_ng` tetap ada dan tetap diisi, nilainya = `SUM(qty)` dari baris detail, dihitung ulang setiap simpan.

Alasan mempertahankannya: `qty_ng` dibaca di 16 file — `DashboardService`, `OeeService`, `SphRecommendationService`, `ChatbotTools`, `BusinessTableCatalog`, Exports, Imports, Requests, Controllers. Mencabutnya berarti mengubah semuanya sekaligus, dan satu saja yang terlewat membuat angkanya diam-diam menjadi nol — bukan error.

`id_jenis_ng` berhenti ditulis, kolomnya dibiarkan untuk jejak data lama. Tidak di-drop agar rollback tetap aman.

### Sinkronisasi lewat service eksplisit, bukan observer

`app/Services/NgDetailService.php`:

```php
syncProduksi(TransaksiProduksi $trx, array $items): void
syncQc(TransaksiQc $trx, array $items): void
```

Satu `DB::transaction`: hapus detail lama → insert baru → set `qty_ng` induk = jumlah `qty` detail → simpan.

Eloquent observer ditolak: tidak jalan di operasi bulk, dan membuat efek samping tak terlihat dari controller. Karena `qty_ng` dibaca 16 file, kapan tepatnya nilai itu berubah harus mudah dilacak. Service juga mengikuti pola repo yang memang berat di lapisan Service.

Jalur form dan jalur import bertemu di service yang sama — tidak ada dua cara menulis NG.

---

## Perubahan form

### Payload

Form mengirim array: `ng[0][id_jenis_ng]`, `ng[0][qty]`, dst. `qty_ng` **berhenti dikirim form** — hanya ditulis `NgDetailService`.

### LHP Produksi

Checkbox `ada_ng` yang ada sekarang dipertahankan. Saat dicentang, yang muncul bukan sepasang field melainkan blok baris berulang mengikuti pola form Dandori (`rows` + `x-for` Alpine): tiap baris berisi typeahead jenis NG + input qty, dengan tombol tambah/hapus baris.

Total NG ditampilkan read-only, dihitung live di Alpine — operator melihat angkanya tanpa bisa mengeditnya.

### LHP Quality

`qty_ng` yang sekarang wajib diisi minimal 0 diganti blok baris yang sama. Nol baris berarti nol NG. Aturan lama "qty_ok + qty_ng harus > 0" tetap berlaku, dengan sisi NG diambil dari jumlah baris.

### Form edit

Memuat seluruh baris detail yang ada, bukan hanya satu jenis dominan.

### Validasi

| Aturan | Alasan |
|---|---|
| `ng` array, `required_if:ada_ng,1` (produksi) | menjaga checkbox tercentang tapi kosong |
| `ng.*.id_jenis_ng` required, `exists:master_jenis_ng,id`, `distinct` | `distinct` menangkap jenis dobel di lapisan validasi sehingga operator dapat pesan jelas, bukan duplicate key dari DB |
| `ng.*.qty` required, integer, min 1 | baris tanpa qty tidak punya arti |

`UNIQUE` di database tetap ada sebagai jaring terakhir. Validasi untuk pesan yang enak dibaca; constraint untuk jaminan.

---

## Migrasi data lama

File migrasi terpisah dari yang membuat tabel, memakai `chunkById(500)` agar tidak menelan memori.

Setiap baris `transaksi_produksi` / `transaksi_qc` dengan `qty_ng > 0` **dan** `id_jenis_ng` terisi → menjadi satu baris detail. Induk yang ter-soft-delete ikut dimigrasi (`withTrashed`) agar data tidak bolong bila di-restore.

**Migrasi tidak menyentuh `qty_ng` induk sama sekali.** Nilai lamanya dibiarkan apa adanya. `qty_ng` baru mulai dihitung ulang dari detail ketika sesi itu disimpan lewat form atau import setelah rilis. Konsekuensinya, baris historis yang tidak pernah disunting akan selamanya memakai `qty_ng` versi lama — dan itu memang angka yang paling benar yang pernah dimiliki sistem untuk baris tersebut.

### Baris dengan `qty_ng > 0` tapi `id_jenis_ng` NULL → **di-skip**

Skema lama mengizinkan kondisi ini: di Produksi `id_jenis_ng` nullable, dan di QC `qty_ng` wajib sementara `id_jenis_ng` nullable.

Keputusan: baris seperti ini **tidak** dimigrasi. Tidak ada baris master jenis NG karangan yang dibuat.

**Konsekuensi yang diterima:** untuk data historis, `SUM(detail) < qty_ng` induk.

**Penanganan agar selisihnya tidak jadi angka hilang:**

1. Migrasi mencetak jumlah baris yang di-skip ke log deploy — angkanya diketahui sekali di awal, bukan tak terlihat.
2. Dashboard Tahap 2 menghitung sisa (`qty_ng − SUM(detail)`) **saat query** dan menampilkannya sebagai baris "Tanpa jenis" di Pareto. Tidak ada data palsu di database, total tetap balance, dan porsinya terlihat.

Data historis akan tetap satu-jenis-per-sesi selamanya. Tahap 2 memberi label eksplisit bahwa data sebelum tanggal rilis hanya menyimpan jenis dominan, agar tren tidak salah dibaca.

---

## Import & Export

**Import Excel** — format tidak berubah sama sekali, nol training ulang. `ProduksiImport` & `QcImport` tetap membaca satu kolom qty NG + satu jenis NG per baris, lalu memanggil `NgDetailService` untuk membuat satu baris detail.

**Export** — `LhpProduksiExport` & `LhpQcExport` saat ini mengeluarkan satu kolom jenis NG. Karena satu sesi bisa punya beberapa jenis, kolom itu menjadi gabungan ringkas: `dented (5); crack (2)`. Satu baris tetap satu sesi; bentuk file tidak berubah, hanya isinya lebih kaya. Ini penting karena file ekspor kemungkinan dipakai orang di luar sistem.

---

## Otorisasi

Nol route baru. Semua perubahan menempel pada form LHP Produksi & Quality yang sudah ada, sehingga aturan role yang berlaku sekarang otomatis terpakai:

- `staff_produksi` → LHP Produksi
- `staff_qc` → LHP Quality
- `admin` → keduanya
- `guest` → ditolak `DenyGuestWrite`

Tidak ada permukaan baru yang perlu di-review dari sisi akses.

---

## Testing

| Kasus | Yang dikunci |
|---|---|
| Simpan sesi dengan 3 jenis NG → 3 baris detail, `qty_ng` = jumlahnya | inti fitur |
| Edit sesi: 3 jenis → 1 jenis, detail lama hilang, `qty_ng` ikut turun | tulis-ulang bersih, bukan menumpuk |
| Jenis NG dobel dalam satu sesi → ditolak validasi | `distinct` bekerja sebelum menyentuh DB |
| Hapus lalu tambah jenis yang sama dalam satu sesi → berhasil | mengunci keputusan "tanpa SoftDeletes"; test ini gagal bila SoftDeletes dipasang |
| Import Excel satu jenis → satu baris detail, `qty_ng` benar | jalur import bertemu service yang sama |
| Migrasi data lama: baris ber-jenis ikut, baris tanpa jenis di-skip | mengunci keputusan migrasi |
| `qty_ng` tetap terbaca benar oleh `DashboardService` & `OeeService` | 16 pembaca lama tidak rusak |

Test suite berjalan di SQLite in-memory. Perhatian khusus: `UNIQUE` dan perilaku FK berbeda antara SQLite dan MySQL — pastikan test menegakkan constraint, bukan hanya validasi aplikasi.

---

## Deploy

Tiga migrasi:

1. `create_transaksi_produksi_ng_table`
2. `create_transaksi_qc_ng_table`
3. `migrate_existing_ng_to_detail_tables`

**Ketiganya wajib didaftarkan di `.github/workflows/deploy.yml` pada PR yang sama.** Lupa = gagal senyap: deploy hijau, `Nothing to migrate`, kode live tanpa tabelnya. Sudah tiga kali terjadi di proyek ini.

`./scripts/check-migration-coverage.sh` dijalankan lokal sebelum membuat PR.

Semua pembacaan tabel detail dibungkus `Schema::hasTable(...)` — kode dan skema tidak dijamin tiba bersamaan di produksi.

---

## Urutan implementasi

1. Migrasi tabel + model + trait bersama
2. `NgDetailService` + test unit
3. Form Produksi (create, edit, request, controller) + test feature
4. Form Quality (create, edit, request, controller) + test feature
5. Import & Export
6. Migrasi data lama + test
7. Daftarkan ketiga migrasi di `deploy.yml`

Setiap langkah menjaga suite tetap hijau. `qty_ng` tidak boleh pernah salah di titik mana pun — 16 pembaca bergantung padanya sepanjang perjalanan.
