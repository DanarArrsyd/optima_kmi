# Lampiran Checksheet PDF Preventive Dies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wajibkan upload PDF checksheet preventive saat input PREVENTIVE dies di halaman control-stroke-dies, simpan private, dan tampilkan link view-nya di form + history.

**Architecture:** Kolom `checksheet_path` (nullable) di `dies_preventive_log`. File disimpan di disk `local` (private, bukan `storage/app/public`). Route baru `showChecksheet` cek role lalu stream file inline — tidak ada direct public URL.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL (prod) / SQLite (test via `RefreshDatabase`), Laravel `Storage` facade + fake disk untuk test.

## Global Constraints

- Max ukuran file: 2048 KB (2 MB) — exact validation rule `max:2048`.
- Format: hanya PDF — `mimes:pdf`.
- Akses hanya role `admin` dan `staff_maintenance` (sama seperti `createPreventive`/`storePreventive` existing) — bukan `manager`.
- Kolom DB nullable (data lama tanpa file tetap valid); wajib hanya via validasi form input baru.
- Disk `local`, path prefix `checksheet/dies-preventive/`.
- Nama file: `{id_part_proses}_{YmdHis}.pdf`.

---

### Task 1: Migration — kolom `checksheet_path`

**Files:**
- Create: `database/migrations/2026_07_23_000001_add_checksheet_path_to_dies_preventive_log_table.php`

**Interfaces:**
- Produces: kolom `checksheet_path` (string, nullable) di tabel `dies_preventive_log`, dipakai Task 2 & 3.

- [ ] **Step 1: Tulis migration (idempotent, pola sama dgn `2026_07_17_014834_add_durasi_menit_to_transaksi_produksi.php`)**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dies_preventive_log', 'checksheet_path')) {
            Schema::table('dies_preventive_log', function (Blueprint $table) {
                $table->string('checksheet_path')->nullable()->after('catatan');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dies_preventive_log', 'checksheet_path')) {
            Schema::table('dies_preventive_log', function (Blueprint $table) {
                $table->dropColumn('checksheet_path');
            });
        }
    }
};
```

- [ ] **Step 2: Jalankan migration lokal, verifikasi kolom ada**

Run: `php artisan migrate`
Expected output: baris `... add_checksheet_path_to_dies_preventive_log_table ... DONE`

Verifikasi:
Run: `php artisan tinker --execute="var_dump(Schema::hasColumn('dies_preventive_log', 'checksheet_path'));"`
Expected: `bool(true)`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_23_000001_add_checksheet_path_to_dies_preventive_log_table.php
git commit -m "feat(maintenance): tambah kolom checksheet_path di dies_preventive_log"
```

---

### Task 2: Store checksheet PDF saat input preventive (validasi wajib + simpan file)

**Files:**
- Modify: `app/Models/DiesPreventiveLog.php` (fillable)
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php:65-93` (`storePreventive`)
- Modify: `tests/Feature/Maintenance/ControlStrokeDiesTest.php` (test existing yang assert store sukses harus attach file; tambah test baru)

**Interfaces:**
- Consumes: kolom `checksheet_path` dari Task 1.
- Produces: `DiesPreventiveLog->checksheet_path` terisi path string setelah store sukses. Dipakai Task 4 (`showChecksheet`) dan Task 6 (history view).

- [ ] **Step 1: Tambah `checksheet_path` ke fillable model**

Edit `app/Models/DiesPreventiveLog.php`:

```php
    protected $fillable = [
        'id_part_proses', 'tanggal_preventive', 'id_karyawan',
        'stroke_at_preventive', 'catatan', 'checksheet_path', 'created_by',
    ];
```

- [ ] **Step 2: Tulis failing test — store sukses dengan checksheet PDF**

Tambah di `tests/Feature/Maintenance/ControlStrokeDiesTest.php`, ganti baris `use Illuminate\Foundation\Testing\RefreshDatabase;` jadi tambah import di atasnya:

```php
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
```

Tambah method baru (taruh setelah `test_admin_can_store_preventive_and_it_resets_the_running_cycle`):

```php
    public function test_store_preventive_saves_checksheet_pdf(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 30500);

        $file = UploadedFile::fake()->create('checksheet.pdf', 100, 'application/pdf');

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'catatan'            => 'Preventive rutin',
            'checksheet'         => $file,
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-dies'));

        $log = DiesPreventiveLog::first();
        $this->assertNotNull($log->checksheet_path);
        Storage::disk('local')->assertExists($log->checksheet_path);
    }
