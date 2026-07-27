# OPTIMA — Architecture Decision Record

> Dokumen hidup. Menjelaskan **kenapa** sistem ini berbentuk seperti sekarang, bukan
> **apa** isinya — struktur kode sudah terbaca dari repo. Isi di sini adalah keputusan
> dan konsekuensinya, termasuk yang mahal.
>
> Terakhir diperbarui: 2026-07-27 (commit `c0e9d13`)

---

## PURPOSE

OPTIMA — Operator & Production Tracking Information Management System. MES/ERP internal untuk pabrik stamping (PT Kenco Manufactur). Menggantikan pencatatan Excel untuk: LHP produksi, quality (NG), dandori (changeover), breakdown mesin & dies, preventive maintenance, dan analitik OEE/MTTR/MTBF/Availability.

Bukan produk multi-tenant. Satu instansi, satu pabrik, akun dibuat admin — tidak ada registrasi publik (`routes/auth.php` sengaja tidak punya route register).

Prod: optima.kencomanufactur.co.id

---

## STACK

| Item | Detail |
|------|--------|
| Framework | Laravel 13, PHP 8.5.4 |
| DB | MySQL (`optima_db`); test suite pakai SQLite in-memory — 471 test, tidak butuh DB lokal |
| Frontend | Blade + Bootstrap 5.3.3, Bootstrap Icons 1.11.3, Alpine.js 3.14.1, Chart.js 4.4.4 |
| Excel | maatwebsite/excel 4.x |
| Chatbot | Provider OpenAI-compatible, DeepSeek primary (`config/services.php` → `DEEPSEEK_API_KEY`) |
| Deploy | GitHub Actions → rsync-over-SSH. Merge ke `main` = deploy otomatis |

Implementasi chatbot alternatif (Gemini, Groq, Cerebras) ada di `app/Services/`, tapi semua free tier menabrak batas TPM pada beban kueri nyata.

Design language: industrial monochrome. Arial di mana-mana, tanpa shadow, topbar `#111827`, sidebar `#1f2937`.

---

## ARCHITECTURE

Laravel MVC standar dengan satu lapisan tambahan: **Service layer** yang memegang semua logika agregasi/analitik. Controller tipis, Service tebal — knowledge graph mencatat paket `Services` dengan fan-in tertinggi di seluruh project (449 masuk / 29 keluar).

### Pembagian controller mengikuti domain, bukan model

| Namespace | Isi |
|---|---|
| `Http/Controllers/Input` | Produksi, Qc, Dandori — form entry harian |
| `Http/Controllers/Vcb` | VcbProduksi, VcbQuality, VcbMesin, VcbDandori — dashboard rekap |
| `Http/Controllers/Maintenance` | Breakdown mesin/dies, MTTR, MTBF, Availability, ControlStroke mesin/dies |
| `Http/Controllers/Master` | CRUD master data (Part, Mesin, Karyawan, Shift, JenisNg, Losstime, User) |
| `Http/Controllers/Admin` | AnomalyController |
| `Http/Controllers/Auth` | Breeze bawaan, minus register |

Service yang setara: `DashboardService` (VCB), `ProduksiService`, `QcService`, `MaintenanceDashboardService`, `MaintenanceDiesDashboardService`, `BreakdownMesinService`, `BreakdownDiesService`, `OeeService`, `SphRecommendationService`, `NotificationService`.

### Model

21 Eloquent model dengan konvensi prefiks:

- `Master*` — data referensi (`MasterPart`, `MasterMesin`, `MasterKaryawan`, `MasterShift`, `MasterJenisNg`, `MasterLosstime`, `MasterPartProses`, …)
- `Transaksi*` — data transaksional (`TransaksiProduksi`, `TransaksiQc`, `TransaksiDandori` + `TransaksiDandoriRincian`, `TransaksiBreakdownMesin`, `TransaksiBreakdownDies`)
- `DiesPreventiveLog` / `MesinPreventiveLog` — siklus preventive

### Otorisasi — default-deny 3 lapis

1. **`routes/web.php`** — semua di dalam `Route::middleware('auth')`, lalu tiap grup dibungkus `role:...` eksplisit. Tidak ada route tanpa daftar role.
2. **`CheckRole`** (alias `role`) — memvalidasi role per grup.
3. **`DenyGuestWrite` + `SecurityHeaders`** — di-append global di `bootstrap/app.php` pada stack `web`, bukan hanya grup auth, supaya route di `routes/auth.php` (mis. `password.update`) ikut tercakup.

Grup route sengaja dipecah dua per domain: satu grup read yang menyertakan `guest`, satu grup write tanpa `guest`.

Role: `admin`, `staff_produksi`, `staff_qc`, `staff_maintenance`, `manager`, `guest` (read-only).

