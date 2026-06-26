# Design: Modul Maintenance Mesin (Fase 1)

**Tanggal:** 2026-06-26
**Status:** Approved (brainstorming)
**Project:** OPTIMA MES — Laravel 13

## Konteks

Migrasi modul Maintenance (MTC) dari mockup Google Apps Script (`system_mtc/`) ke OPTIMA.
Modul penuh mencakup **Mesin** + **Dies**, masing-masing dengan input breakdown dan 3
dashboard turunan (MTTR, MTBF, Availability).

Modul dibangun **bertahap**. Spec ini hanya **Fase 1 — Maintenance Mesin**. Dies = Fase 2
(spec terpisah, mereplikasi pola fase ini + master dies baru yang terhubung ke part/proses).

## Scope Fase 1

Yang dibangun:
- **Role baru** `staff_maintenance` (teknisi MTC).
- **Input Breakdown Mesin** — form create + list + edit/hapus (admin). Pola mengikuti
  `input/produksi`.
- **3 Dashboard**: MTTR, MTBF, Availability mesin. Pola mengikuti VCB existing
  (AJAX + Chart.js + filter tanggal).
- **Master mesin** — tambah kolom `tanggal_instalasi` (basis MTBF & Availability).

Yang TIDAK termasuk (out of scope fase ini):
- Apapun terkait Dies (master dies, breakdown dies, dashboard dies) → Fase 2.
- Precompute dashboard ke tabel (mockup lama precompute ke sheet; di sini dihitung on-the-fly).

## Data Model

### Alter `master_mesin`
Tambah kolom:
- `tanggal_instalasi` — `date`, nullable. Basis perhitungan MTBF & Availability.

Migration baru (jangan ubah migration lama). Karena hosting DB mungkin belum punya kolom
ini, semua kode yang membaca `tanggal_instalasi` harus defensive (lihat catatan di bawah).

### Tabel baru `transaksi_breakdown_mesin`

| kolom | tipe | catatan |
|---|---|---|
| id | bigint PK | |
| no_lkm | string, unique | format `{n}/LKM/MTC/KMI/{romawi-bulan}/{yy}` |
| id_mesin | FK → master_mesin.id_mesin | |
| tanggal_breakdown | date | |
| jam_breakdown | time | |
| tanggal_mulai_perbaikan | date | |
| jam_mulai_perbaikan | time | |
| tanggal_selesai_perbaikan | date | |
| jam_selesai_perbaikan | time | |
| problem_mesin | text | |
| penyebab_breakdown | text | |
| tindakan_perbaikan | text | |
| status_breakdown | string | derived: `Ringan` (<1j) / `Sedang` (1–3j) / `Berat` (≥3j) |
| pic_maintenance | string | |
| repair_time | decimal(8,2) jam | derived = selesai_perbaikan − mulai_perbaikan |
| total_downtime | decimal(8,2) jam | derived = selesai_perbaikan − tanggal_breakdown |
| timestamps + softDeletes | | pola project |

Index: `id_mesin`, `tanggal_breakdown`.

**Field derived dihitung di server saat store/update** — `no_lkm`, `repair_time`,
`total_downtime`, `status_breakdown`. Jangan percaya nilai dari input form.

### Model
`app/Models/TransaksiBreakdownMesin.php` — `use SoftDeletes`, `belongsTo(MasterMesin, 'id_mesin', 'id_mesin')`.
`MasterMesin` tambah `tanggal_instalasi` ke `$fillable` + `hasMany(TransaksiBreakdownMesin)`.

### Helper No.LKM
- `toRoman(bulan)` → angka romawi I..XII.
- `generateNoLkm(nomorUrut, tanggalBreakdown)` → `{nomorUrut}/LKM/MTC/KMI/{romawiBulan}/{yy}`.
- `lastNoLkm()` (AJAX) → saran nomor urut berikutnya (max nomor + 1), buat prefill form.
- Store menolak `no_lkm` duplikat (DB unique + cek eksplisit, pesan: "No LKM sudah ada").

## Routes / Controller / Views

Namespace controller: `App\Http\Controllers\Maintenance\`.

```
Route::middleware('role:admin,staff_maintenance,manager')->prefix('maintenance')->name('maintenance.')->group:
  GET    breakdown                BreakdownMesinController@index    (list)
  GET    breakdown/create         @create
  POST   breakdown                @store
  GET    breakdown/last-no-lkm    @lastNoLkm        (AJAX)
  POST   breakdown/import         @import           (opsional, pola existing)
  GET    breakdown/import-template @importTemplate  (opsional)
  GET    mttr                     MttrController@index
  GET    mttr/data                MttrController@data           (AJAX)
  GET    mtbf                     MtbfController@index
  GET    mtbf/data                MtbfController@data           (AJAX)
  GET    availability            AvailabilityController@index
  GET    availability/data       AvailabilityController@data    (AJAX)

