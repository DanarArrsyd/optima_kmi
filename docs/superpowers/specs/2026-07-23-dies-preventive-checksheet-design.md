# Lampiran Checksheet PDF — Preventive Dies

**Tanggal**: 2026-07-23
**Halaman terkait**: `maintenance/availability/control-stroke-dies` → form preventive & history

## Latar Belakang

User input PREVENTIVE dies di halaman control-stroke-dies saat ini hanya catat tanggal, karyawan, catatan — tanpa bukti fisik checksheet. Perlu wajib lampirkan PDF checksheet preventive tiap input baru, sebagai bukti digital.

Codebase ini belum pernah handle file upload sama sekali (grep `Storage::`/`store(` di semua controller = 0 hasil) — fitur ini jadi pola upload pertama di project.

## Keputusan Desain

| Keputusan | Pilihan |
|---|---|
| Akses file | Private (disk `local`), serve via route ber-auth, bukan direct public URL |
| Max ukuran | 2 MB |
| Data lama tanpa file | Kolom nullable di DB, tampil "-" di history; required cuma di validasi form input baru |
| Tampil link checksheet | Di form preventive (setelah upload) DAN di history list |
| Cara buka PDF | Inline di tab baru (`Content-Disposition: inline`), bukan force-download |

## Arsitektur

Kolom baru `checksheet_path` (string, nullable) di `dies_preventive_log`. File PDF disimpan di disk `local` (`storage/app/`, private — bukan `storage/app/public`). Diakses lewat route Laravel yang cek role dulu, bukan URL langsung. `storage:link` tidak diperlukan karena disk `local`, bukan `public`.

## Komponen

1. **Migration** `add_checksheet_path_to_dies_preventive_log_table`
   - `checksheet_path` string nullable, setelah kolom `catatan`.

2. **Model** `app/Models/DiesPreventiveLog.php`
   - Tambah `checksheet_path` ke `$fillable`.

3. **Controller** `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
   - `storePreventive()`: tambah validasi
     ```php
     'checksheet' => 'required|file|mimes:pdf|max:2048',
     ```
     Simpan file:
     ```php
     $path = $request->file('checksheet')->storeAs(
         'checksheet/dies-preventive',
         $partProses->id . '_' . now()->format('YmdHis') . '.pdf',
         'local'
     );
     ```
     Masukkan `checksheet_path => $path` ke `DiesPreventiveLog::create([...])`.
   - Method baru `showChecksheet(DiesPreventiveLog $diesPreventiveLog)`:
     - `abort_if` role user bukan `admin`/`staff_maintenance` → 403.
     - `abort_if` `$diesPreventiveLog->checksheet_path` kosong ATAU file gak ada di disk (`!Storage::disk('local')->exists(...)`) → 404.
     - Return `response()->file(Storage::disk('local')->path($diesPreventiveLog->checksheet_path))` (default inline).

4. **Route** — tambah di `routes/web.php`, grup sama dgn route control-stroke-dies existing:
   ```php
   Route::get('availability/control-stroke-dies/preventive/{diesPreventiveLog}/checksheet',
       [ControlStrokeDiesController::class, 'showChecksheet'])
       ->name('control-stroke-dies.preventive.checksheet');
   ```

5. **View** `resources/views/maintenance/control-stroke-dies-preventive.blade.php`
   - Form tag tambah `enctype="multipart/form-data"`.
   - Tambah field:
     ```html
     <input type="file" name="checksheet" accept="application/pdf" required>
     ```
   - Tampilkan error validasi `checksheet` kalo ada (pola sama field lain di form ini).

6. **View** `resources/views/maintenance/control-stroke-dies-history.blade.php`
   - Tambah kolom "Checksheet" di tabel history.
   - Tiap baris: kalo `checksheet_path` ada → `<a href="{{ route('maintenance.control-stroke-dies.preventive.checksheet', $log) }}" target="_blank">Lihat PDF</a>`; kalo kosong → `-`.

## Data Flow

1. User buka form preventive → isi tanggal/karyawan/catatan → pilih file PDF → submit (multipart).
2. `storePreventive()` validasi (termasuk file wajib PDF ≤2MB) → gagal → redirect back dengan error, file gak disimpan.
3. Validasi lolos → file disimpan ke `storage/app/checksheet/dies-preventive/{nama}.pdf` → record `DiesPreventiveLog` dibuat dengan `checksheet_path` terisi → redirect sukses ke `control-stroke-dies`.
4. User buka halaman history → tiap baris preventive log tampil link "Lihat PDF" (kalo ada) → klik → route `showChecksheet` cek role → cek file exists → stream inline di tab baru.

## Error Handling

- File kosong / bukan PDF / >2MB → validation error standar Laravel Request, form re-render dengan pesan, tidak ada file tersimpan.
- `checksheet_path` NULL (log lama sebelum fitur ini) → history tampil `-`, tidak ada link.
- `checksheet_path` terisi tapi file fisik hilang dari disk → `showChecksheet` abort 404 dengan pesan "Checksheet tidak ditemukan".
- Role selain `admin`/`staff_maintenance` akses route checksheet → abort 403 (konsisten dengan `createPreventive`/`storePreventive` existing).

## Testing

Feature test baru (atau tambahan di test existing untuk `storePreventive`), pakai `Storage::fake('local')`:

- Store sukses: file PDF valid ≤2MB → `checksheet_path` tersimpan di DB, file ada di fake disk (`Storage::disk('local')->assertExists(...)`).
- Gagal tanpa file → validation error, redirect back, tidak ada record baru.
- Gagal file bukan PDF (mis. `.jpg`) → validation error `mimes`.
- Gagal file >2MB → validation error `max`.
- `showChecksheet`: role `admin`/`staff_maintenance` → 200, response file PDF.
- `showChecksheet`: role lain (`staff_produksi`, dst) → 403.
- `showChecksheet`: `checksheet_path` NULL atau file gak ada di disk → 404.
- History view: log dengan `checksheet_path` tampil link; log tanpa (NULL) tampil `-`.

## Scope

Tidak termasuk: edit/replace checksheet setelah submit (belum ada fitur edit preventive log sama sekali di codebase saat ini, jadi di luar scope). Tidak termasuk: checksheet untuk PREVENTIVE mesin (halaman terpisah `control-stroke-mesin-preventive`) — hanya dies sesuai request user.
