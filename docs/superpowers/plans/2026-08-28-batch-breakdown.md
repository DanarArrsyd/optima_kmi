# Halaman Batch Breakdown Mesin & Dies (PR 4) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pindahkan Breakdown Mesin dan Breakdown Dies dari laci ke halaman batch, menutup migrasi empat modul.

**Architecture:** Konsumen keempat dan kelima `batchDraft(cfg)`. Dua modul ini kembar — beda cuma sasaran (`id_mesin` vs `id_part_proses`), nama kolom problem, dan awalan nomor (LKM vs LKD). Mesin dikerjakan lebih dulu sampai tuntas; Dies menyusul memakai pola yang sudah terbukti.

**Tech Stack:** Laravel 13, Blade, Alpine.js 3.14.1 (CDN), Bootstrap 5.3.3, PHPUnit 12, Pint.

## Global Constraints

- PHP lokal 8.5.4, **CI & prod 8.4** — jangan pakai sintaks khusus 8.5.
- **Nol migrasi.** `.github/workflows/deploy.yml` tidak boleh berubah.
- Kontrak `cfg` yang berlaku (spec §1): `kunci`/`kunciBackup` string LENGKAP dirakit Blade; `formId`, `indexUrl`, `galatServer`, `kumpulkan`, `wajib`, `payload` WAJIB dan dijaga lemparan galat; `resetLain(diri)` callback; `cfg.metode` disebar paling awal.
- Kunci draft: `optima_draft_breakdown_mesin_u{id}` / `..._terkirim_u{id}`, dan `optima_draft_breakdown_dies_u{id}` / `..._terkirim_u{id}`. `_terkirim` MENDAHULUI `_u{id}`.
- **`repair_time`, `total_downtime`, dan `status_breakdown` dihitung SERVER** oleh `BreakdownMesinService::computeDerived()` / `BreakdownDiesService`. Browser tidak pernah mengirimnya.
- Laci **filter** dan laci **note** TIDAK dicabut. Hanya laci penulis.
- Nol entri sidebar baru.
- Bahasa komentar, pesan galat, commit: Indonesia. Scope Pint = `app/` + `tests/`.

## Yang khas modul ini

**`nomor_urut` → No LKM/LKD.** `generateNoLkm(int $nomorUrut, string $tanggal)` menghasilkan `5/LKM/MTC/KMI/VIII/26` (nomor / awalan / bulan romawi / dua digit tahun). Hari ini tabrakannya diperiksa di controller terhadap DB (`BreakdownMesinController.php:139`).

Di mode batch tabrakan datang dari **DUA arah**:

1. Lawan baris yang sudah ada di database.
2. **Antar baris di dalam satu draft** — arah yang nol padanannya di jalur lama, dan yang paling mungkin lolos kalau pola cuma disalin.

⚠️ Nomornya BUKAN kunci global: `generateNoLkm` menyisipkan bulan dan tahun, jadi nomor 5 di bulan VIII dan nomor 5 di bulan IX adalah dua No LKM berbeda dan sah. Pemeriksaan tabrakan WAJIB atas string No LKM yang sudah jadi, bukan atas `nomor_urut` telanjang. Membandingkan `nomor_urut` saja menolak baris yang sah begitu satu batch memuat dua bulan.

⚠️ Pemeriksaan lawan DB harus `withTrashed()` — jalur lama memakainya, dan No LKM baris terhapus tetap tidak boleh dipakai ulang.

**`cfg.setelahPulih`.** Nomor urut menaik sendiri tiap baris masuk draft, tapi `batchDraft.mulai()` memulihkan draft dari `localStorage` tanpa memberi tahu modul. Skenario yang rusak tanpa seam ini:

> Operator menyimpan 8 baris (nomor 5–12), menutup tab. Membuka lagi halaman batch: server merender `nextNomorUrut()` = 5 ke form, `mulai()` memulihkan 8 baris tapi tidak menyentuh form. Baris ke-9 masuk dengan nomor 5 → tabrakan DI DALAM draft → seluruh batch ditolak, sementara form di layar terlihat benar.