### Chatbot — capability registry self-registering

`app/Services/Chatbot/`:

| Komponen | Peran |
|---|---|
| `CapabilityRegistry` | Menemukan dan mencocokkan capability |
| `AbstractCapability` + 19 capability konkret | TopProblemDies, MtbfMttrMesin, OeeMesin, RekapNg, RootCause, Trend, QueryDatabase, dll. |
| `ToolSchemaCatalog` | Menurunkan skema tool dari capability |
| `BusinessTableCatalog` | Membatasi tabel yang boleh disentuh |
| `SqlGuard` | Guard SQL dua lapis (celah backtick sudah ditutup) |

Menambah kemampuan chatbot = menambah satu class capability, tanpa menyentuh orkestrator.

---

## PATTERNS

- **SoftDeletes di semua model.**
- **Import: `firstOrNew()` + set `deleted_at = null` langsung + `save()`** — bukan `updateOrCreate`, karena `deleted_at` tidak ada di `$fillable` sehingga `updateOrCreate` tidak bisa menghidupkan baris yang ter-soft-delete.
- **Pagination: `Paginator::useBootstrapFive()` di `AppServiceProvider`** — wajib; default Laravel merender SVG Tailwind yang rusak di layout ini.
- **VCB Produksi & Quality dua mode** — Mode 1: ringkasan semua operator/inspector. Mode 2: drill-down per orang per tanggal.
- **Guard skema wajib** — fitur yang memakai kolom baru harus dibungkus `Schema::hasColumn`. Kode dan skema tidak dijamin tiba bersamaan di prod.
- **Auth view pakai `layouts.auth`** — nol `@vite`, agar halaman login tidak bergantung pada build asset.
- Blade + Alpine untuk interaktivitas ringan. Tidak ada SPA, tidak ada API JSON publik.

---

## TRADEOFFS

### Migrasi di-deploy targeted, bukan blanket

Step "Migrate" di `.github/workflows/deploy.yml` menjalankan migrasi yang di-hardcode per `--path`, karena skema DB prod sudah drift dan `php artisan migrate` polos meledak di migrasi lama.

Konsekuensi: **tiap migrasi baru wajib didaftarkan di `deploy.yml` pada PR yang sama.**

Lupa = **gagal senyap** — deploy tetap hijau dan mencetak `Nothing to migrate`, sementara kode sudah live di prod tanpa kolomnya. Sudah tiga kali kejadian: `durasi_menit`, enum role `guest`, dan `checksheet_path` — yang terakhir merusak produksi 4 hari tanpa ada yang lapor.

Dijaga `scripts/check-migration-coverage.sh` di CI sebelum test suite. Migrasi lama yang skemanya sudah ada di prod dicatat di `database/migrations/.deploy-baseline`; **migrasi baru tidak boleh masuk ke sana.**

### App berjalan UTC, pabrik beroperasi WIB

`APP_TIMEZONE` sengaja **TIDAK** diset — mengesetnya memundurkan seluruh riwayat secara senyap (~6 bulan data). Konversi dilakukan di lapisan tampilan.

### Branch protection tidak dipakai

Repo private di plan gratis; GitHub API menolak dengan 403. CI test tetap jadi gerbang deploy, tapi penegakannya lewat workflow, bukan proteksi branch.

### Chatbot bayar (DeepSeek), bukan gratis

Semua provider free tier menabrak batas TPM pada beban kueri nyata.

### Guest tidak bisa ganti password

Konsekuensi sengaja dari `DenyGuestWrite` global. Reset dilakukan admin.

---

## PHILOSOPHY

**Laporan user bukan alat deteksi.** Fitur rusak terbukti bisa lewat berhari-hari tanpa keluhan (`checksheet_path`: 4 hari). Karena itu verifikasi bertumpu pada test suite (471 test) + gerbang CI, bukan pada pemakaian di lapangan.

**Jangan verifikasi dengan menulis ke prod.** Input PREVENTIVE (dies & mesin) mereset siklus stroke ke 0 dan tidak bisa ditarik balik. Verifikasi lewat test suite + probe read-only ke skema.

**Kode defensif terhadap skema.** Deploy kode dan migrasi DB adalah dua peristiwa terpisah yang bisa gagal independen; kode harus selamat saat kolomnya belum ada.

**Default-deny untuk akses.** Route tidak pernah terbuka lalu ditambal; tiap grup mendeklarasikan role yang boleh. Menambah role baru berarti menyentuh setiap grup yang relevan secara eksplisit — verbose, tapi tidak ada yang bocor karena lupa.

**Tidak ada permukaan publik.** Registrasi publik pernah ada dan menjadi lubang nyata — siapa pun dari internet bisa membuat akun `staff_produksi`. Dicabut total di `e4f4321`; audit prod bersih.
