# OPTIMA MES — Project Memory

## Project
**OPTIMA** — Operator & Production Tracking Information Management System
Laravel 13 ERP/MES untuk pabrik stamping.
Path: `/Applications/XAMPP/xamppfiles/htdocs/optima/`

## Stack
| Item | Detail |
|------|--------|
| Framework | Laravel 13, PHP 8.5.4 |
| DB | MySQL via XAMPP (`optima_db`) |
| Frontend | Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Alpine.js 3.14.1, Chart.js 4.4.4 |
| Excel | maatwebsite/excel 4.x |
| Design | Industrial monochrome — Arial everywhere, no shadows, #111827 topbar, #1f2937 sidebar |

## Roles
| Role | Akses |
|------|-------|
| `admin` | Semua fitur |
| `staff_produksi` | Input LHP Produksi, Dandori, VCB Produksi/Mesin |
| `staff_qc` | Input LHP Quality, VCB Quality |
| `staff_maintenance` | Breakdown Mesin/Dies, analitik MTTR/MTBF/Availability, Control Stroke |
| `manager` | VCB semua, Export |
| `guest` | Read-only: VCB, analitik maintenance, daftar LHP. Tanpa export, master data, breakdown, chatbot |

## Key Patterns
- SoftDeletes semua model
- Import: `firstOrNew()` + direct set `deleted_at = null` + `save()` (bukan `updateOrCreate` karena `deleted_at` tidak di `$fillable`)
- Pagination: `Paginator::useBootstrapFive()` di `AppServiceProvider` (WAJIB — default Laravel pakai Tailwind SVG)
- DashboardService pattern: semua logika VCB di `app/Services/DashboardService.php`
- VCB Produksi & Quality: dua mode — Mode 1 (semua operator/inspector summary) + Mode 2 (per orang drill-down per tanggal)

## Active Work
| Task | Status |
|------|--------|
| **Akun Guest Read-Only** | ✅ DONE — role `guest`, route default-deny + middleware `DenyGuestWrite`. Akun dibuat admin di Master > Users. |
| **Checksheet PDF Preventive Dies** | ✅ DONE — merged main (PR #34). Upload wajib checksheet PDF (max 2MB) saat input PREVENTIVE dies, disk private, serve via route role-gated, link di history. |
| **Dandori Migration** | ✅ DONE — sejak Jun 2026, lihat `memory/projects/dandori-migration.md` |

→ Detail proyek: `memory/projects/`, context: `memory/context/`
