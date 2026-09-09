# LHR Produksi — Sub-modul Kedua Repair

## Latar belakang

Modul Repair (LHR) yang baru live punya satu alur: repair dikerjakan tim/role
`staff_repair`. Pabrik ternyata punya alur repair KEDUA: barang NG yang
direpair langsung oleh **operator produksi sendiri** (bukan tim repair
khusus). Ini perlu dicatat terpisah dari LHR yang sudah ada karena aktor,
akses, dan (berpotensi) analitik ke depannya berbeda.

Sidebar existing:

```
Repair
  └─ Input LHR   → input.repair.index
```

Sidebar target:

```
Repair
  ├─ LHR Regular   → input.repair.index (route lama, cuma label diganti)
  └─ LHR Produksi  → input.repair-produksi.index (baru)
```

## Keputusan desain

- **Duplikasi struktur, BUKAN reuse tabel.** LHR Produksi punya tabel
  transaksi sendiri (`transaksi_repair_produksi`) dan master proses sendiri
  (`master_part_proses_repair_produksi`) — independen dari
  `transaksi_repair`/`master_part_proses_repair` LHR Regular. Alasan: user
  eksplisit minta "beda total: tabel & master data sendiri-sendiri" — dua
  alur ini dianggap domain terpisah, bukan varian dari satu domain.
- **Field form identik** dengan LHR Regular (Tanggal, Shift, Operator, Part
  Name/No/Customer via master, ID Proses/Proses via master, Jam Start/Finish,
  Qty Act Repair, SPH STD (dari master), SPH ACT/W.Kerja/CT Act/% dihitung
  sama formula persis LHR Regular).
- **`master_part_proses_repair_produksi`** tetap FK ke `MasterPart` yang sama
  (part fisik satu sumber kebenaran di seluruh sistem) — cuma proses & SPH
  standar-nya sendiri, sama pola LHR Regular.
- **Akses input/daftar/export:** `admin, staff_repair, staff_produksi` — tiga
  role ini semua bisa isi & lihat LHR Produksi (beda dari LHR Regular yang
  cuma `admin, staff_repair`).
- **Edit/Update/Destroy: admin-only**, sama konvensi seluruh keluarga LHP.
- **`id_proses` bebas string**, sama pola `master_part_proses`/
  `master_part_proses_repair`.
- **`actual_cycle_time` dihitung, bukan manual** — sama formula LHR Regular
  (durasi_detik ÷ qty_aktual), dan HARUS dihitung ulang saat `update()` (bug
  yang sempat kejadian di LHR Regular — jangan diulang di sini).

## Data model

### Tabel baru: `master_part_proses_repair_produksi`

Identik struktur `master_part_proses_repair` (lihat migrasi
`2026_09_09_100001_create_master_part_proses_repair_table.php` sebagai
cetakan): `id_part` (FK `master_part`), `id_proses`, `nama_proses`,
`sph_standar_repair_produksi`, SoftDeletes.

Model `MasterPartProsesRepairProduksi` — cermin `MasterPartProsesRepair`.

### Tabel baru: `transaksi_repair_produksi`

Identik struktur `transaksi_repair` (migrasi
`2026_09_09_100002_create_transaksi_repair_table.php` sebagai cetakan):
`tanggal_repair_produksi`, `id_shift`, `id_karyawan`,
`id_part_proses_repair_produksi`, `jam_start`, `jam_finish`, `durasi_menit`
(auto), `qty_aktual`, `actual_cycle_time` (computed), `created_by`,
`updated_by`, SoftDeletes.

Model `TransaksiRepairProduksi` — cermin `TransaksiRepair`, termasuk
`hitungDurasiMenit()` standalone copy (bukan panggil silang ke model lain)
dan accessor `waktuKerjaJam`/`sphAktual` formula sama persis.

## Service, Request, Controller

Cermin 1:1 modul Repair existing, nama diganti akhiran `Produksi`:

- `RepairProduksiWriteService` — `simpan()` + `hitungCycleTime()` public
  (dipakai `simpan()` DAN `RepairProduksiController::update()` — WAJIB dari
  awal, bukan ditambal belakangan seperti kasus LHR Regular).
