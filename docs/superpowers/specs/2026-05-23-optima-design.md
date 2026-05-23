# OPTIMA — Design Spec
**Operator & Production Tracking Information System**
Date: 2026-05-23
Stack: Laravel (latest stable) · MySQL · Bootstrap 5 · Chart.js · Alpine.js (minimal)

---

## 1. System Overview

OPTIMA adalah aplikasi web internal untuk pabrik stamping yang menggantikan sistem manual berbasis Google Sheets + Apps Script. Sistem mencakup tiga domain utama: **Produksi**, **Quality Control**, dan **Dandori (Setup/Changeover)**.

Sistem yang sudah berjalan sejak Januari 2026 di Google Sheets (Dandori v2.5) dimigrasikan sepenuhnya ke Laravel dengan logika kalkulasi yang identik.

---

## 2. Tech Stack

| Layer | Teknologi |
|---|---|
| Backend | Laravel (latest stable) |
| Database | MySQL |
| Frontend | Blade + Bootstrap 5 + Chart.js + Alpine.js |
| Auth | Laravel Breeze |
| Audit Trail | spatie/laravel-activitylog |
| Export Excel | maatwebsite/laravel-excel |
| Export PDF | barryvdh/laravel-dompdf |
| Architecture | Full Laravel MVC + AJAX (fetch API) untuk filter dashboard |

---

## 3. Database Schema

### 3.1 Master Tables (6 tabel)

#### `master_shift`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| nama_shift | varchar | Shift 1 / Shift 2 / Shift 3 |
| jam_start | time | |
| jam_finish | time | |
| timestamps | | |

Seed default: Shift 1 (06:00–14:00), Shift 2 (14:00–22:00), Shift 3 (22:00–06:00).

#### `master_karyawan`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| nik | varchar | Unique |
| nama_karyawan | varchar | |
| departemen | enum | Produksi / QC |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `master_mesin`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| id_mesin | varchar | Kode mesin (P1, A1, dst) |
| nama_mesin | varchar | P1 - FUKUI 300T |
| line | varchar | P / A / dst |
| tipe_mesin | enum | PROGRESSIVE / STAMPING |
| kapasitas_max_stroke | integer | nullable |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `master_part`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| part_no | varchar | Unique |
| part_name | varchar | |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `master_part_proses`
Satu part dapat memiliki banyak proses.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| id_part | bigint FK | → master_part |
| id_proses | varchar | 1/2, 2/2, 1/4, dst |
| nama_proses | varchar | PROGRESSIVE, BENDING, DRAW, dst |
| sph_standar_produksi | decimal(8,2) | |
| cycle_time_standar_produksi | decimal(8,2) | detik |
| sph_standar_qc | decimal(8,2) | |
| cycle_time_standar_qc | decimal(8,2) | detik |
| std_dandori_menit | integer | Standard time dandori dalam menit |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `master_losstime`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| kode | varchar | 1–9, dst |
| jenis_losstime | varchar | Setting_Dies, Trial_QC, Mesin_Rusak, dst |
| std_menit_default | integer | nullable — untuk kalkulasi menit_over non-PROGRESSIVE |
| std_menit_progressive | integer | nullable — untuk kalkulasi menit_over PROGRESSIVE |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

> Jika `std_menit = NULL` → `menit_over = menit_loss` (full loss).
> Jika `std_menit NOT NULL` → `menit_over = max(menit_loss - std_menit, 0)`.
> Admin dapat mengubah nilai standar dari UI tanpa perlu mengubah kode.

Seed default:
| Kode | Jenis | std_default | std_progressive |
|---|---|---|---|
| 9 | Setting_Dies | 10 | 15 |
| 7 | Trial_QC | 5 | 5 |
| 1 | Mesin_Rusak | NULL | NULL |
| 2 | Tunggu_Crane | NULL | NULL |
| 3 | Tunggu_Material | NULL | NULL |
| 4 | Tunggu_Dies | NULL | NULL |
| 5 | MP_Tidak_Ada | NULL | NULL |

