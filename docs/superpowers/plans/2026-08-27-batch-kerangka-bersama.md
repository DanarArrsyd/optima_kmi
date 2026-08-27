# Kerangka Batch Bersama (PR 1) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Naikkan kerangka halaman batch (`batchDraft`) dan pemilih part (`partPicker`) dari `input/produksi/create.blade.php` ke `layouts/app.blade.php`, lalu buat halaman produksi memakainya — dengan nol perubahan perilaku.

**Architecture:** Alpine.js dipakai lewat CDN tanpa build step. Komponen bersama adalah fungsi global biasa yang didefinisikan di satu blok `<script>` di layout (baris 1388–1770) dan menerima datanya lewat argumen; `@stack('scripts')` ada di baris 1807, jadi skrip halaman selalu berjalan setelah definisi layout dan boleh memanggilnya. `batchDraft(cfg)` memegang bagian yang generik (draft `localStorage`, backup, pembacaan form, tombol, payload) dan menerima bagian modul lewat objek konfigurasi.

**Tech Stack:** Laravel 13, Blade, Alpine.js 3.14.1 (CDN), Bootstrap 5.3.3, PHPUnit 12 (`php artisan test`, SQLite in-memory), Pint.

## Global Constraints

- PHP lokal 8.5.4, **CI & prod 8.4** — jangan pakai sintaks yang hanya ada di 8.5.
- **Nol migrasi** di PR ini. `.github/workflows/deploy.yml` tidak boleh berubah.
- **Nol perubahan perilaku.** `tests/Feature/Input/HalamanBatchProduksiTest.php` dan `tests/Feature/Input/ProduksiBatchTest.php` yang sudah ada harus hijau **tanpa disunting**. Kalau salah satunya perlu diubah agar lolos, itu tanda ekstraksinya mengubah perilaku — batalkan, jangan sunting testnya.
- Nol entri sidebar baru; `config/sidebar.php` tidak disentuh.
- Scope Pint = `app/` + `tests/` saja. Berkas Blade tidak diformat Pint.
- Komponen bersama menerima data lewat **argumen**, tidak membaca variabel global halaman — ikuti pola `taField(items, initText, initVal)` di layout.
- Bahasa komentar dan pesan galat: Indonesia, sama seperti berkas di sekitarnya.

---

### Task 1: `partPicker()` naik ke layout

