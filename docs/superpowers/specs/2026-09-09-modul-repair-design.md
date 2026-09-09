# Modul Repair (LHR — Laporan Harian Repair)

## Latar belakang

OPTIMA punya grouping domain: Produksi, Quality, MTC Mesin, MTC Dies. Tiap grouping
punya menu sendiri di sidebar dan modul input/laporan sendiri. Sekarang ditambah
grouping baru: **Repair** — mencatat perbaikan barang NG hasil produksi/QC.

Repair berdiri sendiri: input manual, TIDAK link ke transaksi NG existing
(`transaksi_produksi_ng` / `transaksi_qc_ng`). Cuma mencatat qty yang **berhasil**
diperbaiki jadi OK (bukan qty gagal/scrap — di luar scope MVP ini).

Struktur data & UI mengikuti pola LHP Produksi persis (bukan pola baru) karena
sudah teruji dan konsisten dengan codebase.

## Scope MVP

Termasuk:
- Master data baru: Proses Repair (part + proses + SPH standar khusus repair)
- Input LHR: form batch (localStorage draft, pola sama LHP Produksi Batch Input)
- Daftar/laporan LHR: dock filter, edit, hapus (soft delete), export Excel
- Role baru `staff_repair`
- Menu sidebar grup baru "Repair"

TIDAK termasuk (YAGNI, bisa menyusul):
- VCB/dashboard visual Repair
- Import Excel LHR
- Tracking qty gagal/scrap repair
- Fitur staff_note / resolve note
- Log riwayat upgrade SPH standar repair (beda dari `MasterPartProsesSphLog` yang
  dipakai Produksi/QC — di sini admin edit langsung, tanpa histori)
- Akses guest/manager ke modul Repair

## Data model

### Tabel baru: `master_part_proses_repair`

Mirror `master_part_proses`, minus kolom upgrade-history.

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `id_part` | FK → `master_part` | Part sama dengan modul lain (Customer/Part Name/Part No ikut dari sini) |
| `id_proses` | string/int | ID proses, khusus proses repair |
| `nama_proses` | string | Nama proses repair |
| `sph_standar_repair` | int | SPH standar khusus repair, admin edit langsung |
| `created_at/updated_at/deleted_at` | | SoftDeletes |

Model `MasterPartProsesRepair` (`app/Models/MasterPartProsesRepair.php`):
- `use SoftDeletes, LogsActivity` (pola sama model master lain)
- relasi `part(): BelongsTo` → `MasterPart`
- relasi `transaksiRepair(): HasMany` → `TransaksiRepair`

### Tabel baru: `transaksi_repair`

Mirror `transaksi_produksi`, field disesuaikan (tanpa `id_mesin`, tanpa `id_jenis_ng`/`qty_ng`, tanpa staff_note*).

| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | bigint PK | |
| `tanggal_repair` | date | |
| `id_shift` | FK → `master_shift` | |
| `id_karyawan` | FK → `master_karyawan` | Operator/PIC repair |
| `id_part_proses_repair` | FK → `master_part_proses_repair` | Sumber Customer/Part Name/Part No/Proses/SPH STD |
| `jam_start` | time | |
| `jam_finish` | time | |
| `durasi_menit` | int, TIDAK di `$fillable` | Auto-hitung saat `saving()`, sama pola `TransaksiProduksi::hitungDurasiMenit()` — **reuse method itu langsung**, jangan duplikat |
| `qty_aktual` | int | QTY Act Repair — barang berhasil diperbaiki jadi OK |
| `actual_cycle_time` | decimal | CT Kerja, input manual |
| `created_by` / `updated_by` | FK → `users` | |
| `created_at/updated_at/deleted_at` | | SoftDeletes |

Model `TransaksiRepair` (`app/Models/TransaksiRepair.php`):
- Accessor `getWaktuKerjaJamAttribute()` — SAMA formula `TransaksiProduksi` (durasi_menit/60)
- Accessor `getSphAktualAttribute()` — SAMA formula (`qty_aktual / waktu_kerja_jam`, dibulatkan)
- % (performance) dihitung di view/service: `sph_aktual / sph_standar_repair * 100` —
  **JANGAN di-clamp ke 100%** (alasan sama kasus SPH Teoretis existing:
  performance bisa lewat 100% karena dua tujuan SPH standar yang bertentangan —
  lihat `memory/projects/project_sph_teoretis.md`)