Sama terjadi pada tiap putaran penolakan validasi, yang justru kasus umum untuk form 12 field. Modul tidak bisa menambal sendiri: `cfg.metode` disebar paling awal sehingga tidak bisa membungkus `mulai`.

---

### Task 1: `BreakdownMesinWriteService` + `StoreBreakdownMesinBatchRequest` + endpoint

**Files:**
- Create: `app/Services/BreakdownMesinWriteService.php`, `app/Http/Requests/StoreBreakdownMesinBatchRequest.php`
- Modify: `app/Http/Controllers/Maintenance/BreakdownMesinController.php` (konstruktor + `batch`), `routes/web.php:194`
- Test: `tests/Feature/Maintenance/BreakdownMesinBatchTest.php`

**Interfaces:**
- Consumes: `BreakdownMesinService::generateNoLkm(int, string): string`, `::computeDerived(array): array`, `::nextNomorUrut(): int`
- Produces: `BreakdownMesinWriteService::simpan(array $baris): TransaksiBreakdownMesin` — menulis satu baris, TIDAK membuka transaksi sendiri. Route `maintenance.breakdown.batch` (POST `maintenance/breakdown/batch`) menerima `rows_json` ATAU `rows`; redirect ke `maintenance.breakdown.index` dengan flash `success` + `batch_tersimpan`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Maintenance/BreakdownMesinBatchTest.php`. Pakai `tests/Feature/Input/DandoriBatchTest.php` sebagai templat bentuk — ia yang paling baru dan sudah lewat review. Fixture: `MasterMesin`, user `admin`.

Payload satu baris: `nomor_urut`, `id_mesin`, `tanggal_breakdown`, `jam_breakdown`, `tanggal_mulai_perbaikan`, `jam_mulai_perbaikan`, `tanggal_selesai_perbaikan`, `jam_selesai_perbaikan`, `problem_mesin`, `penyebab_breakdown`, `tindakan_perbaikan`, `pic_maintenance`.

Test wajib:

```php
public function test_satu_submit_menulis_semua_baris(): void
public function test_no_lkm_dirakit_dari_nomor_urut_dan_tanggal(): void
public function test_no_lkm_bentrok_dengan_data_lama_ditolak(): void
public function test_no_lkm_bentrok_dengan_baris_terhapus_juga_ditolak(): void
public function test_no_lkm_kembar_antar_baris_dalam_satu_draft_ditolak(): void
public function test_nomor_sama_di_bulan_berbeda_diterima(): void
public function test_angka_turunan_dihitung_server_bukan_dari_payload(): void
public function test_satu_baris_gagal_membatalkan_semuanya(): void
public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
public function test_rows_kosong_ditolak(): void
public function test_guest_tidak_bisa_menyimpan(): void
public function test_rows_json_menyimpan_baris(): void
public function test_rows_json_rusak_ditolak_bukan_meledak(): void
public function test_rows_json_menampung_50_baris_batas_server(): void
public function test_lebih_dari_50_baris_ditolak(): void
public function test_pesan_validasi_berbahasa_indonesia(): void
```

Empat yang menentukan, tulis dengan hati-hati:

- **`test_no_lkm_kembar_antar_baris_dalam_satu_draft_ditolak`** — dua baris dengan `nomor_urut` sama dan bulan sama. Error harus jatuh di `rows.1.nomor_urut` (baris KEDUA, bukan pertama), dan nol baris tertulis. Kirim baris pertama yang SAH supaya indeksnya benar-benar diuji.
- **`test_nomor_sama_di_bulan_berbeda_diterima`** — dua baris `nomor_urut` sama tapi `tanggal_breakdown` beda bulan. HARUS diterima, dua baris tertulis. Test ini yang mencegah "perbaikan" naif yang membandingkan `nomor_urut` telanjang.
- **`test_no_lkm_bentrok_dengan_baris_terhapus_juga_ditolak`** — buat satu baris lalu `delete()` (soft), kirim batch dengan No LKM sama. Harus ditolak. Tanpa `withTrashed()`, No LKM terpakai ulang dan riwayat jadi ambigu.
- **`test_angka_turunan_dihitung_server_bukan_dari_payload`** — selipkan `repair_time`, `total_downtime`, `status_breakdown` palsu di payload; asersi baris tersimpan memakai hasil `computeDerived()`.

Untuk `test_gagal_di_tengah_transaksi_tidak_menyisakan_baris`: ikat double `BreakdownMesinWriteService` lewat `$this->app->instance()` yang berhasil di panggilan pertama lalu melempar di kedua, pakai `withoutExceptionHandling()`, tangkap, asersi `count()` nol. Jangan memicu kegagalan lewat validasi — itu tidak pernah memasuki controller dan tidak menguji transaksinya.

- [ ] **Step 2: Jalankan, pastikan GAGAL**

Run: `php artisan test --filter=BreakdownMesinBatchTest`
Expected: FAIL — `Route [maintenance.breakdown.batch] not defined`.

- [ ] **Step 3: Buat write service**

`BreakdownMesinWriteService::simpan(array $baris)` memindahkan isi `BreakdownMesinController::store()` (baris 133-160) apa adanya: rakit No LKM, `computeDerived()`, `create()`. TIDAK membuka transaksi sendiri — docblock menjelaskan kenapa, mengikuti `ProduksiWriteService`. Pemeriksaan tabrakan TIDAK di sini; itu urusan request (lihat Step 4) supaya seluruh batch ditolak sebelum satu baris pun tertulis.

- [ ] **Step 4: Buat batch request**

Baca `app/Http/Requests/StoreDandoriBatchRequest.php` dulu — struktur `prepareForValidation()` + `withValidator()` disalin dari sana. Aturan per baris mengangkat `StoreBreakdownMesinRequest` apa adanya, dengan awalan `rows.*.`.

`withValidator()` menjalankan satu pemeriksa tabrakan yang menutup KEDUA arah dalam satu lintasan:

```php
$dilihat = [];   // No LKM => indeks baris yang pertama memakainya

