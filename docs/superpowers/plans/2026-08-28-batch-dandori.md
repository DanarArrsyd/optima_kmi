# Halaman Batch Input Dandori (PR 3) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pindahkan Input Dandori dari laci ke halaman batch sendiri, memakai kerangka `batchDraft()` di layout.

**Architecture:** Konsumen ketiga `batchDraft(cfg)`. Bedanya dengan QC dan produksi: **nol service baru** — `DandoriRincianService::simpan($sesi, $rincian): int` sudah ada dan dipakai `DandoriController::store()` hari ini; endpoint batch memanggilnya sekali per baris draft di dalam satu `DB::transaction`.

**Tech Stack:** Laravel 13, Blade, Alpine.js 3.14.1 (CDN), Bootstrap 5.3.3, PHPUnit 12 (`php artisan test`, SQLite in-memory), Pint.

## Global Constraints

- PHP lokal 8.5.4, **CI & prod 8.4** — jangan pakai sintaks yang hanya ada di 8.5.
- **Nol migrasi.** `.github/workflows/deploy.yml` tidak boleh berubah.
- **Satu baris draft Dandori = satu SESI berisi N rincian, dan satu sesi menulis N BARIS TABEL.** Ini beda dari QC dan produksi yang satu baris draft = satu baris tabel. Konsekuensinya: pesan sukses dan flash `batch_tersimpan` menghitung hal yang berbeda — lihat Task 1 Step 4.
- Kontrak `cfg` yang berlaku (PR #125 + #126, lihat `docs/superpowers/specs/2026-08-27-batch-input-empat-modul-design.md` §1): `kunci`/`kunciBackup` string LENGKAP dirakit Blade, tidak ada `userId`; `formId`, `indexUrl`, `galatServer`, `kumpulkan`, `wajib`, `payload` semua WAJIB dan dijaga lemparan galat; `resetLain(diri)` callback; `cfg.metode` disebar paling awal (kerangka menang).
- Kunci draft Dandori: `optima_draft_dandori_u{id}` dan `optima_draft_dandori_terkirim_u{id}`. `_terkirim` MENDAHULUI `_u{id}`.
- **`std_menit` dan `over_menit` dihitung SERVER**, satu definisi di `DandoriRincianService`. Angka Std/Over di tabel draft adalah pratinjau. Jembatan PROGRESSIVE lewat `data-progressive` di `<option>` proses. Jangan pernah mengirim `std_menit`/`over_menit` dari browser.
- Laci **filter** (`erp-dock`) dan laci **note** (`erp-drawer` kedua, sekitar baris 791) di `input/dandori/index.blade.php` TIDAK dicabut. Yang dicabut hanya laci penulis di baris 79.
- Nol entri sidebar baru.
- Bahasa komentar, pesan galat, dan commit: Indonesia. Scope Pint = `app/` + `tests/`.

---

### Task 1: `StoreDandoriBatchRequest` + endpoint `batch`

**Files:**
- Create: `app/Http/Requests/StoreDandoriBatchRequest.php`
- Modify: `app/Http/Controllers/Input/DandoriController.php` (method `batch`), `routes/web.php:107`
- Test: `tests/Feature/Input/DandoriBatchTest.php`

**Interfaces:**
- Consumes: `App\Services\DandoriRincianService::simpan(array $sesi, array $rincian): int` — `$sesi` butuh `tanggal`, `id_shift`, `id_karyawan`, `id_mesin`, `id_part_proses`; `$rincian` array `[{id_losstime, actual_menit}]`. Mengembalikan JUMLAH BARIS TABEL yang ditulis. Tidak membuka transaksi sendiri.
- Produces: route `input.dandori.batch` (POST `input/dandori/batch`), menerima `rows_json` (string JSON) ATAU `rows` (array). Redirect ke `input.dandori.index` dengan flash `success` dan `batch_tersimpan`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Input/DandoriBatchTest.php`. Pakai `tests/Feature/Input/QcBatchTest.php` sebagai templat bentuk (helper `baris()`, `kirim()`, `kirimJson()`, gaya asersi) — ia sudah lewat dua putaran review rigor, jadi polanya sudah benar. Fixture yang dibutuhkan: `MasterShift`, `MasterKaryawan` (departemen `Produksi`), `MasterMesin`, `MasterPart` + `MasterPartProses`, dan dua `MasterLosstime` dengan `std_menit_default` DAN `std_menit_progressive` terisi beda.

Test yang harus ada:

```php
    public function test_satu_submit_menulis_semua_sesi(): void
    public function test_satu_sesi_menulis_satu_baris_per_rincian(): void
    public function test_flash_menghitung_baris_tabel_bukan_baris_draft(): void
    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    public function test_rincian_menempel_pada_sesinya_masing_masing(): void
    public function test_std_menit_diambil_dari_master_bukan_dari_payload(): void
    public function test_proses_progressive_memakai_std_progressive(): void
    public function test_over_menit_tidak_pernah_negatif(): void
    public function test_jenis_loss_time_kembar_diterima(): void
    public function test_rincian_kosong_ditolak_per_baris(): void
    public function test_rows_kosong_ditolak(): void
    public function test_guest_tidak_bisa_menyimpan(): void
    public function test_rows_json_menyimpan_baris(): void
    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    public function test_rows_json_menampung_50_baris_batas_server(): void
    public function test_lebih_dari_50_baris_ditolak(): void
    public function test_pesan_validasi_berbahasa_indonesia(): void
```

Tiga yang tidak punya padanan di QC, tulis dengan hati-hati:

- **`test_satu_sesi_menulis_satu_baris_per_rincian`** — kirim SATU baris draft dengan 3 rincian, asersi `TransaksiDandoriRincian::count()` adalah 3, dan ketiganya berbagi `tanggal`/`id_shift`/`id_karyawan`/`id_mesin`/`id_part_proses` yang sama. Ini yang membedakan Dandori dari dua modul sebelumnya.
- **`test_flash_menghitung_baris_tabel_bukan_baris_draft`** — kirim 2 baris draft, satu berisi 2 rincian dan satu berisi 3, lalu asersi `assertSessionHas('batch_tersimpan', 5)`. Kalau nilainya 2, endpoint menghitung baris draft, bukan hasil `simpan()`.
- **`test_std_menit_diambil_dari_master_bukan_dari_payload`** — sertakan `std_menit` dan `over_menit` palsu di payload rincian (mis. `999`), lalu asersi baris tersimpan memakai angka dari `MasterLosstime`, bukan angka palsu itu. Browser tidak boleh bisa mendikte angka ini.

Untuk `test_gagal_di_tengah_transaksi_tidak_menyisakan_baris`, salin pola dari `QcBatchTest`: ikat double `DandoriRincianService` lewat `$this->app->instance()` yang berhasil di panggilan pertama lalu melempar di kedua, pakai `withoutExceptionHandling()`, tangkap, lalu asersi `count()` nol.

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=DandoriBatchTest`

Expected: FAIL — `Route [input.dandori.batch] not defined`.

- [ ] **Step 3: Buat `StoreDandoriBatchRequest`**

Baca `app/Http/Requests/StoreQcBatchRequest.php` dulu — request ini mengikuti strukturnya persis (`prepareForValidation` membongkar `rows_json` + menormalkan string kosong jadi null, `withValidator` menjalankan pemeriksa per baris dengan indeks baris di kunci error dan nomor baris di pesan manusia).

Aturan, mengangkat `app/Http/Requests/StoreDandoriRequest.php` ke bentuk per-baris:

```php
'rows' => 'required|array|min:1|max:50',
// Batas atas `tomorrow`, bukan `today`: app timezone UTC, pabrik WIB (UTC+7).
'rows.*.tanggal' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
'rows.*.id_shift' => 'required|exists:master_shift,id',
'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
'rows.*.id_mesin' => 'required|exists:master_mesin,id',
'rows.*.id_part_proses' => 'required|exists:master_part_proses,id',
'rows.*.rincian' => 'required|array|min:1',
/* SENGAJA tanpa `distinct`: satu sesi bisa punya beberapa pergantian dies,
 * dan tiap pergantian memunculkan jenis loss time yang sama lagi — kejadian
 * normal di lantai produksi, bukan salah ketik. Halaman Dandori memang tidak
 * pernah melarangnya (lihat PR #118). */
'rows.*.rincian.*.id_losstime' => 'required|exists:master_losstime,id',
'rows.*.rincian.*.actual_menit' => 'required|numeric|min:0.01',
```

`authorize()`: `return $this->user()->hasRole(['admin', 'staff_produksi']);` — samakan dengan grup route yang menampung `input.dandori.store` hari ini.

`messages()`: angkat pesan Indonesia dari `StoreDandoriRequest` dan beri nomor baris, mengikuti gaya `StoreQcBatchRequest`. Minimal `rows.*.tanggal.after_or_equal` (salah ketik tahun), `rows.*.tanggal.before_or_equal`, `rows.*.rincian.required`/`min`, dan `rows.*.rincian.*.actual_menit.min`.

⚠️ Jangan menambahkan aturan untuk `std_menit` atau `over_menit`. Keduanya tidak pernah datang dari browser; `DandoriRincianService` yang menghitungnya. Kalau payload memuatnya, ia diabaikan — dan `test_std_menit_diambil_dari_master_bukan_dari_payload` membuktikan itu.

- [ ] **Step 4: Tambah endpoint dan route**

Di `DandoriController`:

```php
    public function batch(StoreDandoriBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        /* simpan() mengembalikan jumlah BARIS TABEL, bukan jumlah sesi: satu
         * sesi berisi N rincian menulis N baris. Yang dilaporkan ke operator
         * dan dipakai halaman daftar adalah angka baris tabel itu, supaya
         * cocok dengan apa yang nanti dia lihat di tabel. */
        $jumlah = DB::transaction(function () use ($rows) {
            $total = 0;
            foreach ($rows as $row) {
                $total += $this->dandoriRincianService->simpan([
                    'tanggal' => $row['tanggal'],
                    'id_shift' => $row['id_shift'],
                    'id_karyawan' => $row['id_karyawan'],
                    'id_mesin' => $row['id_mesin'],
                    'id_part_proses' => $row['id_part_proses'],
                ], $row['rincian']);
            }

            return $total;
        });

        /* batch_tersimpan adalah bukti sukses yang dibaca index.blade.php untuk
         * membuang draft di localStorage. Draft hanya boleh dibuang setelah
         * server MEMBUKTIKAN barisnya tersimpan — bukan cuma karena halaman
         * batch tidak melihat galat validasi, yang juga terjadi saat request
         * tidak pernah sampai sini (419 sesi habis, 500, jaringan putus). */
        return redirect()->route('input.dandori.index')
            ->with('success', "{$jumlah} baris data Dandori berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }
```

Di `routes/web.php`, di grup yang sama dengan `Route::resource('dandori', ...)` (baris 107), tambahkan sebelum baris resource itu:

```php
        Route::post('dandori/batch', [DandoriController::class, 'batch'])->name('dandori.batch');
```

Route `store` BELUM dicabut di task ini — dicabut di Task 3.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="DandoriBatchTest|DandoriDariLhpTest|DandoriFilterTest"`

Expected: PASS semua. `DandoriDariLhpTest` menguji jalur "Ada Dandori" di form LHP Produksi dan TIDAK boleh berubah.

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/StoreDandoriBatchRequest.php app/Http/Controllers/Input/DandoriController.php routes/web.php tests/Feature/Input/DandoriBatchTest.php
git commit -m "feat(dandori): endpoint batch input Dandori"
```

---

### Task 2: Halaman batch Dandori

`resources/views/input/dandori/create.blade.php` (580 baris) adalah halaman YATIM: route `input.dandori.create` hidup tapi nol view menautkannya — hanya `AuthorizationTest:152`. Diganti seluruhnya oleh halaman batch di posisi route yang sama.

**Files:**
- Rewrite: `resources/views/input/dandori/create.blade.php`
- Modify: `app/Http/Controllers/Input/DandoriController.php:163-172` (kalau datanya perlu ditambah), `resources/views/input/dandori/index.blade.php` (blok pembersih draft saja)
- Test: `tests/Feature/Input/HalamanBatchDandoriTest.php`

**Interfaces:**
- Consumes: route `input.dandori.batch` (Task 1); `batchDraft(cfg)` dan `partPicker(items, partsData)` dari layout; partial `input.partials.dandori-rows`.
- Produces: halaman di `input.dandori.create` dengan `<form id="formBaris">` dan `<form id="formBatch">` (field `rows_json`).

**Templat:** `resources/views/input/qc/create.blade.php` — konsumen `batchDraft` paling baru dan sudah lewat review. Ikuti susunannya; ganti isian per tabel di bawah.

| Field QC | Padanan Dandori |
|---|---|
| `tgl_pengecekan` + `tgl_produksi_part` | satu field `tanggal` (default `now()`) |
| `id_shift` (select) | sama |
| `id_karyawan_qc` + `taField(taInspector)` | `id_karyawan` + `taField(taOperator)`, label "Operator" |
| — | `id_mesin` + `taField(taMesin)` — Dandori punya mesin, QC tidak |
| `partPicker` + kotak info finish-good | `partPicker(taParts, partsData)` + **dropdown Proses** (`<select name="id_part_proses">`), karena Dandori memakai SEMUA proses, bukan cuma finish good |
| `qty_ok` | **tidak ada** |
| blok NG (`ada_ng` + `ng-rows`) | blok rincian: `@include('input.partials.dandori-rows', ['losstimes' => $losstimes, 'karyawan' => $karyawan])` — WAJIB ada minimal 1 rincian, jadi TIDAK pakai checkbox pembungkus seperti `ada_ng` |

⚠️ Dua hal yang gampang hilang:

1. **`<option>` proses wajib membawa `data-progressive`.** Itu jembatan yang membuat pratinjau Std memakai `std_menit_progressive`. Salin bentuknya dari `input/partials/dandori-rows.blade.php` dan dari laci di `input/dandori/index.blade.php`.
2. **Pemilih Proses wajib berada DI DALAM scope `x-data="partPicker(...)"`.** Halaman produksi pernah shipping dengan pemilih Proses di luar scope: setiap test render server lulus sementara halaman tidak bisa menyimpan satu baris pun di peramban, karena Alpine tidak bisa menjangkau `prosesList`. Uji ini secara struktural dengan DOMXPath, bukan substring — lihat `HalamanBatchQcTest::test_pemilih_part_berada_dalam_scope_part_picker`.

Kolom tabel draft: Tanggal, Shift, Operator, Mesin, Part No., Part Name, Proses, Rincian (ringkas: `JENIS ×N mnt`), Total Menit, tombol hapus.

Konfigurasi JS `konfigDandori()` mengikuti bentuk `konfigQc()`. Yang khas Dandori:

- `kunci: 'optima_draft_dandori_u{{ auth()->id() }}'`, `kunciBackup: 'optima_draft_dandori_terkirim_u{{ auth()->id() }}'`
- `wajib`: `tanggal`, `id_shift`, `id_karyawan`, `id_mesin`, `id_part_proses` (semua label Indonesia)
- `periksa(calon, rows, diri)`: tolak kalau `calon.rincian.length === 0` dengan pesan `'Minimal harus ada 1 aktivitas loss time.'`; tolak kalau ada rincian dengan `actual_menit` kosong atau `<= 0`. **Nol pemeriksaan tabrakan jam** — Dandori tidak punya jam start/finish.
- `pratinjau(calon, diri)`: hitung `total_menit` = jumlah `actual_menit`. Std/Over TIDAK dipratinjau per baris draft kecuali datanya sudah tersedia di browser; kalau ditampilkan, beri label yang menyatakan itu pratinjau dan server yang menghitung.
- `payload(r)`: kirim `tanggal`, `id_shift`, `id_karyawan`, `id_mesin`, `id_part_proses`, dan `rincian: [{id_losstime, actual_menit}]`. **JANGAN kirim `std_menit`/`over_menit`.**
- `resetField`: field rincian saja. Tanggal, shift, operator, dan mesin LENGKET — keempatnya konstan sepanjang satu sesi kerja.
- `resetLain(diri)`: kosongkan `partPicker`, dropdown proses, dan blok `.dandori-rows`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Input/HalamanBatchDandoriTest.php`, memakai `tests/Feature/Input/HalamanBatchQcTest.php` sebagai templat. Salin test mekanisnya dengan menukar nama route dan field: form mengirim ke endpoint batch, tabel draft + tombolnya ada, halaman memakai `x-data="batchDraft(`, guest ditolak, form mengirim `rows_json` bukan input per baris, `<div>` seimbang, kunci draft dipisah per pengguna (kedua literal — kunci utama DAN `kunciBackup`), dan kunci draft cocok dengan yang dihapus halaman daftar.

Empat yang khas Dandori, tulis lengkap:

```php
    public function test_pemilih_proses_berada_dalam_scope_part_picker(): void
    public function test_pilihan_proses_membawa_penanda_progressive(): void
    public function test_halaman_memuat_blok_rincian_dandori(): void
    public function test_halaman_tidak_mengirim_std_menit_dari_browser(): void
```

`test_pemilih_proses_berada_dalam_scope_part_picker` pakai DOMXPath: cari `//select[@name='id_part_proses']` lalu asersi ia punya `ancestor::*[contains(@x-data,'partPicker')]`.

`test_pilihan_proses_membawa_penanda_progressive` buat part dengan proses bernama `PROGRESSIVE` dan satu lagi bukan, lalu asersi hanya yang PROGRESSIVE membawa `data-progressive="1"`. ⚠️ Jangkarkan ke blok `<select name="id_part_proses">` atau ke `partsData`, JANGAN cari string `data-progressive` di seluruh HTML — kata itu juga muncul di komentar JS partial dandori, dan test yang sama pernah lolos karenanya.

`test_halaman_tidak_mengirim_std_menit_dari_browser` asersi `payload()` di `konfigDandori()` TIDAK memuat kunci `std_menit` maupun `over_menit`. Jangkarkan ke blok `payload(` di HTML terender.

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=HalamanBatchDandoriTest`

Expected: FAIL — halaman lama tidak punya `batchDraft`, `formBatch`, maupun tabel draft.

- [ ] **Step 3: Tulis halaman batch**

Tulis ulang `resources/views/input/dandori/create.blade.php` menurut templat dan tabel field di atas. Periksa `DandoriController::create()` — ia sudah mengirim `$shifts`, `$karyawan`, `$mesin`, `$parts` (dengan relasi `proses`), dan `$losstimes`. Tambah data hanya kalau halaman benar-benar butuh yang belum ada, dan katakan apa di laporan.

Konstanta JS yang disiapkan `@php` + `@json`, dipakai `konfigDandori()`: `taOperator`, `taMesin`, `taParts`, `partsData` (dengan `proses` sebagai ARRAY berisi `id`, `id_proses`, `nama_proses`), dan `ltLabel` untuk meringkas rincian di tabel draft.

- [ ] **Step 4: Tambah blok pembersih draft di halaman daftar**

Di `resources/views/input/dandori/index.blade.php`, di dalam blok `<script>` yang sudah ada, tambahkan (mencerminkan `input/qc/index.blade.php`):

```blade
@if (session('batch_tersimpan'))
(function() {
    /* Draft di localStorage cuma hidup di browser, jadi cuma boleh dibuang
       kalau server sudah MEMBUKTIKAN barisnya tersimpan — flash
       batch_tersimpan dari DandoriController::batch() adalah bukti itu.
       Kunci ditulis SAMA PERSIS dengan `kunci` dan `kunciBackup` di
       konfigDandori() (create.blade.php) supaya keduanya selalu menunjuk
       baris yang sama untuk pengguna yang sama. Dijaga
       HalamanBatchDandoriTest::test_kunci_draft_cocok_dengan_yang_dihapus_index. */
    try {
        localStorage.removeItem('optima_draft_dandori_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_dandori_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
```

⚠️ Blok ini WAJIB ada di task yang sama dengan halaman batchnya. Kalau halaman batch live tanpa blok ini, backup draft tidak pernah dibuang, `mulai()` memulihkan baris yang SUDAH tersimpan, dan satu klik menulisnya lagi ke `transaksi_dandori_rincian`. Nol unique constraint, nol error. Persis ini kejadian di QC dan baru tertangkap saat review.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="HalamanBatchDandoriTest|DandoriBatchTest|DandoriDariLhpTest"`

Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add resources/views/input/dandori/create.blade.php resources/views/input/dandori/index.blade.php app/Http/Controllers/Input/DandoriController.php tests/Feature/Input/HalamanBatchDandoriTest.php
git commit -m "feat(dandori): halaman batch input Dandori"
```

---

### Task 3: Cabut laci penulis, pensiunkan route `store`

**Files:**
- Modify: `resources/views/input/dandori/index.blade.php` (cabut laci penulis di baris 79 + plumbing matinya; tombol "Input Baru" jadi tautan)
- Modify: `app/Http/Controllers/Input/DandoriController.php` (hapus `store`), `routes/web.php:107`
- Modify: `tests/Feature/Input/DandoriFilterTest.php` dan test lain yang memakai `input.dandori.store`
- Delete: `app/Http/Requests/StoreDandoriRequest.php` — HANYA kalau nol pemakai tersisa

- [ ] **Step 1: Cabut laci penulis**

Buang laci di baris 79 beserta form penulisnya dan JS yang hanya melayaninya. **Laci NOTE di sekitar baris 791 TETAP**, begitu juga `erp-dock` filter dan form import. Ubah tombol "Input Baru" jadi `<a href="{{ route('input.dandori.create') }}">`, pertahankan gating perannya.

Setelah selesai, halaman harus menyisakan tepat SATU elemen `erp-drawer` (laci note). Catatan: `grep -c "erp-drawer"` mengembalikan 2 karena kelas `erp-drawer-hd` ikut cocok — itu wajar, bandingkan dengan `input/qc/index.blade.php` yang sudah beres.

⚠️ Blok pembersih `batch_tersimpan` yang ditambahkan di Task 2 harus tetap byte-identik.

⚠️ Buang plumbing yang jadi mati (variabel `@php`, `@json`, fungsi JS yang hanya dipakai laci penulis), tapi JANGAN sentuh variabel milik dock filter. Grep tiap kandidat sebelum menghapus — saat produksi melewati langkah ini, 94 baris plumbing mati lolos di pass pertama.

- [ ] **Step 2: Alihkan test yang memakai route `store`**

```bash
grep -rn "input.dandori.store" tests/
```

Ubah tiap pemanggilan jadi `post(route('input.dandori.batch'), ['rows' => [$payload]])` dan sarangkan kunci error-nya (`rincian` jadi `rows.0.rincian`). Test yang memakai `input.dandori.update` TIDAK berubah — form edit tidak disentuh.

Boleh mengubah asersi di sini, beda dari Task 1-2. Yang tetap dilarang: MELEMAHKAN asersi. Kalau sebuah test jadi tidak masuk akal di jalur batch, pindahkan cakupannya, jangan hapus diam-diam, dan katakan di laporan.

- [ ] **Step 3: Pensiunkan route dan method `store`**

Ubah `Route::resource('dandori', DandoriController::class)->only(['create', 'store', 'destroy'])` jadi `->only(['create', 'destroy'])`. Hapus `DandoriController::store()`.

```bash
grep -rn "StoreDandoriRequest\|input.dandori.store" app/ routes/ resources/ tests/
```

Kalau nol pemakai, hapus `app/Http/Requests/StoreDandoriRequest.php`.

⚠️ Periksa `tests/Feature/DateRangeGuardTest.php`. Kalau ia mengawasi `StoreDandoriRequest`, alihkan ke `StoreDandoriBatchRequest` dengan field bersarang (`rows.*.tanggal`). Melewatkan ini membuat penjaga tanggal mengawasi kelas mati sementara request LIVE tidak dijaga sama sekali — hampir kejadian di PR #114 dan benar-benar ada di PR #126. Dandori punya sejarah khusus di sini: tanggal `0026` pernah lolos ke prod (lihat `project_dandori_corrupt_dates`), jadi penjaga ini bukan formalitas.

⚠️ Setelah laci dicabut, periksa apakah halaman daftar masih merender `$errors`. Laci penulis biasanya satu-satunya perendernya, dan `import()` memvalidasi berkas ke bag default — tanpa perender, operator yang salah unggah kembali ke halaman yang tampak tidak berubah, nol pesan. Kejadian di QC (PR #126). Kalau hilang, tambahkan bloknya beserta test yang mengasersi pesannya BENAR-BENAR sampai ke halaman.

- [ ] **Step 4: Jalankan seluruh suite**

Run: `php artisan test`

Expected: PASS. Laporkan jumlahnya dan jelaskan tiap selisih.

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app tests
git add -A
git commit -m "feat(dandori): laci penulis dicabut, route store pensiun"
```

---

### Task 4: Gerbang akhir & PR

- [ ] **Step 1: Seluruh suite** — `php artisan test`, PASS.
- [ ] **Step 2: Cakupan migrasi** — `./scripts/check-migration-coverage.sh`, angka tidak berubah (nol migrasi).
- [ ] **Step 3: Format** — `vendor/bin/pint app tests`, passed.
- [ ] **Step 4: Periksa sisa**

```bash
grep -rn "input.dandori.store" app/ routes/ resources/
```
Expected: nol hasil di luar komentar.

```bash
grep -rn "erp-drawer\"" resources/views/input/dandori/index.blade.php
```
Expected: tepat satu — laci note.

- [ ] **Step 5: Buka PR**

```bash
git push -u origin feat/batch-dandori
```

Judul: `feat(dandori): halaman batch input Dandori`

Isi PR menyebut: (a) PR 3 dari 4 di spec `2026-08-27-batch-input-empat-modul-design.md`; (b) nol service baru — `DandoriRincianService` dipakai apa adanya; (c) satu baris draft = satu sesi = N baris tabel, dan flash `batch_tersimpan` menghitung baris tabel; (d) `std_menit`/`over_menit` tetap dihitung server, browser tidak pernah mengirimnya; (e) halaman yatim `input.dandori.create` diganti isinya; (f) nol migrasi; (g) apakah `cfg` cukup atau ada yang harus dipaksakan — jawabannya menentukan apakah PR 4 perlu menambah seam.

---

## Catatan untuk PR 4

Dandori adalah konsumen ketiga `batchDraft`. Kalau `cfg` masih terasa pas di sini, seam `cfg.komponen` yang ditunda di spec §1 boleh tetap ditunda. Kalau `resetLain` mulai harus tahu terlalu banyak soal isi halaman, catat di PR — PR 4 (Breakdown) sudah pasti butuh satu seam baru (`cfg.setelahPulih`, spec §2.3) untuk melanjutkan `nomor_urut` setelah draft dipulihkan, jadi keduanya bisa dikerjakan sekaligus di sana.
