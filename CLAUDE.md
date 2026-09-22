# OPTIMA MES — Project Memory

## Project
**OPTIMA** — Operator & Production Tracking Information Management System
Laravel 13 ERP/MES untuk pabrik stamping.
Path: `/Applications/XAMPP/xamppfiles/htdocs/optima/`

## Stack
| Item | Detail |
|------|--------|
| Framework | Laravel 13. PHP lokal 8.5.4, **CI & prod 8.4** — sintaks yang cuma ada di 8.5 lolos lokal lalu bikin CI merah |
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

## Deploy & Migrasi

**Merge ke `main` = deploy otomatis** (GitHub Actions: lint → test → rsync-over-SSH →
composer di server → migrate → sync secret). Jangan pernah kasih instruksi upload manual.

### 🔴 Aturan wajib: migrasi baru harus didaftarkan di `deploy.yml`

Step "Migrate (targeted)" hanya menjalankan migrasi yang di-hardcode per `--path` (DB prod
schema-drift, blanket `migrate` meledak di migrasi lama). **Tiap bikin migrasi baru, tambah
barisnya di `.github/workflows/deploy.yml` pada PR yang sama.**

Lupa = **gagal senyap**: deploy tetap hijau dan mencetak `Nothing to migrate`, padahal kode
sudah live di prod tanpa kolom/tabelnya. Sudah tiga kali kejadian (`durasi_menit`, enum role
`guest`, `checksheet_path` — yang terakhir merusak produksi 4 hari tanpa ada yang lapor).

Dijaga `scripts/check-migration-coverage.sh` (jalan di CI sebelum test suite). Jalankan
lokal sebelum bikin PR. Migrasi lama yang schema-nya sudah ada di prod tercatat di
`database/migrations/.deploy-baseline` — **migrasi baru tidak boleh ditaruh di sana.**

Fitur yang memakai kolom baru tetap wajib guard `Schema::hasColumn` — kode dan schema tidak
dijamin tiba bersamaan.

## Perintah

```bash
php artisan test                          # SQLite in-memory (tidak butuh DB lokal)
./scripts/check-migration-coverage.sh     # gerbang cakupan migrasi
vendor/bin/pint app tests                 # format (scope Pint = app/ + tests/ saja)
```

## Active Work
| Task | Status |
|------|--------|
| **Akun Guest Read-Only** | ✅ DONE — role `guest`, route default-deny + middleware `DenyGuestWrite`. Akun dibuat admin di Master > Users. |
| **Gerbang cakupan migrasi** | ✅ DONE (PR #39) — CI menolak migrasi yang tak terdaftar di `deploy.yml`. |
| **Checksheet PDF Preventive Dies** | ✅ DONE — merged main (PR #34). Upload wajib checksheet PDF (max 2MB) saat input PREVENTIVE dies, disk private, serve via route role-gated, link di history. |
| **Dandori Migration** | ✅ DONE — sejak Jun 2026 (17.894 baris prod). ⚠️ `memory/projects/dandori-migration.md` masih tertulis 🔴 PENDING, sengaja dibiarkan — jangan dipercaya. |
| **Dandori Satu Alur** | ✅ DONE — live (PR #111). Centang "Ada Dandori" di form LHP Produksi menulis `transaksi_dandori_rincian`. Nol migrasi, nol rumus disentuh. Blok sengaja TIDAK ada di form Edit. |
| **LHP Batch Input** | ✅ DONE — live (PR #114). Input LHP Produksi jadi halaman batch sendiri, laci penulis di halaman daftar dicabut, `input.produksi.store` pensiun (tinggal `input.produksi.batch`). Draft di `localStorage`, nol migrasi. ⚠️ 50 baris dikirim sebagai satu `rows_json` — `max_input_vars`=1000 memotong input per-baris secara SENYAP. ⚠️ QC, Dandori, Breakdown Mesin & Breakdown Dies MASIH pakai laci; laci sisa di `input/produksi/index.blade.php` itu laci **note**, bukan penulis — jangan ikut dicabut. |
| **Halaman Login** | ✅ DONE — live (PR #120–122). `public/img/login_page.png` dipakai sebagai kanvas utuh yang mengisi penuh layar; panel diposisikan dalam persen, ukuran pakai `cqw`. ⚠️ JANGAN `background-size:cover` dan JANGAN kunci rasio 16:9 (dua-duanya menggeser panel/menyisakan bilah kosong). Aturan CSS dasar wajib DI ATAS media query-nya. Tombol login tidak boleh pernah `disabled` — ada test penjaganya. |
| **Filter Mesin VCB Produksi/Mesin** | ✅ DONE — live (PR #178, superseded PR #177). Filter LINE checkbox awalnya (PR #177) diganti dropdown "Semua Mesin" multi-select grouped-by-line, identik LHP Produksi. `mesinFilterComp()` dipromote dari bespoke (LHP Produksi doang) jadi shared Alpine component di `layouts/app.blade.php` + partial `partials/mesin-picker.blade.php`, dipakai 3 halaman (LHP Produksi, VCB Mesin, VCB Produksi Mode 1 & 2). `DashboardService` filter langsung `whereIn('id_mesin', ...)`, bukan lewat `line`. ⚠️⚠️ Tiap halaman yang pasang `x-data="mesinFilterComp(_mesinForJs, ...)"` WAJIB juga emit `const _mesinForJs = @json($_mesinForJs);` di `@push('scripts')` — lupa berarti Alpine `ReferenceError` senyap di browser (server HTML tetep valid, SEMUA test PHPUnit tetep hijau karena nol eksekusi JS). Kejadian pas PR #178: lolos 4 review per-task, ketangkep final whole-branch review doang. ⚠️ `KomponenBersamaTest` kunci `mesinFilterComp(` cuma boleh satu definisi di layout. |

## Catatan operasional

- **Jangan usulkan "coba input satu data di prod" untuk verifikasi.** Input PREVENTIVE
  (dies & mesin) mereset siklus stroke ke 0 dan tidak bisa ditarik balik. Verifikasi lewat
  test suite + probe read-only ke schema.
- **Fitur rusak bisa lewat berhari-hari tanpa keluhan** — laporan user bukan alat deteksi.
- App berjalan **UTC**, pabrik **WIB**. Jangan set `APP_TIMEZONE` (memundurkan riwayat
  secara senyap); konversi di lapisan tampilan.

→ Detail proyek: `memory/projects/`, context: `memory/context/`