---

### 3.2 Transaction Tables (4 tabel)

#### `transaksi_produksi`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| tanggal_produksi | date | |
| id_shift | bigint FK | → master_shift |
| id_karyawan | bigint FK | → master_karyawan |
| id_mesin | bigint FK | → master_mesin |
| id_part_proses | bigint FK | → master_part_proses |
| jam_start | time | |
| jam_finish | time | |
| qty_aktual | integer | |
| actual_cycle_time | decimal(8,2) | detik, auto-calculated |
| created_by | bigint FK | → users |
| updated_by | bigint FK | → users, nullable |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `transaksi_qc`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| tgl_pengecekan | date | |
| tgl_produksi_part | date | |
| id_shift | bigint FK | → master_shift |
| id_karyawan_qc | bigint FK | → master_karyawan (departemen=QC) |
| id_part_proses | bigint FK | → master_part_proses |
| jam_start | time | |
| jam_finish | time | |
| qty_ok | integer | |
| qty_ng | integer | |
| actual_cycle_time | decimal(8,2) | detik, auto-calculated |
| created_by | bigint FK | → users |
| updated_by | bigint FK | → users, nullable |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `transaksi_dandori`
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| tanggal | date | |
| id_shift | bigint FK | → master_shift |
| id_karyawan | bigint FK | → master_karyawan (operator) |
| id_mesin | bigint FK | → master_mesin |
| id_part_proses | bigint FK | → master_part_proses |
| total_aktual_menit | integer | SUM(menit_loss) semua rincian |
| standard_time_menit | integer | Dari master_part_proses.std_dandori_menit |
| status | enum | OK / OVER |
| created_by | bigint FK | → users |
| updated_by | bigint FK | → users, nullable |
| deleted_at | timestamp | Soft delete |
| timestamps | | |

#### `transaksi_dandori_rincian`
Menggantikan `db_rincian` flat sheet — data dinormalisasi.

| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| id_transaksi_dandori | bigint FK | → transaksi_dandori |
| id_losstime | bigint FK | → master_losstime |
| menit_loss | integer | Durasi aktual |
| menit_over | integer | Auto-calculated saat store |
| timestamps | | |

> `month` dan `week` tidak disimpan di DB — dihitung on-the-fly dari kolom `tanggal` menggunakan Carbon. Tidak ada redundansi data.

---

### 3.3 Auth Table

#### `users` (extend Laravel default)
| Kolom | Tipe | Keterangan |
|---|---|---|
| id | bigint PK | |
| name | varchar | |
| email | varchar | Unique |
| password | varchar | Hashed |
| role | enum | admin / staff_produksi / staff_qc / manager |
| id_karyawan | bigint FK | → master_karyawan, nullable |
| timestamps | | |

---

## 4. Authentication & Authorization

### 4.1 Auth
Laravel Breeze — login/logout/session. Tidak menggunakan Jetstream.

### 4.2 Roles & Access Matrix

| Fitur | Admin | Staff Produksi | Staff QC | Manager |
|---|:---:|:---:|:---:|:---:|
| CRUD Master Data (semua) | ✅ | ❌ | ❌ | ❌ |
| CRUD Users | ✅ | ❌ | ❌ | ❌ |
| Audit Trail Log | ✅ | ❌ | ❌ | ❌ |
| Input LHP Produksi | ✅ | ✅ | ❌ | ❌ |
| Input LHP Quality | ✅ | ❌ | ✅ | ❌ |
| Input Dandori | ✅ | ✅ | ❌ | ❌ |
| VCB Produksi | ✅ | ✅ | ❌ | ✅ |
| VCB Mesin | ✅ | ✅ | ❌ | ✅ |
| VCB Quality | ✅ | ❌ | ✅ | ✅ |
| VCB Dandori | ✅ | ✅ | ❌ | ✅ |
| Export (domain masing-masing) | ✅ | ✅ | ✅ | ✅ |