```

- [ ] **Step 3: Jalankan test, verifikasi gagal**

Run: `php artisan test --filter test_store_preventive_saves_checksheet_pdf`
Expected: FAIL — `checksheet_path` NULL (kolom belum diisi controller, request field `checksheet` diabaikan).

- [ ] **Step 4: Implementasi — validasi wajib PDF + simpan file di `storePreventive`**

Ganti method `storePreventive` di `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php:65-93` jadi:

```php
    public function storePreventive(MasterPartProses $partProses, Request $request)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        $validated = $request->validate([
            'tanggal_preventive' => 'required|date|before_or_equal:today',
            'id_karyawan'        => 'required|exists:master_karyawan,id',
            'catatan'            => 'nullable|string|max:1000',
            'checksheet'         => 'required|file|mimes:pdf|max:2048',
        ]);

        $currentStroke = $this->svc->currentStrokeFor($partProses);

        $checksheetPath = $request->file('checksheet')->storeAs(
            'checksheet/dies-preventive',
            $partProses->id . '_' . now()->format('YmdHis') . '.pdf',
            'local'
        );

        DiesPreventiveLog::create([
            'id_part_proses'       => $partProses->id,
            'tanggal_preventive'   => $validated['tanggal_preventive'],
            'id_karyawan'          => $validated['id_karyawan'],
            'stroke_at_preventive' => $currentStroke,
            'catatan'              => $validated['catatan'] ?? null,
            'checksheet_path'      => $checksheetPath,
            'created_by'           => auth()->id(),
        ]);

        $partNo = $partProses->part?->part_no;

        return redirect()->route('maintenance.control-stroke-dies')->with(
            'success',
            "Preventive {$partNo} — {$partProses->nama_proses} tersimpan. Stroke direset dari "
            . number_format($currentStroke, 0, ',', '.') . ' ke 0.'
        );
    }
```

- [ ] **Step 5: Jalankan test baru, verifikasi lolos**

Run: `php artisan test --filter test_store_preventive_saves_checksheet_pdf`
Expected: PASS

- [ ] **Step 6: Perbaiki test existing yang break krn checksheet sekarang wajib**

Validasi sekarang wajib `checksheet` — 2 test positive-flow existing bakal gagal (redirect ke back dgn error, bukan sukses) krn gak kirim file. Edit `tests/Feature/Maintenance/ControlStrokeDiesTest.php`:

`test_admin_can_store_preventive_and_it_resets_the_running_cycle` — tambah `Storage::fake('local');` di awal method dan tambah field `checksheet`:

```php
    public function test_admin_can_store_preventive_and_it_resets_the_running_cycle(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 30500);

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'catatan'            => 'Preventive rutin',
            'checksheet'         => UploadedFile::fake()->create('checksheet.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-dies'));
        $response->assertSessionHas('success');

        $log = DiesPreventiveLog::first();
        $this->assertSame(30500, $log->stroke_at_preventive);
        $this->assertSame($f['admin']->id, $log->created_by);

        $dataResponse = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.data'));
        $dataResponse->assertJsonFragment(['current_stroke' => 0, 'status' => 'ok']);
    }
```

`test_staff_maintenance_can_store_preventive` — tambah `Storage::fake('local');` dan field `checksheet`:

```php
    public function test_staff_maintenance_can_store_preventive(): void
    {
        Storage::fake('local');
        $f     = $this->fixtures();
        $staff = User::factory()->create(['role' => 'staff_maintenance']);

        $response = $this->actingAs($staff)->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'checksheet'         => UploadedFile::fake()->create('checksheet.pdf', 100, 'application/pdf'),
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-dies'));
        $this->assertDatabaseCount('dies_preventive_log', 1);
    }