Hari ini `partPicker()` didefinisikan di dalam `@push('scripts')` halaman produksi dan membaca dua konstanta global halaman (`taParts`, `partsData`). Halaman batch QC nanti butuh yang sama. Naikkan ke layout dengan data lewat argumen.

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1704` (sisipkan setelah `saringOrangNonaktif()` berakhir, sebelum `function sbToggle()`)
- Modify: `resources/views/input/produksi/create.blade.php:393-421` (hapus definisi lokal), `:170` (ubah pemanggilan `x-data`)
- Test: `tests/Feature/Layout/KomponenBersamaTest.php:22-28`

**Interfaces:**
- Consumes: —
- Produces: fungsi global `partPicker(items, partsData)` → objek Alpine dengan properti `query` (string), `val` (id part atau null), `open` (bool), `idx` (number), `partNo` (string), `partName` (string), `prosesList` (array proses), dan metode `tersaring` (getter), `pilih(item)`, `tutup()`, `tombol(e)`. Bentuk `items`: `[{ v: id, t: 'PART-NO — Part Name' }]`. Bentuk `partsData`: `[{ id, part_no, part_name, proses: [{ id, id_proses, nama_proses, sph_standar_produksi }] }]`.

- [ ] **Step 1: Tulis test yang gagal**

Di `tests/Feature/Layout/KomponenBersamaTest.php`, tambahkan satu baris ke konstanta `KOMPONEN`:

```php
    /** Komponen yang definisinya dikunci di layouts/app.blade.php. */
    private const KOMPONEN = [
        'function taField(',
        'function filterSearchComp(',
        'function partGroupComp(',
        'function erpToggleDock(',
        'function saringOrangNonaktif(',
        'function partPicker(',
    ];
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=KomponenBersamaTest`

Expected: FAIL. Dua asersi jatuh untuk data set `'function partPicker('` — `function partPicker( harus didefinisikan di layouts/app.blade.php`, dan daftar penyalin berisi `input/produksi/create.blade.php`.

- [ ] **Step 3: Sisipkan `partPicker()` di layout**

Di `resources/views/layouts/app.blade.php`, tepat setelah baris 1704 (kurung tutup `saringOrangNonaktif`) dan sebelum `function sbToggle()`, sisipkan:

```js

/* ══════════════════════════════════════════════════════════════════
   partPicker — ketik-cari part yang sekaligus mengisi Part No, Part
   Name, dan daftar prosesnya.

   Beda dari taField: taField hanya mengembalikan satu nilai, sementara
   yang ini punya muatan turunan (partNo/partName/prosesList) yang harus
   ikut berubah tiap kali pilihan berganti — dan ikut dikosongkan saat
   pilihan dibatalkan, kalau tidak Part No lama menggantung di layar
   sementara val sudah null.

   Data masuk lewat argumen, bukan dibaca dari konstanta global halaman,
   supaya halaman mana pun bisa memakainya (lihat taField di atas).
   ══════════════════════════════════════════════════════════════════ */
function partPicker(items, partsData) {
    return {
        items, partsData,
        query: '', val: null, open: false, idx: -1,
        partNo: '', partName: '', prosesList: [],

        get tersaring() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pilih(item) {
            this.query = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part = this.partsData.find(p => p.id == item.v);
            this.partNo     = part ? part.part_no : '';
            this.partName   = part ? part.part_name : '';
            this.prosesList = part ? part.proses : [];
        },
        tutup() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = ''; this.partNo = ''; this.partName = '';
                    this.prosesList = [];
                }
            }, 180);
        },
        tombol(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx + 1, this.tersaring.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.idx = Math.max(this.idx - 1, 0); }
            else if (e.key === 'Enter') { e.preventDefault(); if (this.idx >= 0 && this.tersaring[this.idx]) this.pilih(this.tersaring[this.idx]); }
            else if (e.key === 'Escape') { this.open = false; }
        },
    };
}
```

- [ ] **Step 4: Hapus definisi lokal dan ubah pemanggilannya**

Di `resources/views/input/produksi/create.blade.php`, hapus seluruh blok komentar `/* Part memilih part sekaligus mengisi Part No dan daftar prosesnya. */` beserta `function partPicker() { ... }` di bawahnya (baris 393–421).

Lalu ubah baris 170 dari:

```blade
                <div x-data="partPicker()">
```

menjadi:

```blade
                <div x-data="partPicker(taParts, partsData)">
```

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="KomponenBersamaTest|HalamanBatchProduksiTest|ProduksiBatchTest"`