foreach ($rows as $i => $row) {
    // Baris yang field-nya belum lolos aturan dasar tidak bisa dirakit
    // jadi No LKM; melewatinya mencegah galat kedua yang membingungkan
    // di baris yang sudah punya galat sendiri.
    if (! isset($row['nomor_urut'], $row['tanggal_breakdown'])) {
        continue;
    }

    $noLkm = $svc->generateNoLkm((int) $row['nomor_urut'], $row['tanggal_breakdown']);

    if (isset($dilihat[$noLkm])) {
        // Tabrakan ANTAR BARIS. Sebut nomor baris lawannya — operator
        // sedang menatap 50 baris dan harus tahu yang mana.
        $validator->errors()->add("rows.{$i}.nomor_urut", "Baris ".($i+1).": No LKM {$noLkm} sama dengan baris ".($dilihat[$noLkm]+1).".");
        continue;
    }

    $dilihat[$noLkm] = $i;

    if (TransaksiBreakdownMesin::withTrashed()->where('no_lkm', $noLkm)->exists()) {
        $validator->errors()->add("rows.{$i}.nomor_urut", "Baris ".($i+1).": No LKM {$noLkm} sudah ada, gunakan nomor lain.");
    }
}
```

⚠️ Kunci peta adalah **No LKM yang sudah jadi**, bukan `nomor_urut`. Nomor 5 bulan VIII dan nomor 5 bulan IX adalah dua nomor sah yang berbeda.

- [ ] **Step 5: Endpoint + route**

`BreakdownMesinController::batch()` membungkus loop `simpan()` dalam satu `DB::transaction`, lalu redirect dengan `success` dan `batch_tersimpan` (jumlah baris). Salin bentuk komentarnya dari `DandoriController::batch()` — `batch_tersimpan` adalah bukti sukses yang dibaca halaman daftar untuk membuang draft.

Route di grup yang sama dengan `Route::resource('breakdown', ...)` (baris 194), DITARUH SEBELUM baris resource itu:

```php
Route::post('breakdown/batch', [BreakdownMesinController::class, 'batch'])->name('breakdown.batch');
```

Route `store` BELUM dicabut — Task 3.

- [ ] **Step 6: Jalankan, pastikan LULUS**

Run: `php artisan test --filter="BreakdownMesinBatchTest|AuthorizationTest"`

- [ ] **Step 7: Format & commit**

```bash
vendor/bin/pint app tests
git add -A && git commit -m "feat(breakdown): endpoint batch Breakdown Mesin"
```

---

### Task 2: Seam `cfg.setelahPulih` + halaman batch Breakdown Mesin

Halaman yatim `maintenance.breakdown.create` (`resources/views/maintenance/breakdown/create.blade.php`) diganti isinya. Route-nya sudah ada dan sudah diuji `AuthorizationTest`.

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (seam `setelahPulih`)
- Rewrite: `resources/views/maintenance/breakdown/create.blade.php`
- Modify: `resources/views/maintenance/breakdown/index.blade.php` (blok pembersih draft SAJA)
- Modify: `app/Http/Controllers/Maintenance/BreakdownMesinController.php:116` (`create()` menyiapkan data halaman batch)
- Test: `tests/Feature/Maintenance/HalamanBatchBreakdownMesinTest.php`

**Interfaces:**
- Produces: `cfg.setelahPulih(diri)` — callback opsional, dipanggil di AKHIR `mulai()` setelah `rows` dipulihkan dan `barisGagal` dihitung. Dipakai modul untuk menyesuaikan form terhadap draft yang baru dipulihkan.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Maintenance/HalamanBatchBreakdownMesinTest.php`, memakai `tests/Feature/Input/HalamanBatchDandoriTest.php` sebagai templat: form mengirim ke endpoint batch, tabel draft + tombolnya ada, `x-data="batchDraft(`, guest ditolak, `rows_json` bukan input per baris, `<div>` seimbang, kedua literal kunci draft ada, kunci cocok dengan yang dihapus halaman daftar, dan penjaga global JS (`x-data="taField(IDENT` wajib punya `const IDENT =`).