### 4.3 Middleware
Custom middleware `CheckRole` di-attach ke route group. Sidebar di-render kondisional — menu yang tidak boleh diakses tidak ditampilkan (bukan disabled).

---

## 5. Business Logic & Kalkulasi

Semua kalkulasi dipisah di **Service Layer** (`app/Services/`), bukan di Controller.

### 5.1 Overnight Duration Handling
```
Jika jam_finish >= jam_start:
    duration_sec = (jam_finish - jam_start) dalam detik

Jika jam_finish < jam_start (overnight, misal 22:00 → 02:00):
    duration_sec = (86400 - jam_start_sec) + jam_finish_sec
```

### 5.2 ProduksiService
```
duration_hours       = duration_sec / 3600
actual_cycle_time    = duration_sec / qty_aktual
target_qty (per row) = duration_hours × sph_standar_produksi
```

### 5.3 QcService
```
total_qty_check      = qty_ok + qty_ng
actual_cycle_time    = duration_sec / total_qty_check
target_qc_qty        = duration_hours × sph_standar_qc
```

### 5.4 DandoriService
```
is_progressive = (nama_proses == 'PROGRESSIVE')
std_menit      = is_progressive ? losstime.std_menit_progressive
                                : losstime.std_menit_default

Jika std_menit NULL  → menit_over = menit_loss
Jika std_menit !NULL → menit_over = max(menit_loss - std_menit, 0)

total_aktual_menit = SUM(menit_loss semua rincian)
status             = total_aktual_menit > standard_time_menit ? 'OVER' : 'OK'
```

### 5.5 DashboardService — Aggregation (Deterministic)
Tidak rata-rata persentase. Hitung dari total SUM terlebih dahulu.

**VCB Produksi** (group by tanggal + id_karyawan):
```
Achievement % = (SUM(qty_aktual) / SUM(duration_hours × sph_standar_produksi)) × 100
Avg Actual SPH = SUM(qty_aktual) / SUM(duration_hours)
```

**VCB Mesin** (group by tanggal + id_mesin):
```
Achievement % = (SUM(qty_aktual) / SUM(duration_hours × sph_standar_produksi)) × 100
Avg Actual SPH = SUM(qty_aktual) / SUM(duration_hours)
```
> `kapasitas_max_stroke` di `master_mesin` disimpan sebagai referensi kapasitas mesin tetapi tidak digunakan dalam kalkulasi dashboard v1.0. Di v1.0, qty_aktual digunakan sebagai proxy output mesin. Tracking stroke aktual dapat ditambahkan di iterasi berikutnya.

**VCB Quality** (group by tgl_pengecekan + id_karyawan_qc):
```
Achievement % = (SUM(qty_ok + qty_ng) / SUM(duration_hours × sph_standar_qc)) × 100
Avg Actual SPH QC = SUM(qty_ok + qty_ng) / SUM(duration_hours)
```

**VCB Dandori** (group by id_mesin + id_losstime):
```
Total Loss per group = SUM(menit_loss)
Total Over per group = SUM(menit_over)
Filter: tanggal range, bulan, week, mesin, shift
```

### 5.6 Traffic Light
```
≥ 100% → green  (Bootstrap: success)
85–99% → yellow (Bootstrap: warning)
< 85%  → red    (Bootstrap: danger)
```

---

## 6. Dashboard Visualization

### 6.1 Filter Bar
Setiap VCB memiliki filter bar. Filter trigger AJAX → JSON response → Chart.js + tabel update tanpa reload halaman.

- VCB Produksi / Mesin / Quality: `[Tanggal Dari] [Tanggal Sampai] [Shift] [Tampilkan] [Export]`
- VCB Dandori: `[Bulan] [Week] [Mesin] [Shift] [Tampilkan] [Export]`

### 6.2 Chart Specs

