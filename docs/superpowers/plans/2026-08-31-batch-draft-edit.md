# Edit Baris Draft — Batch Input (5 Modul) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah tombol EDIT di tabel "DRAFT TERSIMPAN" pada 5 halaman batch input
(LHP Produksi, QC, Dandori, Breakdown Mesin, Breakdown Dies), lewat satu perubahan
di kerangka bersama `batchDraft()` plus `cfg.sebar()` per modul.

**Architecture:** `batchDraft` (kerangka JS di `resources/views/layouts/app.blade.php`)
dapat state baru `editIndex` dan method `edit(i)`/`batalEdit()`; `simpanKeDraft()`
dan `hapus(i)` diubah supaya sadar mode edit. Tiap modul menulis `cfg.sebar(row, diri)`
— fungsi baru, mirror `cfg.kumpulkan(diri)` tapi arah sebaliknya: baca `row`, tulis ke
DOM form + komponen Alpine (taField/partPicker/ng-rows/dandori-rows).

**Tech Stack:** Blade, Alpine.js 3.14 (tanpa build step — script inline `@push('scripts')`), nol perubahan PHP/server.

## Global Constraints

- Nol endpoint server baru, nol perubahan `StoreXBatchRequest` mana pun (lihat spec).
- Nol reorder baris — baris tetap di posisi asal setelah UPDATE BARIS.
- Nol test PHPUnit otomatis untuk perilaku ini — repo tidak punya Dusk/harness JS
  (`grep -i dusk composer.json package.json` kosong). Verifikasi tiap task lewat
  browser manual (`preview_start` + `computer`/`read_page`), bukan `php artisan test`.
- Spec: `docs/superpowers/specs/2026-08-31-batch-draft-edit-design.md`

---

### Task 1: Kerangka bersama — state edit di `batchDraft()`

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1873` (daftar `cfg` wajib)
- Modify: `resources/views/layouts/app.blade.php:1910-1913` (state awal)
- Modify: `resources/views/layouts/app.blade.php:2007-2025` (`simpanKeDraft`)
- Modify: `resources/views/layouts/app.blade.php:2041` (`hapus`)

**Interfaces:**
- Produces: `editIndex` (null | number) — dibaca semua 5 template modul.
  `edit(i)` — dipanggil tombol EDIT di tabel. `batalEdit()` — dipanggil tombol
  "Batal Edit". `cfg.sebar(row, diri)` — kontrak baru, WAJIB diisi tiap modul
  (mirror `cfg.kumpulkan`), dipanggil dari `edit(i)`.

- [ ] **Step 1: Tambah `sebar` ke daftar cfg wajib**

Baris 1873 saat ini:
```js
for (const k of ['kunci', 'kunciBackup', 'formId', 'indexUrl', 'kumpulkan', 'wajib', 'payload', 'galatServer']) {
```
Ganti jadi:
```js
for (const k of ['kunci', 'kunciBackup', 'formId', 'indexUrl', 'kumpulkan', 'wajib', 'payload', 'galatServer', 'sebar']) {
```

- [ ] **Step 2: Tambah state `editIndex`**

Baris 1910-1913 saat ini:
```js
        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],
```
Ganti jadi:
```js
        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],
        editIndex: null,