Tiga yang khas modul ini:

```php
public function test_nomor_urut_awal_diisi_dari_next_nomor_urut(): void
public function test_halaman_memasang_setelah_pulih(): void
public function test_halaman_tidak_mengirim_angka_turunan_dari_browser(): void
```

`test_halaman_memasang_setelah_pulih` menjangkar ke `setelahPulih` DI DALAM blok `konfigBreakdownMesin()`, bukan di seluruh HTML — nama itu juga muncul di definisi `batchDraft` di layout, yang terender di setiap halaman. Pelajaran dari PR #129.

`test_halaman_tidak_mengirim_angka_turunan_dari_browser` menjangkar ke blok `payload(` dan menuntut ketiadaan `repair_time`, `total_downtime`, `status_breakdown`.

- [ ] **Step 2: Jalankan, pastikan GAGAL**

- [ ] **Step 3: Tambah seam di layout**

Di `batchDraft()` dalam `resources/views/layouts/app.blade.php`, di AKHIR `mulai()` (setelah `barisGagal` dihitung):

```js
            /* Modul yang bentuk formnya bergantung pada isi draft menyesuaikan
               di sini. Breakdown memakainya untuk melanjutkan nomor urut:
               server merender nomor berikutnya berdasarkan DATABASE, sementara
               draft yang baru dipulihkan mungkin sudah memakai beberapa nomor
               sesudahnya. Tanpa kail ini baris berikutnya memakai nomor yang
               sudah terpakai di draft, dan seluruh batch ditolak sementara
               form di layar terlihat benar.

               Modul tidak bisa menambal sendiri: cfg.metode disebar paling
               awal, jadi mulai() tidak bisa dibungkus dari luar. */
            if (cfg.setelahPulih) cfg.setelahPulih(this);
```

Tambahkan `setelahPulih` ke tabel kontrak `cfg` di `docs/superpowers/specs/2026-08-27-batch-input-empat-modul-design.md` §1, dan hapus catatan "ditunda ke PR 4" di §2.3 karena sudah terpasang.

⚠️ JANGAN memasukkannya ke daftar kunci wajib — ia opsional, dan tiga modul yang sudah live tidak mengirimnya.

- [ ] **Step 4: Siapkan data controller + tulis halaman**

`BreakdownMesinController::create()` mengirim daftar mesin dan `nextNomorUrut()`.

Halaman memakai `resources/views/input/qc/create.blade.php` sebagai templat susunan. Field:

| Field | Bentuk |
|---|---|
| `nomor_urut` | number, nilai awal `nextNomorUrut()` dari server |
| `id_mesin` | `taField(taMesin)` |
| `tanggal_breakdown` + `jam_breakdown` | date + time |
| `tanggal_mulai_perbaikan` + `jam_mulai_perbaikan` | date + time |
| `tanggal_selesai_perbaikan` + `jam_selesai_perbaikan` | date + time |
| `problem_mesin`, `penyebab_breakdown`, `tindakan_perbaikan` | textarea |
| `pic_maintenance` | text |

`konfigBreakdownMesin()`:

- `kunci: 'optima_draft_breakdown_mesin_u{{ auth()->id() }}'`, `kunciBackup: '..._terkirim_u{{ auth()->id() }}'`
- `wajib`: keduabelas field, label Indonesia
- `periksa(calon, rows, diri)`: tolak tanggal mundur (`mulai_perbaikan` < `breakdown`, `selesai` < `mulai`) dengan pesan yang menyebut field-nya. Tolak No LKM kembar di dalam draft — server juga menolaknya, tapi menangkapnya di browser menghemat operator satu putaran penuh. **Nol pemeriksaan tabrakan jam** — dua mesin berbeda boleh rusak bersamaan.
- `pratinjau(calon, diri)`: hitung `repair_jam` dan `downtime_jam` untuk kolom tabel draft. Beri label yang menyatakan itu pratinjau; server yang menghitung angka tersimpan.
- `payload(r)`: keduabelas field saja. **JANGAN kirim `repair_time`/`total_downtime`/`status_breakdown`.**
- `resetField`: field per-kejadian (jam, problem, penyebab, tindakan). Tanggal, mesin, dan PIC LENGKET.
- `resetLain(diri)`: naikkan `nomor_urut` ke `max(nomor di draft) + 1`.
- `setelahPulih(diri)`: sama — hitung ulang `nomor_urut` dari `rows` yang baru dipulihkan.

Kolom tabel draft: No LKM (pratinjau), Mesin, Tgl/Jam Breakdown, Tgl/Jam Selesai, Repair (j), Downtime (j), PIC, tombol hapus.

- [ ] **Step 5: Blok pembersih draft di halaman daftar**

Di `resources/views/maintenance/breakdown/index.blade.php`, salin bentuk dari `input/qc/index.blade.php`:

```blade
@if (session('batch_tersimpan'))
(function() {
    try {
        localStorage.removeItem('optima_draft_breakdown_mesin_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_breakdown_mesin_terkirim_u{{ auth()->id() }}');
    } catch (e) {}
})();
@endif
```

⚠️ WAJIB di task yang sama dengan halaman batchnya. Tanpa ini batch tersimpan bisa TERKIRIM DOBEL tanpa error — kejadian di QC, ketangkap review.

- [ ] **Step 6: Jalankan, pastikan LULUS. Step 7: commit.**

---

### Task 3: Cabut laci Breakdown Mesin, pensiunkan `store`

**Files:**
- Modify: `resources/views/maintenance/breakdown/index.blade.php`, `BreakdownMesinController.php`, `routes/web.php`
- Delete: `app/Http/Requests/StoreBreakdownMesinRequest.php` — HANYA kalau nol pemakai

- [ ] **Step 1:** Cabut laci penulis (`erp-drawer` di baris ~47) + plumbing matinya. Tombol "Input Baru" jadi `<a href="{{ route('maintenance.breakdown.create') }}">`. Laci note, dock filter, dan form import TETAP. Grep tiap variabel `@php` sebelum menghapus — di Dandori, mencabut 5 query master menjatuhkan 13 test karena dock filter memakainya.