| Dashboard | Chart Type | X-Axis | Bar 1 | Bar 2 | Bar 3 |
|---|---|---|---|---|---|
| VCB Produksi | Grouped Bar | Operator | Target SPH | Actual SPH (traffic light) | — |
| VCB Mesin | Grouped Bar | Mesin | Target QTY | Actual QTY (traffic light) | — |
| VCB Quality | Grouped Bar | QC Inspector | Target QTY | Actual QTY (traffic light) | Qty NG (merah) |
| VCB Dandori | Stacked Bar | Mesin | Stack per jenis_loss (menit_loss) | — | — |

VCB Dandori memiliki dua chart: satu untuk `menit_loss`, satu untuk `menit_over`.

### 6.3 API Endpoints (AJAX)
```
GET /api/vcb/produksi?date_from=&date_to=&shift=
GET /api/vcb/mesin?date_from=&date_to=&shift=
GET /api/vcb/quality?date_from=&date_to=&shift=
GET /api/vcb/dandori?bulan=&week=&mesin=&shift=

GET /export/produksi?...   → Excel / PDF
GET /export/quality?...    → Excel / PDF
GET /export/dandori?...    → Excel / PDF
```

---

## 7. Blade UI Structure

### 7.1 Layout
- `layouts/app.blade.php` — sidebar + topbar (role-aware)
- `layouts/auth.blade.php` — halaman login

Topbar: Logo OPTIMA | Role Badge | Nama User | Logout
Sidebar: Menu dikondisikan berdasarkan role (hidden jika tidak punya akses).

### 7.2 Sidebar per Role

**Admin:** Semua menu (Input Data, VCB, Master Data, Users)
**Staff Produksi:** Input LHP Produksi, Input Dandori, VCB Produksi, VCB Mesin
**Staff QC:** Input LHP Quality, VCB Quality
**Manager:** VCB Produksi, VCB Mesin, VCB Quality, VCB Dandori

### 7.3 Form Input Dandori
Form dinamis dengan rincian loss time yang bisa ditambah/hapus per baris. Alpine.js digunakan untuk:
- Kalkulasi real-time total aktual menit saat user ketik
- Auto-fill Standard Time dan Line saat user pilih Part/Proses dan Mesin
- Status badge (OK/OVER) update langsung

---

## 8. Folder Structure

```
app/
  Http/Controllers/
    Auth/LoginController.php
    Master/{Karyawan,Mesin,Part,Losstime,Shift,User}Controller.php
    Input/{Produksi,Qc,Dandori}Controller.php
    Vcb/{Produksi,Mesin,Quality,Dandori}Controller.php
    ExportController.php
  Http/Middleware/CheckRole.php
  Http/Requests/{StoreProduksi,StoreQc,StoreDandori,StoreMaster}Request.php
  Models/{MasterShift,MasterKaryawan,MasterMesin,MasterPart,MasterPartProses,
          MasterLosstime,TransaksiProduksi,TransaksiQc,TransaksiDandori,
          TransaksiDandoriRincian,User}.php
  Services/{Produksi,Qc,Dandori,Dashboard}Service.php

resources/views/
  layouts/{app,auth}.blade.php
  auth/login.blade.php
  master/{karyawan,mesin,part,losstime,shift,users}/{index,create,edit}.blade.php
  input/{produksi,qc,dandori}/{index,create}.blade.php
  vcb/{produksi,mesin,quality,dandori}.blade.php

database/
  migrations/         (11 files)
  seeders/{MasterShift,MasterLosstime,AdminUser}Seeder.php
```

---

## 9. Packages

| Package | Kegunaan |
|---|---|
| laravel/breeze | Auth (login/logout/session) |
| spatie/laravel-activitylog | Audit trail otomatis |
| maatwebsite/laravel-excel | Export Excel |
| barryvdh/laravel-dompdf | Export PDF |

---

## 10. Scope yang Tidak Termasuk (v1.0)

- Notifikasi real-time (push/email) saat achievement < 85%
- OEE (Overall Equipment Effectiveness) metric
- Multi-plant / multi-site support
- Mobile app

Fitur ini dapat ditambahkan di iterasi berikutnya setelah v1.0 stabil.