```

- [ ] **Step 3: `simpanKeDraft()` sadar mode edit**

Baris 2007-2025 saat ini:
```js
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
```
Ganti jadi:
```js
        simpanKeDraft() {
            this.galat = '';

            /* Mode edit memakai uid baris lama supaya :key template x-for
               tidak berubah — Alpine akan menganggap ini elemen DOM baru
               kalau uid berganti, dan itu memutus fokus/scroll operator
               tepat saat UPDATE BARIS baru saja diklik. */
            const uid = this.editIndex !== null ? this.rows[this.editIndex].uid : seq++;
            const calon = { uid, ...cfg.kumpulkan(this) };

            for (const [kunci, label] of Object.entries(cfg.wajib)) {
                if (!calon[kunci]) { this.galat = `${label} wajib diisi.`; return; }
            }

            /* cfg.periksa menerima this.rows UTUH (bukan difilter) supaya
               nomor baris di pesan galatnya tetap akurat. Modul yang punya
               pemeriksaan tabrakan antar-baris (produksi, breakdown mesin,
               breakdown dies) mengecualikan this.editIndex sendiri lewat
               diri.editIndex — lihat cfg.periksa masing-masing modul. */
            const galat = cfg.periksa ? cfg.periksa(calon, this.rows, this) : null;
            if (galat) { this.galat = galat; return; }

            if (cfg.pratinjau) cfg.pratinjau(calon, this);

            if (this.editIndex !== null) {
                this.rows[this.editIndex] = calon;
            } else {
                this.rows.push(calon);
            }
            this.editIndex = null;
            this.pesan = '';
            this.barisGagal = [];
            this.resetSebagian();
        },

        /* Dipanggil tombol EDIT di tabel draft. cfg.sebar menulis isi row
           ke form atas (DOM + komponen Alpine seperti taField/partPicker/
           ng-rows/dandori-rows) — kebalikan dari cfg.kumpulkan. */
        edit(i) {
            this.editIndex = i;
            this.galat = '';
            cfg.sebar(this.rows[i], this);
            this.form().scrollIntoView({ behavior: 'smooth', block: 'start' });
        },

        batalEdit() {
            this.editIndex = null;
            this.galat = '';
            this.resetSebagian();
        },
```

- [ ] **Step 4: `hapus(i)` menolak hapus baris yang lagi diedit, dan geser `editIndex` kalau baris sebelumnya dihapus**

Baris 2041 saat ini:
```js
        hapus(i) { this.rows.splice(i, 1); this.barisGagal = []; },
```
Ganti jadi:
```js
        /* Baris yang sedang diedit (i === editIndex) tidak boleh dihapus dari
           sini — tombolnya dinonaktifkan di template, guard ini jaga-jaga
           kalau ada jalur lain yang memanggil hapus() langsung. Menghapus
           baris SEBELUM editIndex menggeser index baris yang diedit — tanpa
           koreksi ini, UPDATE BARIS berikutnya menimpa baris yang salah. */
        hapus(i) {
            if (i === this.editIndex) return;
            this.rows.splice(i, 1);
            this.barisGagal = [];
            if (this.editIndex !== null && i < this.editIndex) this.editIndex--;
        },
```

- [ ] **Step 5: Verifikasi manual — kerangka belum dipakai modul mana pun (nol `cfg.sebar` sampai Task 2), jadi cukup pastikan halaman lain tidak 500**

```bash
php artisan route:list --name=input.produksi.batch
```
Expected: route masih ada (tidak ada error saat file blade dimuat — akan gagal keras saat Task 1 selesai sendirian karena `cfg.sebar` belum ada di modul manapun; lanjut ke Task 2 SEBELUM mencoba buka halaman manapun di browser).

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "feat(batch-draft): tambah state edit di kerangka bersama batchDraft"
```

---

### Task 2: LHP Produksi — tombol EDIT + `cfg.sebar`

**Files:**
- Modify: `resources/views/input/produksi/create.blade.php`

**Interfaces:**
- Consumes: `editIndex`, `edit(i)`, `batalEdit()` dari Task 1.
- Produces: `cfg.sebar` untuk modul produksi (referensi paling lengkap — dipakai
  modul lain sebagai pola).

- [ ] **Step 1: Tombol submit berubah label + tombol Batal Edit**

Baris 283-287 saat ini:
```html
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
```
Ganti jadi:
```html
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

- [ ] **Step 2: Tombol EDIT di tabel draft + highlight baris yang diedit**

Baris 313 saat ini:
```html
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
```
Ganti jadi:
```html
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
```

Baris 331-336 saat ini:
```html
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```
Ganti jadi:
```html
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```

- [ ] **Step 3: Kecualikan `editIndex` sendiri dari pemeriksaan tumpang-tindih jam**

Baris 437-443 saat ini:
```js
            for (let i = 0; i < rows.length; i++) {
                if (rows[i].id_mesin != calon.id_mesin) continue;
                const a = rentang(diri, rows[i]), b = rentang(diri, calon);
                if (a.mulai < b.selesai && b.mulai < a.selesai) {
                    return `Jam tumpang tindih dengan baris ${i + 1} pada mesin dan tanggal yang sama.`;
                }
            }
