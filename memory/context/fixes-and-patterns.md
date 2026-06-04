# Bug Fixes & Patterns Penting

## Bug Fixes Session Ini

### 1. Import Soft-Delete Bug (FIXED)
**Problem:** Semua import class pakai `updateOrCreate(..., ['deleted_at' => null])` lewat
mass-assignment, tapi `deleted_at` tidak ada di `$fillable` model → silent ignore → record
soft-deleted tidak ter-restore.

**Fix:** Ganti ke `firstOrNew()` + directly set `$record->deleted_at = null` + `$record->save()`

**Files fixed:** `LosstimeImport.php`, `KaryawanImport.php`, `MesinImport.php`,
`PartImport.php` (Part + PartProses), `ShiftImport.php`

---

### 2. Pagination Tailwind SVG Bug (FIXED)
**Problem:** Laravel default pakai Tailwind pagination view dengan SVG icons
(`w-5 h-5` Tailwind class). Karena kita tidak pakai Tailwind, SVG render 723×723px →
muncul sebagai bentuk gelap raksasa di bawah tabel.

**Fix:** Tambah `Paginator::useBootstrapFive()` di `AppServiceProvider::boot()`

**File:** `app/Providers/AppServiceProvider.php`

⚠️ JANGAN HAPUS ini — setiap project Laravel baru wajib ada ini!

---

### 3. CSS Universal Selector (FIXED)
**Problem:** `*, *::before, *::after { font-family: Arial !important }` bisa
conflict dengan Bootstrap Icons font di `::before` pseudo-elements.

**Fix:** Pisahkan jadi:
```css
*, *::before, *::after { box-sizing: border-box; }
* { font-family: Arial, Helvetica, sans-serif !important; }
```

---

## Patterns Standar OPTIMA

### VCB Two-Mode Pattern
Semua halaman VCB (Produksi, Quality) pakai dua mode:
- **Mode 1:** Semua operator/inspector summary — chart bar ranking + tabel dengan progress bar
- **Mode 2:** Per orang drill-down — chart per tanggal + expandable daily detail table
- Click row di Mode 1 → switch ke Mode 2 dengan operator ter-select

### DashboardService Methods
- `getVcbProduksi()` — summary semua operator
- `getVcbProduksiByDate()` — detail per tanggal untuk 1 operator
- `getVcbQuality()` — summary semua inspector QC
- `getVcbQualityByDate()` — detail per tanggal untuk 1 inspector

### % Column (LHP Tables)
Tidak disimpan ke DB — dihitung on-the-fly di view:
`$pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null`

### Sidebar State
- `optima_sb_open` di localStorage
- CSS class `sb-pinned` di body → sidebar 220px
- Default (tanpa class): sidebar 0px (hidden)
- Dua state saja: hidden ↔ expanded

## Login Page
Split layout: panel kiri gelap (logo perusahaan + branding) + panel kanan card form
Logo: `public/logo.png` (512×512 company logo, displayed 120px di login, 26px di topbar)