```

`test_store_preventive_rejects_future_date` dan `test_store_preventive_rejects_unknown_karyawan` — tidak perlu diubah (mereka menegaskan error key spesifik, tetap valid walau `checksheet` juga error krn tidak dikirim).

- [ ] **Step 7: Tambah test negatif untuk checksheet**

Tambah 3 method baru setelah `test_store_preventive_rejects_unknown_karyawan`:

```php
    public function test_store_preventive_rejects_missing_checksheet(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertSessionHasErrors('checksheet');
        $this->assertDatabaseCount('dies_preventive_log', 0);
    }

    public function test_store_preventive_rejects_non_pdf_checksheet(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'checksheet'         => UploadedFile::fake()->create('checksheet.jpg', 100, 'image/jpeg'),
        ]);

        $response->assertSessionHasErrors('checksheet');
        $this->assertDatabaseCount('dies_preventive_log', 0);
    }

    public function test_store_preventive_rejects_oversized_checksheet(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'checksheet'         => UploadedFile::fake()->create('checksheet.pdf', 2049, 'application/pdf'),
        ]);

        $response->assertSessionHasErrors('checksheet');
        $this->assertDatabaseCount('dies_preventive_log', 0);
    }
```

- [ ] **Step 8: Jalankan seluruh file test, verifikasi semua lolos**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS semua (13 test)

- [ ] **Step 9: Commit**

```bash
git add app/Models/DiesPreventiveLog.php app/Http/Controllers/Maintenance/ControlStrokeDiesController.php tests/Feature/Maintenance/ControlStrokeDiesTest.php
git commit -m "feat(maintenance): wajibkan upload checksheet PDF saat input preventive dies"
```

---

### Task 3: Route + method `showChecksheet` (serve file private)

**Files:**
- Modify: `routes/web.php:178-183`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php` (tambah method + import `Storage`)
- Modify: `tests/Feature/Maintenance/ControlStrokeDiesTest.php` (tambah test)

**Interfaces:**
- Consumes: `DiesPreventiveLog->checksheet_path` dari Task 2.
- Produces: route `maintenance.control-stroke-dies.preventive.checksheet` (param model `DiesPreventiveLog`), dipakai Task 6 (link di history view).

- [ ] **Step 1: Tulis failing test — akses checksheet by admin sukses 200**

Tambah di `tests/Feature/Maintenance/ControlStrokeDiesTest.php`, setelah test-test Task 2:

```php
    public function test_admin_can_view_checksheet_file(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();

        $path = Storage::disk('local')->putFileAs(
            'checksheet/dies-preventive',
            UploadedFile::fake()->create('checksheet.pdf', 100, 'application/pdf'),
            'test.pdf'
        );
        $log = DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'checksheet_path' => $path, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-dies.preventive.checksheet', $log));

        $response->assertOk();
    }

    public function test_manager_cannot_view_checksheet_file(): void
    {
        Storage::fake('local');
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $path = Storage::disk('local')->putFileAs(
            'checksheet/dies-preventive',
            UploadedFile::fake()->create('checksheet.pdf', 100, 'application/pdf'),
            'test.pdf'
        );
        $log = DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'checksheet_path' => $path, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($manager)->get(route('maintenance.control-stroke-dies.preventive.checksheet', $log));

        $response->assertForbidden();
    }

    public function test_checksheet_file_returns_404_when_missing(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();

        $log = DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'checksheet_path' => null, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-dies.preventive.checksheet', $log));

        $response->assertNotFound();
    }
```

- [ ] **Step 2: Jalankan test, verifikasi gagal**

Run: `php artisan test --filter test_admin_can_view_checksheet_file`
Expected: FAIL — route `maintenance.control-stroke-dies.preventive.checksheet` belum terdaftar (RouteNotFoundException).

- [ ] **Step 3: Tambah route**

Edit `routes/web.php`, sisipkan baris baru sebelum penutup grup di baris 183 (setelah baris 182):

```php
        Route::post('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'storePreventive'])->name('control-stroke-dies.preventive');
        Route::get('availability/control-stroke-dies/preventive/{diesPreventiveLog}/checksheet', [ControlStrokeDiesController::class, 'showChecksheet'])->name('control-stroke-dies.preventive.checksheet');
    });
```

- [ ] **Step 4: Tambah method `showChecksheet` di controller**

Edit `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`. Tambah import setelah `use App\Services\MaintenanceDiesDashboardService;`:

```php
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
```

Tambah method baru setelah `storePreventive`:

```php
    public function showChecksheet(DiesPreventiveLog $diesPreventiveLog)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        if (!$diesPreventiveLog->checksheet_path || !Storage::disk('local')->exists($diesPreventiveLog->checksheet_path)) {
            abort(404, 'Checksheet tidak ditemukan.');
        }

        return response()->file(Storage::disk('local')->path($diesPreventiveLog->checksheet_path));
    }
```

- [ ] **Step 5: Jalankan test, verifikasi lolos**

Run: `php artisan test --filter "test_admin_can_view_checksheet_file|test_manager_cannot_view_checksheet_file|test_checksheet_file_returns_404_when_missing"`
Expected: PASS (3 test)

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Http/Controllers/Maintenance/ControlStrokeDiesController.php tests/Feature/Maintenance/ControlStrokeDiesTest.php
git commit -m "feat(maintenance): route + endpoint serve checksheet PDF preventive dies"
```

---

### Task 4: Form input — field upload PDF

**Files:**
- Modify: `resources/views/maintenance/control-stroke-dies-preventive.blade.php:42-78`
- Modify: `tests/Feature/Maintenance/ControlStrokeDiesTest.php` (tambah test)

**Interfaces:**
- Consumes: tidak ada dependency baru dari task lain (murni tampilan form yang sudah POST ke route existing).

- [ ] **Step 1: Tulis failing test — form page tampilkan input file**

Tambah di `tests/Feature/Maintenance/ControlStrokeDiesTest.php` setelah `test_admin_can_view_preventive_form_page`:

```php
    public function test_preventive_form_page_has_checksheet_file_input(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-dies.preventive.create', $f['proses']->id));

        $response->assertOk();
        $response->assertSee('enctype="multipart/form-data"', false);
        $response->assertSee('name="checksheet"', false);
    }
```

- [ ] **Step 2: Jalankan test, verifikasi gagal**

Run: `php artisan test --filter test_preventive_form_page_has_checksheet_file_input`
Expected: FAIL — form belum punya `enctype` atau input `checksheet`.

- [ ] **Step 3: Edit view — tambah `enctype` dan field file**

Edit `resources/views/maintenance/control-stroke-dies-preventive.blade.php`, baris 42 ganti:

```html
        <form method="POST" action="{{ route('maintenance.control-stroke-dies.preventive', $partProses) }}" enctype="multipart/form-data">