```
Ganti jadi:
```js
            for (let i = 0; i < rows.length; i++) {
                if (i === diri.editIndex) continue;
                if (rows[i].id_mesin != calon.id_mesin) continue;
                const a = rentang(diri, rows[i]), b = rentang(diri, calon);
                if (a.mulai < b.selesai && b.mulai < a.selesai) {
                    return `Jam tumpang tindih dengan baris ${i + 1} pada mesin dan tanggal yang sama.`;
                }
            }
```

- [ ] **Step 4: Tambah `cfg.sebar`**

Sisip method baru persis setelah `resetLain(diri) { ... },` (baris 480, sebelum
komentar `/* Bentuk field yang dikirim ke server ... */` di baris 482):
```js

        /* Kebalikan cfg.kumpulkan: baca row draft, tulis ke form atas —
           dipanggil edit(i) di kerangka. Part dicari balik dari partsData
           lewat part_no+part_name (row tidak menyimpan id part mentah,
           cuma hasil tampilnya) supaya partPicker.pilih() bisa mengisi
           ulang prosesList-nya sebelum <select id="f_proses"> diisi. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tanggal_produksi"]').value = row.tanggal_produksi;
            f.querySelector('[name="id_shift"]').value = row.id_shift;
            f.querySelector('[name="jam_start"]').value = row.jam_start;
            f.querySelector('[name="jam_finish"]').value = row.jam_finish;
            f.querySelector('[name="qty_aktual"]').value = row.qty_aktual;

            const operator = Alpine.$data(document.getElementById('f_operator').closest('[x-data]'));
            operator.pick({ v: row.id_karyawan, t: row.karyawan_teks });

            const mesin = Alpine.$data(document.getElementById('f_mesin').closest('[x-data]'));
            mesin.pick({ v: row.id_mesin, t: row.mesin_teks });

            const p = picker();
            const dataPart = partsData.find(pp => pp.part_no === row.part_no && pp.part_name === row.part_name);
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses"]');
                    if (proses) proses.value = row.id_part_proses;
                });
            }

            const ngCb = document.getElementById('ada_ng');
            Alpine.$data(ngCb.closest('[x-data]')).adaNg = row.ng.length > 0;
            Alpine.nextTick(() => {
                const ng = diri.blok('.ng-rows');
                if (ng) ng.rows = row.ng.map((n, i) => ({ uid: `e${Date.now()}_${i}`, id_jenis_ng: n.id_jenis_ng, qty: n.qty }));
            });

            const dandoriCb = document.getElementById('ada_dandori');
            Alpine.$data(dandoriCb.closest('[x-data]')).adaDandori = row.dandori.length > 0;
            Alpine.nextTick(() => {
                const dandori = diri.blok('.dandori-rows');
                if (dandori) {
                    dandori.rows = row.dandori.map((d, i) => ({
                        uid: `e${Date.now()}_${i}`, id_losstime: d.id_losstime, actual_menit: d.actual_menit,
                        std_menit: 0, over_menit: 0,
                    }));
                    dandori.rows.forEach(r => dandori.recompute(r));
                }
            });
        },
```

- [ ] **Step 5: Verifikasi manual di browser**

```bash
php artisan serve
```
Buka `http://127.0.0.1:8000/input/produksi/create` (login admin), isi 1 baris,
SAVE TO DRAFT. Klik EDIT baris itu di tabel — pastikan: semua field form
(tanggal, shift, operator, mesin, part name, part no, proses, jam, qty) terisi
persis sama dengan baris tadi; tombol berubah jadi "UPDATE BARIS"; tombol
"Batal Edit" muncul. Ubah Qty OK, klik UPDATE BARIS — baris di tabel harus
BERUBAH di tempat yang sama (bukan nambah baris baru), badge jumlah draft
tetap 1. Uji juga: centang "Ada Dandori" isi 1 rincian sebelum SAVE, lalu EDIT
baris itu — blok Dandori harus otomatis tercentang dan rincian terisi ulang.
Uji "Batal Edit" — form kembali kosong, `editIndex` balik null (tombol EDIT
baris manapun bisa diklik lagi).

- [ ] **Step 6: Commit**

```bash
git add resources/views/input/produksi/create.blade.php
git commit -m "feat(input-produksi): tombol EDIT baris draft"
```

---

### Task 3: QC — tombol EDIT + `cfg.sebar`

**Files:**
- Modify: `resources/views/input/qc/create.blade.php`

**Interfaces:**
- Consumes: sama seperti Task 2. QC nol pemeriksaan tabrakan jam (lihat
  `periksa()` modul ini) — nol perubahan diperlukan di `periksa()`.

- [ ] **Step 1: Tombol submit + Batal Edit**

Baris 240-244 saat ini:
```html
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
```
Ganti jadi (identik pola Task 2):
```html
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

- [ ] **Step 2: Tombol EDIT di tabel + highlight**

Baris 270 saat ini:
```html
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
```
Ganti jadi:
```html
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
```

Baris 287-292 saat ini:
```html
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```
Ganti jadi:
```html
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```

- [ ] **Step 3: Tambah `cfg.sebar`**

Sisip persis setelah `resetLain(diri) { ... },` (baris 421, sebelum `payload(r) {`
di baris 423). QC tidak punya `<select id="f_proses">` manual — `id_part_proses`
adalah hidden input reaktif `:value="prosesList[0] ? prosesList[0].id : ''"`
(baris 194), jadi cukup isi part lewat `pilih()`, proses ikut otomatis:
```js

        /* Kebalikan cfg.kumpulkan. Beda dari produksi: QC nol <select> Proses
           manual — id_part_proses hidden input reaktif ke prosesList[0], jadi
           cukup p.pilih() dan proses ikut terisi sendiri tanpa Alpine.nextTick. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tgl_pengecekan"]').value = row.tgl_pengecekan;
            f.querySelector('[name="tgl_produksi_part"]').value = row.tgl_produksi_part;
            f.querySelector('[name="id_shift"]').value = row.id_shift;
            f.querySelector('[name="jam_start"]').value = row.jam_start;
            f.querySelector('[name="jam_finish"]').value = row.jam_finish;
            f.querySelector('[name="qty_ok"]').value = row.qty_ok;

            const inspector = Alpine.$data(document.getElementById('f_inspector').closest('[x-data]'));
            inspector.pick({ v: row.id_karyawan_qc, t: row.inspector_teks });

            const p = picker();
            const dataPart = partsData.find(pp => pp.part_no === row.part_no && pp.part_name === row.part_name);
            if (dataPart) p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });

            const ngCb = document.getElementById('ada_ng');
            Alpine.$data(ngCb.closest('[x-data]')).adaNg = row.ng.length > 0;
            Alpine.nextTick(() => {
                const ng = diri.blok('.ng-rows');
                if (ng) ng.rows = row.ng.map((n, i) => ({ uid: `e${Date.now()}_${i}`, id_jenis_ng: n.id_jenis_ng, qty: n.qty }));
            });
        },
```

- [ ] **Step 4: Verifikasi manual di browser**

Buka `/input/qc/create`, isi 1 baris dengan NG, SAVE TO DRAFT, EDIT baris itu —
pastikan semua field terisi termasuk blok NG, "Proses Finish Good" muncul benar
(bukan kosong). UPDATE BARIS dengan Qty OK diubah — baris di tabel berubah di
tempat, jumlah draft tidak nambah.

- [ ] **Step 5: Commit**

```bash
git add resources/views/input/qc/create.blade.php
git commit -m "feat(input-qc): tombol EDIT baris draft"
```

---

### Task 4: Dandori — tombol EDIT + `cfg.sebar`

**Files:**
- Modify: `resources/views/input/dandori/create.blade.php`

**Interfaces:**
- Consumes: sama Task 2. Dandori nol pemeriksaan tabrakan jam — nol perubahan
  di `periksa()`.

- [ ] **Step 1: Tombol submit + Batal Edit**

Baris 235-239 saat ini:
```html
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
```
Ganti jadi:
```html
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

- [ ] **Step 2: Tombol EDIT di tabel + highlight**

Baris 264 saat ini:
```html
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
```
Ganti jadi:
```html
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
```

Baris 274-279 saat ini:
```html
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```
Ganti jadi (kolom tabel Dandori punya `colspan="10"` di baris kosong, baris
259 — TIDAK berubah karena jumlah `<th>` tidak bertambah, tombol EDIT masuk
`<td>` yang sama dengan tombol Hapus):
```html
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```

- [ ] **Step 3: Tambah `cfg.sebar`**

Sisip persis setelah `resetLain(diri) { ... },` (baris 411, sebelum `payload(r) {`
di baris 413). Dandori selalu menampilkan blok Rincian Loss Time (nol
checkbox toggle) dan field "Operator Dandori" di dalam partial itu TIDAK
dikumpulkan `kumpulkan()` modul ini (lihat baris 346-364 — nol
`dandori_karyawan_id` di return), jadi `sebar` juga tidak menyentuhnya:
```js

        /* Kebalikan cfg.kumpulkan. Blok Rincian Loss Time selalu tampil (nol
           checkbox), jadi langsung isi dandori.rows tanpa toggle apa pun. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tanggal"]').value = row.tanggal;
            f.querySelector('[name="id_shift"]').value = row.id_shift;

            const operator = Alpine.$data(document.getElementById('f_operator').closest('[x-data]'));
            operator.pick({ v: row.id_karyawan, t: row.karyawan_teks });

            const mesin = Alpine.$data(document.getElementById('f_mesin').closest('[x-data]'));
            mesin.pick({ v: row.id_mesin, t: row.mesin_teks });

            const p = picker();
            const dataPart = partsData.find(pp => pp.part_no === row.part_no && pp.part_name === row.part_name);
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses"]');
                    if (proses) proses.value = row.id_part_proses;
                });
            }

            Alpine.nextTick(() => {
                const dandori = diri.blok('.dandori-rows');
                if (dandori) {
                    dandori.rows = row.rincian.map((r, i) => ({
                        uid: `e${Date.now()}_${i}`, id_losstime: r.id_losstime, actual_menit: r.actual_menit,
                        std_menit: 0, over_menit: 0,
                    }));
                    dandori.rows.forEach(r => dandori.recompute(r));
                }
            });
        },
```

- [ ] **Step 4: Verifikasi manual di browser**

Buka `/input/dandori/create`, isi 1 baris dengan 2 rincian loss time, SAVE TO
DRAFT, EDIT baris itu — pastikan part/proses/mesin/operator terisi dan KEDUA
rincian muncul kembali di tabel dandori-rows dengan Std/Over terhitung ulang
(bukan kosong/0 keduanya kalau memang ada std). UPDATE BARIS — baris berubah
di tempat.

- [ ] **Step 5: Commit**

```bash
git add resources/views/input/dandori/create.blade.php
git commit -m "feat(input-dandori): tombol EDIT baris draft"
```

---

### Task 5: Breakdown Mesin — tombol EDIT + `cfg.sebar`

**Files:**
- Modify: `resources/views/maintenance/breakdown/create.blade.php`

**Interfaces:**
- Consumes: sama Task 2. `periksa()` modul ini punya pemeriksaan No LKM kembar
  antar baris — WAJIB kecualikan `diri.editIndex`.

- [ ] **Step 1: Tombol submit + Batal Edit**

Baris 159-163 saat ini:
```html
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
```
Ganti jadi:
```html
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

- [ ] **Step 2: Tombol EDIT di tabel + highlight**

Baris 187 saat ini:
```html
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
```
Ganti jadi:
```html
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
```

Baris 195-200 saat ini:
```html
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```
Ganti jadi:
```html
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```

- [ ] **Step 3: Kecualikan `editIndex` dari pemeriksaan No LKM kembar**

Baris 318-322 saat ini:
```js
            const noLkmBaru = noLkmPreview(calon.nomor_urut, calon.tanggal_breakdown);
            const kembar = rows.find(r => noLkmPreview(r.nomor_urut, r.tanggal_breakdown) === noLkmBaru);
            if (kembar) {
                return `No LKM ${noLkmBaru} sudah dipakai baris lain di draft ini.`;
            }
```
Ganti jadi:
```js
            const noLkmBaru = noLkmPreview(calon.nomor_urut, calon.tanggal_breakdown);
            const kembar = rows.find((r, i) => i !== diri.editIndex && noLkmPreview(r.nomor_urut, r.tanggal_breakdown) === noLkmBaru);
            if (kembar) {
                return `No LKM ${noLkmBaru} sudah dipakai baris lain di draft ini.`;
            }
```

- [ ] **Step 4: Tambah `cfg.sebar`**

Sisip persis setelah `resetLain(diri) { majukanNomorUrut(diri); },` (baris
350-352, sebelum komentar seam `setelahPulih` di baris 354). Breakdown Mesin
nol part picker — cuma taField untuk mesin dan PIC:
```js

        /* Kebalikan cfg.kumpulkan. nomor_urut ikut ditulis ulang ke nilai
           baris yang diedit — majukanNomorUrut() yang jalan lewat resetLain()
           setelah UPDATE BARIS akan menghitung ulang nomor berikutnya dari
           this.rows yang sudah diperbarui, jadi tidak perlu ditangani di sini. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="nomor_urut"]').value = row.nomor_urut;
            f.querySelector('[name="tanggal_breakdown"]').value = row.tanggal_breakdown;
            f.querySelector('[name="jam_breakdown"]').value = row.jam_breakdown;
            f.querySelector('[name="tanggal_mulai_perbaikan"]').value = row.tanggal_mulai_perbaikan;
            f.querySelector('[name="jam_mulai_perbaikan"]').value = row.jam_mulai_perbaikan;
            f.querySelector('[name="tanggal_selesai_perbaikan"]').value = row.tanggal_selesai_perbaikan;
            f.querySelector('[name="jam_selesai_perbaikan"]').value = row.jam_selesai_perbaikan;
            f.querySelector('[name="problem_mesin"]').value = row.problem_mesin;
            f.querySelector('[name="penyebab_breakdown"]').value = row.penyebab_breakdown;
            f.querySelector('[name="tindakan_perbaikan"]').value = row.tindakan_perbaikan;

            const mesin = Alpine.$data(document.getElementById('f_mesin').closest('[x-data]'));
            mesin.pick({ v: row.id_mesin, t: row.mesin_teks });

            const pic = Alpine.$data(document.getElementById('f_pic').closest('[x-data]'));
            pic.pick({ v: row.pic_maintenance, t: row.pic_maintenance });
        },
```

- [ ] **Step 5: Verifikasi manual di browser**

Buka `/maintenance/breakdown/create`, isi 1 baris, SAVE TO DRAFT, EDIT — semua
field (termasuk 3 pasang tanggal/jam dan 3 textarea) terisi ulang. UPDATE
BARIS dengan Nomor Urut LKM TIDAK diubah — pastikan nol galat "No LKM sudah
dipakai" muncul (ini kasus yang tadinya akan salah tanpa Step 3).

- [ ] **Step 6: Commit**

```bash
git add resources/views/maintenance/breakdown/create.blade.php
git commit -m "feat(breakdown-mesin): tombol EDIT baris draft"
```

---

### Task 6: Breakdown Dies — tombol EDIT + `cfg.sebar`

**Files:**
- Modify: `resources/views/maintenance/breakdown-dies/create.blade.php`

**Interfaces:**
- Consumes: sama Task 2. `periksa()` modul ini punya pemeriksaan No LKD kembar
  — WAJIB kecualikan `diri.editIndex`, pola identik Task 5.

- [ ] **Step 1: Tombol submit + Batal Edit**

Baris 201-205 saat ini:
```html
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
```
Ganti jadi:
```html
            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
```

- [ ] **Step 2: Tombol EDIT di tabel + highlight**

Baris 230 saat ini:
```html
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
```
Ganti jadi:
```html
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
```

Baris 240-245 saat ini:
```html
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```
Ganti jadi:
```html
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
```

- [ ] **Step 3: Kecualikan `editIndex` dari pemeriksaan No LKD kembar**

Baris 371-375 saat ini:
```js
            const noLkdBaru = noLkdPreview(calon.nomor_urut, calon.tanggal_breakdown);
            const kembar = rows.find(r => noLkdPreview(r.nomor_urut, r.tanggal_breakdown) === noLkdBaru);
            if (kembar) {
                return `No LKD ${noLkdBaru} sudah dipakai baris lain di draft ini.`;
            }
```
Ganti jadi:
```js
            const noLkdBaru = noLkdPreview(calon.nomor_urut, calon.tanggal_breakdown);
            const kembar = rows.find((r, i) => i !== diri.editIndex && noLkdPreview(r.nomor_urut, r.tanggal_breakdown) === noLkdBaru);
            if (kembar) {
                return `No LKD ${noLkdBaru} sudah dipakai baris lain di draft ini.`;
            }
```

- [ ] **Step 4: Tambah `cfg.sebar`**

Baca dulu blok `resetLain(diri)` modul ini (langsung setelah Step 3 di file
aslinya, sekitar baris 404 dst. — sama pola `majukanNomorUrut(diri)` seperti
Breakdown Mesin) untuk pastikan titik sisip persis setelahnya, sebelum
`payload(r) {`. Breakdown Dies PUNYA part picker (beda dari Breakdown Mesin):
```js

        /* Kebalikan cfg.kumpulkan. Beda dari Breakdown Mesin: modul ini
           punya part picker + <select> Proses manual, nol field mesin. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="nomor_urut"]').value = row.nomor_urut;
            f.querySelector('[name="tanggal_breakdown"]').value = row.tanggal_breakdown;
            f.querySelector('[name="jam_breakdown"]').value = row.jam_breakdown;
            f.querySelector('[name="tanggal_mulai_perbaikan"]').value = row.tanggal_mulai_perbaikan;
            f.querySelector('[name="jam_mulai_perbaikan"]').value = row.jam_mulai_perbaikan;
            f.querySelector('[name="tanggal_selesai_perbaikan"]').value = row.tanggal_selesai_perbaikan;
            f.querySelector('[name="jam_selesai_perbaikan"]').value = row.jam_selesai_perbaikan;
            f.querySelector('[name="problem_dies"]').value = row.problem_dies;
            f.querySelector('[name="penyebab_breakdown"]').value = row.penyebab_breakdown;
            f.querySelector('[name="tindakan_perbaikan"]').value = row.tindakan_perbaikan;

            const p = picker();
            const dataPart = partsData.find(pp => pp.part_no === row.part_no && pp.part_name === row.part_name);
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses"]');
                    if (proses) proses.value = row.id_part_proses;
                });
            }

            const pic = Alpine.$data(document.getElementById('f_pic').closest('[x-data]'));
            pic.pick({ v: row.pic_maintenance, t: row.pic_maintenance });
        },
```

- [ ] **Step 5: Verifikasi manual di browser**

Buka `/maintenance/breakdown-dies/create`, isi 1 baris, SAVE TO DRAFT, EDIT —
part/proses dan semua field lain terisi ulang. UPDATE BARIS — nol galat "No
LKD sudah dipakai".

- [ ] **Step 6: Commit**

```bash
git add resources/views/maintenance/breakdown-dies/create.blade.php
git commit -m "feat(breakdown-dies): tombol EDIT baris draft"
```

---

### Task 7: Regresi — pastikan jalur lama (nol edit) masih utuh di ke-5 modul

**Files:** (nol file baru — verifikasi murni)

- [ ] **Step 1: `php artisan test` — pastikan nol regresi PHP**

```bash
php artisan test --filter=Batch
```
Expected: semua test batch (`ProduksiBatchTest`, `QcBatchTest`,
`DandoriBatchTest`, `BreakdownMesinBatchTest`, `BreakdownDiesBatchTest` atau
nama serupa) tetap PASS — perubahan Task 1-6 murni Blade/JS, nol test PHP yang
seharusnya terpengaruh; run ini pembuktian bahwa asumsi itu benar.

- [ ] **Step 2: Verifikasi manual jalur tambah-baris biasa di 1 modul (LHP Produksi) tanpa pernah klik EDIT**

Buka `/input/produksi/create`, isi & SAVE TO DRAFT 3 baris berturut-turut TANPA
klik EDIT sama sekali. Pastikan: label tombol tetap "SAVE TO DRAFT" terus,
nol tombol "Batal Edit" muncul, 3 baris masuk tabel dengan benar, SUBMIT DRAFT
berhasil seperti sebelumnya. Ini pembuktian `editIndex` yang default `null`
nol mengganggu alur lama.

- [ ] **Step 3: Commit (kalau ada perbaikan dari langkah verifikasi di atas)**

Kalau Step 1-2 nol menemukan masalah, task ini nol menghasilkan commit —
lewati.