Expected: PASS semua. `HalamanBatchProduksiTest` dan `ProduksiBatchTest` tidak boleh disunting sedikit pun.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/input/produksi/create.blade.php tests/Feature/Layout/KomponenBersamaTest.php
git commit -m "refactor(layout): partPicker jadi komponen bersama"
```

---

### Task 2: `batchDraft()` naik ke layout, produksi memakainya

Ini inti PR-nya. `lhpBatch()` sekarang mencampur kerangka draft dengan isian LHP. Pisahkan: kerangka ke layout, isian jadi objek konfigurasi di halaman.

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (sisipkan setelah `partPicker()` dari Task 1)
- Modify: `resources/views/input/produksi/create.blade.php:79` (`x-data`), `:422-698` (ganti `lhpBatch()` jadi konfigurasi)
- Test: `tests/Feature/Layout/KomponenBersamaTest.php:22-29`, `tests/Feature/Input/HalamanBatchProduksiTest.php`

**Interfaces:**
- Consumes: `partPicker(items, partsData)` dari Task 1.
- Produces: fungsi global `batchDraft(cfg)` → objek Alpine. Bentuk `cfg`:

  | Kunci | Tipe | Wajib | Arti |
  |---|---|---|---|
  | `kunci` | string | ya | kunci `localStorage` LENGKAP, dirakit oleh Blade di halaman modul, mis. `'optima_draft_lhp_produksi_u{{ auth()->id() }}'` — bukan nama dasar yang dirakit di JS |
  | `kunciBackup` | string | ya | kunci `localStorage` cadangan, sama polanya, mis. `'optima_draft_lhp_produksi_terkirim_u{{ auth()->id() }}'` |
  | `formId` | string | ya | id elemen `<form>` baris, mis. `'formBaris'` |
  | `indexUrl` | string | ya | tujuan tombol CANCEL |
  | `galatServer` | array<string> | ya | `$errors->keys()`, dipakai menandai baris yang ditolak |
  | `kumpulkan` | `(diri) => object` | ya | baca form, hasilkan objek baris (tanpa `uid`) |
  | `wajib` | `{ field: 'Label' }` | ya | field yang tidak boleh kosong |
  | `periksa` | `(calon, rows, diri) => string\|null` | tidak | pemeriksaan antar-baris; kembalikan pesan galat atau `null` |
  | `pratinjau` | `(calon, diri) => void` | tidak | isi kolom hitungan pratinjau pada `calon` |
  | `payload` | `(r) => object` | ya | bentuk satu baris yang dikirim server |
  | `resetField` | array<string> | ya | `name` field yang dikosongkan setelah baris masuk draft |
  | `resetLain` | `(diri) => void` | tidak | pembersihan yang tidak bisa diungkapkan lewat `resetField` |
  | `metode` | object | tidak | metode tambahan yang disebar ke objek Alpine (mis. `ringkasNg`) |

  Metode yang tersedia untuk halaman: `mulai()`, `form()`, `nilai(nama)`, `teksTerpilih(nama)`, `blok(kelas)`, `detik(jam)`, `durasiDetik(mulai, selesai)`, `simpanKeDraft()`, `resetSebagian()`, `hapus(i)`, `payloadRows()`, `batal()`, `pindahkanKeBackup()`. State: `rows`, `pesan`, `galat`, `barisGagal`.

  **Kenapa `kunci`/`kunciBackup` dirakit oleh Blade, bukan di runtime JS:**
  merakit di JS (mis. `` `optima_draft_${cfg.kunci}_u${cfg.userId}` ``) membuat
  literal kunci lengkap berhenti muncul di HTML yang dikirim server — dan
  `HalamanBatchProduksiTest` menjangkar ke literal itu di response mentah.
  Versi runtime-composed sempat dicoba di branch ini dan dua test penjaga
  tetap HIJAU walau kuncinya salah rakit, murni karena sebuah *komentar* di
  Blade kebetulan memuat teks kunci yang sama — assertion-nya lolos tanpa
  benar-benar memeriksa kunci yang dipakai `localStorage`. Karena itu `cfg`
  sekarang mewajibkan string kunci yang SUDAH utuh.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan penjaga satu-definisi di `tests/Feature/Layout/KomponenBersamaTest.php`:

```php
    /** Komponen yang definisinya dikunci di layouts/app.blade.php. */
    private const KOMPONEN = [
        'function taField(',
        'function filterSearchComp(',
        'function partGroupComp(',
        'function erpToggleDock(',
        'function saringOrangNonaktif(',
        'function partPicker(',
        'function batchDraft(',
    ];