```

Baris 71-77 (blok Catatan), tambah field checksheet setelahnya — ganti blok:

```html
                <div class="col-12">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" rows="3"
                              class="form-control @error('catatan') is-invalid @enderror"
                              placeholder="Opsional — mis. ganti pisau, cek clearance">{{ old('catatan') }}</textarea>
                    @error('catatan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label">Checksheet Preventive (PDF) <span class="text-danger">*</span></label>
                    <input type="file" name="checksheet" accept="application/pdf"
                           class="form-control @error('checksheet') is-invalid @enderror">
                    @error('checksheet')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Maks. 2 MB, format PDF.</div>
                </div>
```

- [ ] **Step 4: Jalankan test, verifikasi lolos**

Run: `php artisan test --filter test_preventive_form_page_has_checksheet_file_input`
Expected: PASS

- [ ] **Step 5: Jalankan seluruh file test, pastikan tidak ada regresi**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS semua

- [ ] **Step 6: Commit**

```bash
git add resources/views/maintenance/control-stroke-dies-preventive.blade.php tests/Feature/Maintenance/ControlStrokeDiesTest.php
git commit -m "feat(maintenance): form input preventive dies wajib upload checksheet PDF"
```

---

### Task 5: History view — kolom link checksheet

**Files:**
- Modify: `resources/views/maintenance/control-stroke-dies-history.blade.php:77-99`
- Modify: `tests/Feature/Maintenance/ControlStrokeDiesTest.php` (tambah test)

**Interfaces:**
- Consumes: route `maintenance.control-stroke-dies.preventive.checksheet` dari Task 3; `checksheet_path` dari Task 2.

- [ ] **Step 1: Tulis failing test — history tampilkan link kalo ada file, dash kalo tidak**

Tambah di `tests/Feature/Maintenance/ControlStrokeDiesTest.php` setelah `test_history_endpoint_returns_logs_for_requested_dies_only`:

```php
    public function test_history_shows_checksheet_link_when_present(): void
    {
        Storage::fake('local');
        $f = $this->fixtures();

        $path = Storage::disk('local')->putFileAs(
            'checksheet/dies-preventive',
            UploadedFile::fake()->create('checksheet.pdf', 100, 'application/pdf'),
            'test.pdf'
        );
        $log = DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'checksheet_path' => $path, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-dies.history', $f['proses']->id));

        $response->assertOk();
        $response->assertSee(route('maintenance.control-stroke-dies.preventive.checksheet', $log), false);
        $response->assertSee('Lihat PDF');
    }

    public function test_history_shows_dash_when_checksheet_missing(): void
    {
        $f = $this->fixtures();
        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'checksheet_path' => null, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-dies.history', $f['proses']->id));

        $response->assertOk();
        $response->assertDontSee('Lihat PDF');
    }
```

- [ ] **Step 2: Jalankan test, verifikasi gagal**

Run: `php artisan test --filter "test_history_shows_checksheet_link_when_present|test_history_shows_dash_when_checksheet_missing"`
Expected: FAIL pada test pertama — kolom/link checksheet belum ada di view.

- [ ] **Step 3: Edit view — tambah kolom Checksheet**

Edit `resources/views/maintenance/control-stroke-dies-history.blade.php` baris 77-81, ganti header:

```html
                    <tr>
                        <th>Tanggal</th><th>PIC / Petugas</th>
                        <th class="text-end">Stroke Tercapai</th>
                        <th>Catatan</th><th>Checksheet</th><th>Diinput Oleh</th>
                    </tr>
```

Baris 84-91 (row data), ganti:

```html
                @forelse($logs as $log)
                    <tr>
                        <td style="white-space:nowrap;font-weight:600">{{ $log->tanggal_preventive->format('d M Y') }}</td>
                        <td style="white-space:nowrap">{{ $log->karyawan?->nama_karyawan ?? '—' }}</td>
                        <td class="text-end" style="white-space:nowrap">{{ number_format($log->stroke_at_preventive, 0, ',', '.') }}</td>
                        <td>{{ $log->catatan ?? '—' }}</td>
                        <td style="white-space:nowrap">
                            @if($log->checksheet_path)
                                <a href="{{ route('maintenance.control-stroke-dies.preventive.checksheet', $log) }}" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-pdf"></i> Lihat PDF
                                </a>
                            @else
                                —
                            @endif
                        </td>
                        <td style="white-space:nowrap">{{ $log->createdBy?->name ?? '—' }}</td>
                    </tr>
```

Baris 92-98 (empty state), ganti `colspan="5"` jadi `colspan="6"`:

```html
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-clock-history d-block mb-2" style="font-size:1.6rem;color:#9ca3af"></i>
                            Belum ada riwayat preventive untuk dies ini.
                        </td>
                    </tr>
```

- [ ] **Step 4: Jalankan test, verifikasi lolos**

Run: `php artisan test --filter "test_history_shows_checksheet_link_when_present|test_history_shows_dash_when_checksheet_missing"`
Expected: PASS (2 test)

- [ ] **Step 5: Jalankan seluruh file test, pastikan tidak ada regresi**

Run: `php artisan test tests/Feature/Maintenance/ControlStrokeDiesTest.php`
Expected: PASS semua (20 test)

- [ ] **Step 6: Commit**

```bash
git add resources/views/maintenance/control-stroke-dies-history.blade.php tests/Feature/Maintenance/ControlStrokeDiesTest.php
git commit -m "feat(maintenance): history preventive dies tampilkan link checksheet PDF"
```

---

### Task 6: Full suite regression check

**Files:** tidak ada perubahan file — verifikasi saja.

- [ ] **Step 1: Jalankan seluruh test suite project**

Run: `php artisan test`
Expected: semua test PASS, tidak ada regresi di file lain (mis. `ChatbotCapabilityParityTest`, `MesinUpdateTest`, dll — tidak tersentuh perubahan ini tapi wajib tetap hijau).

- [ ] **Step 2: Smoke check migration di DB lokal (opsional tapi disarankan sebelum push)**

Run: `php artisan migrate:status`
Expected: migration `2026_07_23_000001_add_checksheet_path_to_dies_preventive_log_table` berstatus `Ran`.