Route::middleware('role:admin')->prefix('maintenance')->name('maintenance.')->group:
  GET    breakdown/{breakdown}/edit   @edit
  PUT    breakdown/{breakdown}        @update
  DELETE breakdown/{breakdown}        @destroy
```

Views:
- `resources/views/maintenance/breakdown/{index,create,edit}.blade.php`
- `resources/views/maintenance/{mttr,mtbf,availability}.blade.php`

List breakdown memakai pola **sticky-freeze** yang sudah ada di project (CSS `.tbl-freeze`
shared di `layouts/app.blade.php`). Kolom freeze: Tanggal → Mesin.

FormRequest: `StoreBreakdownMesinRequest` (validasi tanggal/jam, urutan waktu masuk akal:
mulai_perbaikan ≥ breakdown, selesai ≥ mulai).

## DashboardService

`app/Services/MaintenanceDashboardService.php` (pola `app/Services/DashboardService.php`).
Hitung on-the-fly, tanpa tabel dashboard.

Filter tanggal `[from, to]`. Default: lifetime (instalasi → hari ini). Per mesin, dalam window:
- `breakdowns` = baris dengan `tanggal_breakdown` ∈ `[from, to]`.
- `count` = jumlah breakdown; `Σrepair` = Σ repair_time; `Σdowntime` = Σ total_downtime.
- `operating_hours` = jam dari `max(tanggal_instalasi, from)` sampai `to`.

Formula:
- **MTTR** (jam) = `count > 0 ? Σrepair / count : 0`
- **MTBF** (jam) = `count > 0 ? (operating_hours − Σdowntime) / count : operating_hours`
- **Availability %** = `operating_hours > 0 ? (operating_hours − Σdowntime) / operating_hours × 100 : 100`

Output per mesin: id_mesin, nama_mesin, count, Σrepair, Σdowntime, mttr_jam, mtbf_jam,
mtbf_hari, availability_pct.

**Defensive:** mesin dengan `tanggal_instalasi` NULL → MTBF & Availability tidak dapat
dihitung. Tandai baris "instalasi belum diset" dan kecualikan dari agregat/chart, jangan
sampai throw. Semua akses `tanggal_instalasi` dibungkus try-catch / cek kolom (pola
defensive-DB project, untuk jaga-jaga hosting belum migrate).

Dashboard view: kartu ringkas (rata-rata MTTR/MTBF/Availability) + Chart.js bar per mesin +
tabel detail + filter rentang tanggal (AJAX, pola VCB existing).

## Nav + Role

- Sidebar (`layouts/app.blade.php`): grup baru **"Maintenance"** (icon `bi-wrench-adjustable`)
  berisi: Breakdown, MTTR, MTBF, Availability. Tampil untuk `admin`, `staff_maintenance`,
  `manager`.
- Role `staff_maintenance`: daftarkan di UI/validasi user (`Master\UserController`,
  request user, dropdown role). Middleware `role:` sudah generic (cek string) → cukup
  ditambahkan di route group + UI.
- `/dashboard` redirect: `staff_maintenance` → `maintenance/mttr`.

## Testing

phpunit (pola project):
- **Store breakdown**: `no_lkm` ter-generate sesuai format; `repair_time`, `total_downtime`,
  `status_breakdown` benar; `no_lkm` duplikat ditolak; urutan waktu tidak valid ditolak.
- **MaintenanceDashboardService**: MTTR/MTBF/Availability benar untuk dataset kecil; window
  filter mempersempit hasil; edge case (instalasi NULL → ditandai, tidak throw; count 0 →
  MTTR 0, availability 100).
- **Role gate**: `staff_qc` / `staff_produksi` tidak bisa akses route maintenance;
  edit/hapus hanya admin.

## Fase 2 (catatan, di luar scope)

Replikasi pola ini untuk Dies + buat **master dies** (`id_dies`, `nama_dies`, link ke
part/proses + customer). Breakdown dies bawa kolom tambahan `id_part_proses` (→ id_proses,
nama_proses, customer). No.LKD format `{n}/LKD/MTC/KMI/{romawi}/{yy}`. Dashboard dies di-grup
per `id_dies` + proses.