- `StoreRepairProduksiBatchRequest`, `UpdateRepairProduksiRequest`
- `RepairProduksiController` (index/create/batch/edit/update/export/destroy)
- `StoreMasterPartProsesRepairProduksiRequest` +
  `Master\ProsesRepairProduksiController` (CRUD admin-only) — **validasi
  `sph_standar_repair_produksi` WAJIB `required` (bukan `nullable`)** karena
  kolomnya NOT NULL — bug yang sempat kejadian di LHR Regular, jangan
  diulang.
- `LhpRepairProduksiExport`

## Routes

- `input/repair-produksi/*` → `input.repair-produksi.*`
  - `index|create|batch|export` → role `admin,staff_repair,staff_produksi`
  - `edit|update|destroy` → role `admin` saja (masuk blok admin-only "EDIT/UPDATE LHP" existing)
- `master/proses-repair-produksi/*` → `master.proses-repair-produksi.*` —
  role `admin`, **route manual (bukan `Route::resource()`)** dengan
  placeholder camelCase `{prosesRepairProduksi}` — pelajaran dari LHR Regular
  soal mismatch snake_case/camelCase di route model binding.

## Views

- `resources/views/input/repair-produksi/{index,create,edit}.blade.php` —
  cermin `input/repair/*`, field sama, reuse komponen JS bersama
  (`batchDraft`, `taField`, `partPicker`, `filterSearchComp`,
  `partGroupComp`, `<x-export-modal>`) — TIDAK boleh redefine.
  - Toolbar Export & panel modal export: role gate `admin,staff_repair,staff_produksi`
    (bukan admin-only — pelajaran dari bug LHR Regular Fix #3).
  - Edit/Hapus toolbar: role gate `admin` saja.
- `resources/views/master/proses-repair-produksi/{index,create,edit}.blade.php`
  — cermin `master/proses-repair/*`.

## Sidebar

`config/sidebar.php` grup `repair` berubah dari 1 item jadi 2:

```php
[
    'type' => 'group',
    'key' => 'repair',
    'label' => 'Repair',
    'icon' => 'bi-bandaid',
    'items' => [
        ['label' => 'LHR Regular', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair.index', 'roles' => ['admin', 'staff_repair'], 'match' => ['input.repair.*']],
        ['label' => 'LHR Produksi', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair-produksi.index', 'roles' => ['admin', 'staff_repair', 'staff_produksi'], 'match' => ['input.repair-produksi.*']],
    ],
],
```

Grup `master` nambah item "Proses Repair Produksi" →
`master.proses-repair-produksi.index`, role `admin`.

Plafon: grup `repair` sekarang 2 anak (dari 1) — masih jauh di bawah
`ANAK_TERBESAR` limit (16). Top-level tetap 9, tidak berubah.

## Chatbot

`master_part_proses_repair_produksi` & `transaksi_repair_produksi` masuk
`BusinessTableCatalog` di PR yang sama — jangan sampai kejadian gap yang
sama seperti LHR Regular (baru ketahuan pas final review).

## Migrasi & Deploy

Dua migrasi baru, WAJIB didaftar di `.github/workflows/deploy.yml` di task
yang sama.

## Testing

Mirror seluruh suite LHR Regular dengan nama `*RepairProduksi*`:
model unit test, write-service unit test, master CRUD feature test, batch
feature test (termasuk test role `staff_produksi` BISA akses, beda dari LHR
Regular yang menolaknya), export unit test, halaman batch/daftar/edit
feature test, `AuthorizationTest` boundary, `SidebarMenuTest`/
`SidebarNavTest` update untuk 2 anak baru + peran `staff_produksi` akses
grup ini.

**Regression test wajib dari awal** (bukan ditambal di akhir seperti LHR
Regular): `actual_cycle_time` terhitung ulang saat `update()`, dan validasi
`sph_standar_repair_produksi` kosong gagal dengan pesan validasi (bukan 500).

## Yang TIDAK berubah

- `input.repair.*` (LHR Regular) — routes, controller, model, tabel semuanya
  tetap sama persis. HANYA label sidebar yang berubah ("Input LHR" →
  "LHR Regular").
- Tidak ada migrasi/perubahan skema ke tabel `transaksi_repair`/
  `master_part_proses_repair` existing.