- [ ] **Step 2:** Periksa apakah halaman masih merender `$errors`. Laci penulis biasanya satu-satunya perendernya, dan `import()` memvalidasi berkas ke bag default. Sudah kejadian di QC (#126) dan Dandori (#127). Kalau hilang, tambahkan bloknya + test yang mengasersi pesannya sampai ke halaman terender.

- [ ] **Step 3:** `Route::resource('breakdown', ...)->only(['index', 'create', 'store'])` jadi `->only(['index', 'create'])`. Hapus `store()`. Grep `StoreBreakdownMesinRequest` — hapus kalau nol pemakai.

⚠️ Periksa `tests/Feature/DateRangeGuardTest.php`. Kalau ia mengawasi `StoreBreakdownMesinRequest`, alihkan ke request batch dengan field bersarang (`rows.*.tanggal_breakdown`). Melewatkannya membuat penjaga tanggal mengawasi kelas mati sementara request LIVE tidak dijaga — hampir kejadian di #114, benar-benar ada di #126 dan #127.

- [ ] **Step 4:** Seluruh suite. **Step 5:** commit.

---

### Task 4: Breakdown Dies — seluruhnya

Kembar Mesin. Kerjakan sekaligus (service + request + endpoint + halaman + cabut laci) memakai pola yang sudah terbukti di Task 1-3.

**Beda dari Mesin:**

| Aspek | Mesin | Dies |
|---|---|---|
| Sasaran | `id_mesin` | `id_part_proses` (part + proses) |
| Kolom problem | `problem_mesin` | `problem_dies` |
| Awalan nomor | `no_lkm` / `generateNoLkm` | `no_lkd` / `generateNoLkd` |
| Route dasar | `maintenance.breakdown.*` | `maintenance.breakdown-dies.*` |
| Halaman create | sudah ada (yatim) | **belum ada** — route `create` harus DITAMBAH |
| Parameter route | `{breakdown}` | `{breakdownDy}` |

⚠️ Dies memakai `partPicker(taParts, partsData)` untuk memilih part + proses. Pemilih Proses WAJIB berada di dalam scope `x-data="partPicker(...)"` — halaman produksi pernah shipping dengan pemilih di luar scope: semua test render server lulus sementara halaman tidak bisa menyimpan satu baris pun di peramban. Uji dengan DOMXPath.

⚠️ `Route::resource('breakdown-dies', ...)->only(['index', 'store'])` — TAMBAH `create` dan `batch`, buang `store` di akhir. Perhatikan `->parameters(['breakdown-dies' => 'breakdownDy'])` jangan hilang.

Test menyalin `BreakdownMesinBatchTest` dan `HalamanBatchBreakdownMesinTest`, termasuk keempat test No LKD yang menentukan.

---

### Task 5: Gerbang akhir & PR

- [ ] `php artisan test` — PASS, laporkan jumlah dan jelaskan selisihnya.
- [ ] `./scripts/check-migration-coverage.sh` — 54/14/40 tak berubah.
- [ ] `vendor/bin/pint app tests` — passed.
- [ ] Periksa sisa:

```bash
grep -rn "maintenance.breakdown.store\|maintenance.breakdown-dies.store" app/ routes/ resources/
grep -c "erp-drawer" resources/views/maintenance/breakdown/index.blade.php
grep -c "erp-drawer" resources/views/maintenance/breakdown-dies/index.blade.php
```

Dua yang terakhir mengembalikan 2 (kelas `erp-drawer-hd` ikut cocok) — yang penting tepat SATU elemen laci tersisa, yaitu laci note. Bandingkan dengan `input/qc/index.blade.php` yang sudah beres.

- [ ] Buka PR. Isi menyebut: PR 4 dari 4, migrasi empat modul TUNTAS; seam `cfg.setelahPulih` beserta skenario yang memaksanya ada; pemeriksaan No LKM/LKD dua arah dan kenapa kuncinya string No LKM bukan `nomor_urut`; halaman yatim Mesin diganti isinya, `create` Dies ditambah; nol migrasi.

---

## Catatan penutup migrasi

Setelah PR ini, keempat modul memakai `batchDraft` dan nol modul Input menyisakan laci penulis. Yang tersisa di `docs/superpowers/specs/2026-08-27-batch-input-empat-modul-design.md` §1 sebagai kekurangan yang diketahui: seam `cfg.komponen` untuk reset sub-widget. Lima konsumen sekarang menjangkau DOM/Alpine dengan tangan sendiri; keputusan menambah seam itu — atau membiarkannya — sebaiknya diambil dengan lima contoh nyata di depan mata, bukan sekarang.