```

Lalu tambahkan test di `tests/Feature/Input/HalamanBatchProduksiTest.php` yang membuktikan halaman produksi benar-benar memakai kerangka bersama itu — bukan sekadar kerangkanya ada di layout:

```php
    public function test_halaman_batch_memakai_kerangka_bersama(): void
    {
        // Kerangka draft (localStorage, KUNCI_BACKUP, penjaga bfcache) hidup di
        // layout supaya empat modul berikutnya tidak menyalinnya. Kalau halaman
        // ini diam-diam kembali punya kerangka sendiri, salinan itu akan
        // menyimpang tanpa ada yang tahu — persis riwayat taField.
        $html = $this->halaman()->getContent();

        $this->assertStringContainsString('x-data="batchDraft(', $html);
        $this->assertStringNotContainsString('function lhpBatch(', $html);
    }
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter="KomponenBersamaTest|test_halaman_batch_memakai_kerangka_bersama"`

Expected: FAIL. `function batchDraft( harus didefinisikan di layouts/app.blade.php`, dan `test_halaman_batch_memakai_kerangka_bersama` gagal di asersi pertama karena halaman masih memakai `x-data="lhpBatch()"`.

- [ ] **Step 3: Sisipkan `batchDraft()` di layout**

Di `resources/views/layouts/app.blade.php`, tepat setelah kurung tutup `partPicker()` yang ditambahkan di Task 1, sisipkan:

```js

/* ══════════════════════════════════════════════════════════════════
   batchDraft — kerangka halaman input batch: form satu baris di atas,
   tabel draft di bawah, satu SUBMIT mengirim semuanya.

   Yang ADA di sini adalah yang nol hubungannya dengan modul mana pun:
   penyimpanan draft, pemulihan setelah submit ditolak, pembacaan form,
   dan penjaga pengiriman ganda. Empat hal itu justru yang paling mahal
   kalau salah, dan mode gagalnya senyap — draft hilang tidak melempar
   error, cuma menyisakan tabel kosong yang operator kira memang belum
   diisi. Karena itu dia hidup satu kali di sini, bukan disalin per modul.

   Yang TIDAK ada di sini: field apa yang dikumpulkan, mana yang wajib,
   pemeriksaan antar-baris, dan bentuk payload. Itu semua masuk lewat cfg.

   Kunci localStorage (cfg.kunci, cfg.kunciBackup) sengaja disuplai UTUH oleh
   tiap modul, bukan dirakit di sini dari potongan (mis. id pengguna). Layout
   ini murni JS generik tanpa akses ke Blade/auth, dan merakit kunci di JS
   runtime membuat literalnya berhenti muncul di HTML server — dua test
   pre-existing yang menjangkar ke literal kunci itu butuh teksnya utuh di
   response mentah. Nilai kedua kunci HARUS sama persis dengan yang dihapus
   halaman daftar modul itu (lihat komentar di batal() dan pindahkanKeBackup()
   di bawah) — kunci yang tidak cocok berarti draft/backup jadi yatim,
   tersimpan di browser tapi tidak pernah lagi dibaca atau dibersihkan.
   ══════════════════════════════════════════════════════════════════ */
function batchDraft(cfg) {
    /* Kunci dipisah per pengguna: komputer di lantai produksi dipakai
       bergantian, dan kunci bersama membuat draft operator A muncul di
       layar operator B. Modul pemanggil yang menyusun id pengguna ke dalam
       cfg.kunci (lihat konfigLhp() di create.blade.php) — lihat catatan di
       atas soal kenapa perakitannya di Blade, bukan di sini. */
    const KUNCI = cfg.kunci;

    /* Kunci cadangan: SUBMIT DRAFT adalah POST form biasa yang berakhir di
       redirect server, jadi tidak ada kesempatan JS menghapus KUNCI tepat
       saat sukses. Sebaliknya, submit memindahkan isi KUNCI ke sini dulu
       (lihat pindahkanKeBackup()) lalu mengosongkan KUNCI.
       Draft di sini hanya hidup di browser, jadi hanya boleh dibuang kalau
       server sudah MEMBUKTIKAN baris-barisnya tersimpan — bukan sekadar
       ketiadaan galat validasi di halaman ini. Bukti itu adalah flash
       batch_tersimpan yang dibaca dan dieksekusi di halaman daftar:
       halaman daftar-lah yang menghapus KUNCI_BACKUP ini.
       Kalau operator kembali ke SINI dan backup masih ada — baik karena
       galat validasi, 419 sesi habis, 500 di server, maupun jaringan
       putus sebelum POST-nya mendarat — submit itu belum terbukti sukses,
       jadi mulai() memulihkannya, bukan membuangnya. */
    const KUNCI_BACKUP = cfg.kunciBackup;
    let seq = 0;

    return {
        /* Spread cfg.metode di PALING AWAL supaya kerangka selalu menang atas
           cfg.metode — baik state maupun method. Dalam object literal JS,
           kunci yang datang belakangan menang atas kunci yang sama dari
           sebaran sebelumnya. Empat modul akan memakai batchDraft(); kalau
           cfg.metode modul kebetulan mendefinisikan nama yang sama dengan
           state atau method kerangka (mis. "rows", "pesan", "mulai", atau
           "hapus"), kerangka WAJIB tetap yang menang — bukan tertimpa senyap
           oleh cfg.metode. */
        ...(cfg.metode || {}),

        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],

        mulai() {
            /* Halaman batch yang dipulihkan dari bfcache (tombol Back setelah
               submit sukses) masih menampilkan baris-baris yang SUDAH tersimpan
               di server — x-init "mulai()" tidak jalan lagi karena Alpine tidak
               di-reinit, dan tombol SUBMIT tetap aktif. Mengirim ulang baris
               yang sama menulis data dobel; tidak ada unique constraint yang
               mencegahnya. Muat ulang supaya mulai() benar-benar jalan lagi dan
               memutuskan nasib draft dari kondisi server yang sebenarnya.

               Didaftarkan DI SINI, bukan di lingkup layout: layout dimuat semua
               halaman, dan memaksa reload bfcache di halaman yang tidak punya
               draft adalah perubahan perilaku yang tidak diminta siapa pun. */
            window.addEventListener('pageshow', (e) => {
                if (e.persisted) window.location.reload();
            });

            /* Selesaikan nasib KUNCI_BACKUP (kalau ada) SEBELUM membaca KUNCI,
               supaya restorasi normal di bawah otomatis mengambil hasilnya.
               Sampai di SINI berarti halaman daftar belum sempat membuktikan
               sukses (lihat komentar di deklarasi KUNCI_BACKUP) — jadi backup
               SELALU dipulihkan, tanpa syarat ada/tidaknya galat validasi. */
            try {
                const backupMentah = localStorage.getItem(KUNCI_BACKUP);
                if (backupMentah !== null) {
                    localStorage.setItem(KUNCI, backupMentah);
                    localStorage.removeItem(KUNCI_BACKUP);
                }
            } catch (e) {
                // localStorage tak bisa dibaca/ditulis; lewati saja.
            }

            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah) {
                    this.rows = JSON.parse(mentah).map(r => ({ ...r, uid: seq++ }));
                    if (this.rows.length) this.pesan = `${this.rows.length} baris draft dipulihkan.`;
                }
            } catch (e) {
                localStorage.removeItem(KUNCI);
            }
            this.$watch('rows', () => localStorage.setItem(KUNCI, JSON.stringify(this.rows)));

            /* Server menolak batch → tandai barisnya, draft JANGAN dibuang. */
            this.barisGagal = (cfg.galatServer || [])
                .map(k => (k.match(/^rows\.(\d+)\./) || [])[1])
                .filter(v => v !== undefined)
                .map(Number);
        },

        form() { return document.getElementById(cfg.formId); },

        nilai(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            return el ? el.value : '';
        },

        teksTerpilih(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            if (!el || el.tagName !== 'SELECT') return '';
            return el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : '';
        },

        blok(kelas) {
            const el = this.form().querySelector(kelas);
            return el ? Alpine.$data(el) : null;
        },

        detik(jam) {
            const [h, m] = (jam || '0:0').split(':').map(Number);
            return (h * 3600) + (m * 60);
        },

        durasiDetik(mulai, selesai) {
            const a = this.detik(mulai), b = this.detik(selesai);
            return b >= a ? b - a : (86400 - a) + b;
        },

        simpanKeDraft() {
            this.galat = '';

            const calon = { uid: seq++, ...cfg.kumpulkan(this) };

            for (const [kunci, label] of Object.entries(cfg.wajib)) {
                if (!calon[kunci]) { this.galat = `${label} wajib diisi.`; return; }
            }

            const galat = cfg.periksa ? cfg.periksa(calon, this.rows, this) : null;
            if (galat) { this.galat = galat; return; }

            if (cfg.pratinjau) cfg.pratinjau(calon, this);

            this.rows.push(calon);
            this.pesan = '';
            this.barisGagal = [];
            this.resetSebagian();
        },

        /* Field yang TIDAK disebut cfg.resetField sengaja dipertahankan:
           biasanya tanggal, shift, dan orang — ketiganya konstan sepanjang
           satu sesi kerja, dan mengetik ulang tiap baris bikin operator
           berhenti memakai halaman ini. */
        resetSebagian() {
            const f = this.form();
            (cfg.resetField || []).forEach(n => {
                const el = f.querySelector(`[name="${n}"]`);
                if (el) el.value = '';
            });

            if (cfg.resetLain) cfg.resetLain(this);
        },

        hapus(i) { this.rows.splice(i, 1); this.barisGagal = []; },

        payloadRows() { return this.rows.map(r => cfg.payload(r)); },

        batal() {
            if (this.rows.length && !confirm(`Buang ${this.rows.length} baris draft yang belum dikirim?`)) return;
            localStorage.removeItem(KUNCI);
            window.location = cfg.indexUrl;
        },

        /* Dipasang di @submit form batch. Form itu POST biasa (bukan
           @submit.prevent) — begitu handler ini selesai, browser lanjut
           mengirim form dan halaman ini hilang sebelum JS lain sempat jalan.
           Jadi draft TIDAK bisa langsung dihapus di sini: kalau server nanti
           menolak batch (galat validasi), operator akan diarahkan balik ke
           halaman ini dan berhak mendapatkan ketikannya kembali. Sebagai
           gantinya draft dipindah ke KUNCI_BACKUP dan KUNCI dikosongkan;
           mulai() yang membereskan nasib akhirnya saat halaman dibuka lagi. */
        pindahkanKeBackup() {
            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah !== null) {
                    /* Kalau setItem(KUNCI_BACKUP) di bawah gagal (mis. kuota
                       localStorage penuh), KUNCI tetap WAJIB dikosongkan di
                       finally — kalau tidak, draft yang baru saja berhasil
                       disimpan ke server akan ditawarkan lagi ke operator dan
                       terkirim dobel. */
                    try {
                        localStorage.setItem(KUNCI_BACKUP, mentah);
                    } finally {
                        localStorage.removeItem(KUNCI);
                    }
                }
            } catch (e) {
                // localStorage tak tersedia; biarkan submit tetap jalan apa adanya.
            }
        },
    };
}
```

- [ ] **Step 4: Ubah halaman produksi jadi konfigurasi**

Di `resources/views/input/produksi/create.blade.php`, ubah baris 79 dari:

```blade
<div class="erp-panel" x-data="lhpBatch()" x-init="mulai()">
```

menjadi:

```blade
<div class="erp-panel" x-data="batchDraft(konfigLhp())" x-init="mulai()">
```

Lalu di dalam `@push('scripts')`, hapus penjaga `pageshow` (sudah pindah ke layout) dan ganti seluruh `function lhpBatch() { ... }` dengan:

```js
function konfigLhp() {
    /* Rentang mutlak, supaya baris yang melewati tengah malam ikut benar.
       durasiDetik() diambil dari batchDraft lewat `diri`, tidak disalin ke
       sini — menyalin pembantu waktu adalah awal dari dua definisi yang
       pelan-pelan menyimpang. */
    const rentang = (diri, r) => {
        const mulai = Date.parse(`${r.tanggal_produksi}T${r.jam_start}:00`);
        return { mulai, selesai: mulai + (diri.durasiDetik(r.jam_start, r.jam_finish) * 1000) };
    };

    const picker = () => Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhp_produksi_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhp_produksi_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.produksi.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tanggal_produksi: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
            id_mesin: 'Mesin', id_part_proses: 'Proses', jam_start: 'Jam Start',
            jam_finish: 'Jam Finish', qty_aktual: 'Qty OK',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                tanggal_produksi: diri.nilai('tanggal_produksi'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan: diri.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == diri.nilai('id_karyawan')) || {}).t || '',
                id_mesin: diri.nilai('id_mesin'),
                mesin_teks: (taMesin.find(m => m.v == diri.nilai('id_mesin')) || {}).t || '',
                id_part_proses: diri.nilai('id_part_proses'),
                proses_teks: diri.teksTerpilih('id_part_proses'),
                part_no: p.partNo,
                part_name: p.partName,
                jam_start: diri.nilai('jam_start'),
                jam_finish: diri.nilai('jam_finish'),
                qty_aktual: diri.nilai('qty_aktual'),
                ng: (diri.blok('.ng-rows') || { rows: [] }).rows.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
                dandori: (diri.blok('.dandori-rows') || { rows: [] }).rows.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
                dandori_karyawan_id: diri.nilai('dandori_karyawan_id'),
            };
        },

        periksa(calon, rows, diri) {
            if (Number(calon.qty_aktual) < 1) return 'Qty OK minimal 1.';
            if (diri.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                return 'Jam Finish tidak boleh sama dengan Jam Start.';
            }

            for (let i = 0; i < rows.length; i++) {
                if (rows[i].id_mesin != calon.id_mesin) continue;
                const a = rentang(diri, rows[i]), b = rentang(diri, calon);
                if (a.mulai < b.selesai && b.mulai < a.selesai) {
                    return `Jam tumpang tindih dengan baris ${i + 1} pada mesin dan tanggal yang sama.`;
                }
            }

            return null;
        },

        /* Pratinjau; server yang menghitung angka yang tersimpan. */
        pratinjau(calon, diri) {
            const jamKerja = diri.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_produksi : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        /* Tanggal, Shift, Operator, dan Mesin sengaja TIDAK di sini:
           keempatnya konstan sepanjang satu sesi kerja. */
        resetField: ['jam_start', 'jam_finish', 'qty_aktual'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses"]');
            if (proses) proses.value = '';

            ['ada_ng', 'ada_dandori'].forEach(id => {
                const cb = document.getElementById(id);
                if (!cb) return;
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                Alpine.$data(cb.closest('[x-data]'))[id === 'ada_ng' ? 'adaNg' : 'adaDandori'] = false;
            });

            const ng = diri.blok('.ng-rows'); if (ng) ng.rows = [];
            const dandori = diri.blok('.dandori-rows'); if (dandori) dandori.rows = [];
        },

        /* Bentuk field yang dikirim ke server (rows_json) — sama persis
           dengan yang dulu dirakit lewat input tersembunyi per baris,
           termasuk ng_count/dandori_count yang dihitung terpisah dari
           panjang array ng/dandori (lihat komentar periksaHitungan() di
           StoreProduksiBatchRequest soal kenapa itu penting). */
        payload(r) {
            return {
                tanggal_produksi: r.tanggal_produksi,
                id_shift: r.id_shift,
                id_karyawan: r.id_karyawan,
                id_mesin: r.id_mesin,
                id_part_proses: r.id_part_proses,
                jam_start: r.jam_start,
                jam_finish: r.jam_finish,
                qty_aktual: r.qty_aktual,
                ng_count: r.ng.length,
                ada_ng: r.ng.length > 0 ? 1 : 0,
                ng: r.ng.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
                dandori_count: r.dandori.length,
                ada_dandori: r.dandori.length > 0 ? 1 : 0,
                dandori_karyawan_id: r.dandori_karyawan_id,
                dandori: r.dandori.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
            };
        },

        metode: {
            ringkasNg(r) {
                if (!r.ng.length) return '—';
                return r.ng.map(n => `${ngLabel[n.id_jenis_ng] ?? n.id_jenis_ng} ×${n.qty}`).join(', ');
            },

            ringkasDandori(r) {
                if (!r.dandori.length) return '—';
                return r.dandori.map(d => `${ltLabel[d.id_losstime] ?? d.id_losstime} ${d.actual_menit}mnt`).join(', ');
            },
        },
    };
}
```

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="KomponenBersamaTest|HalamanBatchProduksiTest|ProduksiBatchTest|ProduksiWriteServiceTest|NgMultiJenisProduksiTest|DandoriDariLhpTest"`