- relasi `shift()`, `karyawan()`, `partProsesRepair()`, `createdBy()`, `updatedBy()`

## Service

`app/Services/RepairWriteService.php` — mirror `ProduksiWriteService`:
- `simpanBatch(array $rows, int $userId): array` — validasi tiap baris, insert
  banyak `TransaksiRepair`, kembalikan ringkasan sukses/gagal (pola sama batch
  Produksi: "50 baris dikirim sebagai satu payload, `max_input_vars` bukan
  masalah lagi karena format `rows_json`")
- `update(TransaksiRepair $repair, array $data, int $userId): TransaksiRepair`

## Controller & Routes

`app/Http/Controllers/Input/RepairController.php`, mirror `ProduksiController`:

| Method | Route name | Middleware role |
|---|---|---|
| `index` | `input.repair.index` | `admin, staff_repair` |
| `create` | `input.repair.create` | `admin, staff_repair` |
| `batch` | `input.repair.batch` (POST) | `admin, staff_repair` |
| `edit` | `input.repair.edit` | `admin, staff_repair` |
| `update` | `input.repair.update` (PUT) | `admin, staff_repair` |
| `destroy` | `input.repair.destroy` (DELETE) | `admin, staff_repair` |
| `export` | `input.repair.export` | `admin, staff_repair` |

Route group prefix `input/repair`, di `routes/web.php` nempel di blok existing
`prefix('input')->name('input.')`.

Master CRUD: `app/Http/Controllers/Master/ProsesRepairController.php`, route
prefix `master/proses-repair`, name `master.proses-repair.*`, role `admin` saja.
Mirror `PartController` (bagian proses saja, bukan seluruh part CRUD).

## Views

- `resources/views/input/repair/index.blade.php` — pola `.erp-ws`, dock filter
  (Find / Part & Customer / Filter by Date, checklist tanggal WAJIB berpasangan
  `filter_apply` — lihat `memory/projects/project_dock_filter_konvensi.md`),
  tombol "Input Baru" ke halaman create, tabel daftar + tombol Edit/Hapus/Export.
- `resources/views/input/repair/create.blade.php` — halaman batch input,
  draft `localStorage`, kontrak `cfg` sama modul batch lain
  (lihat `memory/projects/project_batch_draft_edit.md` untuk kontrak `cfg.sebar()`).
  Field: Tanggal, Shift, Operator (typeahead karyawan), Part (typeahead →
  auto-fill Customer/Part Name/Part No), Proses Repair (dropdown/typeahead dari
  `master_part_proses_repair` filter by part terpilih → auto-fill SPH STD),
  Jam Start, Jam Finish, QTY Act Repair, CT Kerja.
  W. Kerja, SPH ACT, % dihitung live di JS (read-only, mirror kalkulasi Produksi).
- `resources/views/input/repair/edit.blade.php` — form tunggal edit satu baris.
- `resources/views/master/proses-repair/index.blade.php` — CRUD master, mirror
  bagian "Part & Proses" di halaman Master Part existing.

Komponen JS bersama (`taField`, `partPicker`, `taFieldFiltered`) dipakai ulang —
**data array WAJIB lewat var JS global di `@push('scripts')`, JANGAN inline
`@json()` di `x-data="..."`** (lihat `memory/feedback_komponen_js_bersama.md`).

## Export

`app/Exports/LhpRepairExport.php`, mirror `LhpProduksiExport.php`.

## Role & Sidebar

- `app/Models/User.php` — tambah `'staff_repair' => 'Staff Repair'` di `const ROLES`.
  Ini SATU-SATUNYA tempat wajib disentuh supaya role sah secara sistem
  (validasi request, dropdown form user, filter daftar user otomatis ikut).
- `config/sidebar.php` — grup baru:
  ```php
  [
      'type' => 'group',
      'key' => 'repair',
      'label' => 'Repair',
      'icon' => 'bi-bandaid',
      'items' => [
          ['label' => 'Input LHR', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair.index', 'roles' => ['admin', 'staff_repair'], 'match' => ['input.repair.*']],
      ],
  ]
  ```
  Ditaruh setelah grup `mtcdies`, sebelum grup `master`. Ini top-level ke-9 —
  PAS di plafon `tingkat_atas≤9` (lihat `memory/project_sidebar_collapsible_nav.md`),
  jangan nambah grup top-level lagi setelah ini tanpa reorganisasi.
- `config/sidebar.php` grup `master` — tambah item:
  ```php
  ['label' => 'Proses Repair', 'icon' => 'bi-bandaid', 'route' => 'master.proses-repair.index', 'roles' => ['admin'], 'match' => ['master.proses-repair.*']],
  ```

## Migrasi & Deploy

Dua migrasi baru:
1. `xxxx_create_master_part_proses_repair_table.php`
2. `xxxx_create_transaksi_repair_table.php`

**WAJIB** ditambahkan ke step "Migrate (targeted)" di `.github/workflows/deploy.yml`
pada PR yang sama — aturan project, lupa = gagal senyap
(lihat CLAUDE.md § Deploy & Migrasi).

Kode yang baca kolom baru tetap wajib guard `Schema::hasColumn` mengikuti pola
`TransaksiProduksi::booted()` untuk `durasi_menit`.

## Testing

Mirror suite existing, disesuaikan nama:
- `tests/Feature/Input/RepairBatchTest.php` (mirror `ProduksiBatchTest`)
- `tests/Feature/Input/HalamanBatchRepairTest.php` (mirror `HalamanBatchProduksiTest`)
- `tests/Unit/RepairWriteServiceTest.php` (mirror `ProduksiWriteServiceTest`)
- `tests/Feature/Master/ProsesRepairCrudTest.php` (mirror bagian proses di test Master Part)
- `tests/Feature/AuthorizationTest.php` — tambah assertion `staff_repair` cuma
  bisa akses `input.repair.*`, DITOLAK dari route domain lain.
- `tests/Unit/SidebarMenuTest.php` & `tests/Feature/Layout/SidebarNavTest.php` —
  keduanya punya literal daftar-semua-role (`ROLES` const / array manual di
  beberapa method) yang TIDAK otomatis ikut kalau `User::ROLES` nambah entry.
  Tambah `staff_repair` ke daftar literal itu supaya coverage nggak bolong.
- `tests/Feature/OverviewPageTest.php` — ada loop role literal (baris ~350),
  putuskan saat implementasi apakah `staff_repair` perlu diuji di situ (overview
  page saat ini terbuka untuk staff_produksi/qc/maintenance/manager/guest —
  Repair sengaja TIDAK dapat akses Overview di scope MVP ini kecuali diputuskan
  lain saat implementasi).

## Error handling

- Batch: baris invalid dilaporkan per-baris (index + pesan), baris valid tetap
  tersimpan — pola sama batch Produksi/QC.
- `Schema::hasColumn` guard di `TransaksiRepair::booted()` untuk `durasi_menit`,
  in case migrasi prod telat (pola wajib project, lihat
  `memory/feedback_defensive_db.md`).
- Master Proses Repair: part harus punya minimal satu proses repair sebelum
  bisa dipilih di form LHR — kalau kosong, dropdown proses tampil kosong dengan
  pesan "Belum ada proses repair untuk part ini, tambah di Master Data dulu."

## Keputusan final (update dari review awal)

- `staff_repair` **DAPAT** akses halaman Overview. `config/sidebar.php` baris
  Overview (`roles`) dan `routes/web.php` middleware `role:...` untuk route
  `overview` tambah `staff_repair`. `tests/Feature/OverviewPageTest.php`
  (loop role literal ~baris 350) ikut ditambah `staff_repair`.
- `id_proses` di `master_part_proses_repair` ikut pola existing
  `master_part_proses.id_proses`: string bebas diisi manual (bukan
  auto-increment) — konsisten, satu pola id_proses untuk seluruh sistem,
  nggak nambah varian baru tanpa alasan kuat.