Expected: PASS semua. Kalau `test_pemilih_proses_berada_dalam_scope_part_picker` atau `test_div_tags_seimbang_di_halaman_batch` merah, itu markup yang tergeser saat menghapus blok — perbaiki markupnya, jangan testnya.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/input/produksi/create.blade.php tests/Feature/Layout/KomponenBersamaTest.php tests/Feature/Input/HalamanBatchProduksiTest.php
git commit -m "refactor(layout): kerangka batchDraft jadi komponen bersama"
```

---

### Task 3: Gerbang akhir & PR

**Files:**
- Tidak ada berkas baru. Hanya menjalankan gerbang dan membuka PR.

**Interfaces:**
- Consumes: hasil Task 1 dan Task 2.
- Produces: PR yang siap di-review. Tidak ada simbol baru.

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`

Expected: PASS. Jumlah test = jumlah sebelum PR ini + 1 (`test_halaman_batch_memakai_kerangka_bersama`) + 2 data set baru di `KomponenBersamaTest` (`partPicker`, `batchDraft`). Per 2026-08-27 dasarnya 1013, jadi harapkan 1016.

- [ ] **Step 2: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`

Expected: `✓ Cakupan migrasi lengkap: 54 file — 14 dijalankan saat deploy, 40 baseline.` Angkanya tidak boleh berubah — PR ini nol migrasi.

- [ ] **Step 3: Format**

Run: `vendor/bin/pint app tests`

Expected: `{"tool":"pint","result":"passed"}`. Berkas Blade di luar scope Pint, jadi tidak ikut tersentuh.

- [ ] **Step 4: Periksa nol definisi tersisa**

Run: `grep -rn "function lhpBatch(\|function partPicker(\|function batchDraft(" resources/views/`

Expected: tepat dua baris, keduanya di `resources/views/layouts/app.blade.php` — `function partPicker(` dan `function batchDraft(`. Nol `lhpBatch`.

- [ ] **Step 5: Buka PR**

```bash
git push -u origin refactor/kerangka-batch-bersama
```

Judul PR: `refactor(layout): kerangka batch jadi komponen bersama`

Isi PR harus menyebut: (a) ini nol perubahan perilaku, (b) `HalamanBatchProduksiTest` dan `ProduksiBatchTest` lama hijau tanpa disunting sebagai buktinya, (c) nol migrasi, (d) ini PR 1 dari 4 di `docs/superpowers/specs/2026-08-27-batch-input-empat-modul-design.md`.

---

## Catatan untuk PR berikutnya

PR 2 (Quality) adalah pemakai pertama kerangka ini dari nol. Kalau saat menulisnya ternyata ada bagian `cfg` yang terasa dipaksakan — mis. `resetLain` harus tahu terlalu banyak soal isi halaman — itu sinyal kerangkanya salah bentuk, dan memperbaikinya di PR 2 masih murah karena baru satu modul menumpang. Menemukan hal yang sama di PR 4 berarti membongkar empat halaman.
