# Modul Repair (LHR) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah modul baru "Repair" (LHR — Laporan Harian Repair) yang mirror struktur LHP Produksi: master proses repair sendiri, input batch, daftar+filter+export, role baru `staff_repair`.

**Architecture:** Mirror 1:1 pola existing LHP Produksi/QC — dua tabel baru (`master_part_proses_repair`, `transaksi_repair`), dua model, satu write service, satu FormRequest batch + satu FormRequest update, satu controller Input + satu controller Master, satu Export class, tiga view (index/create/edit) untuk Input Repair + tiga view (index/create/edit) untuk Master Proses Repair, plus role & sidebar wiring.

**Tech Stack:** Laravel 13, PHP 8.4 (CI/prod — jangan pakai sintaks 8.5-only), MySQL (SQLite di test), Bootstrap 5.3.3 + Alpine.js 3.14.1 (komponen `batchDraft`/`taField`/`partPicker`/`filterSearchComp`/`partGroupComp` sudah ada di `resources/views/layouts/app.blade.php`, JANGAN diduplikasi), Maatwebsite Excel 4.x, Pest/PHPUnit.

## Global Constraints

- CI & prod jalan PHP 8.4 — jangan pakai sintaks yang cuma ada di 8.5.
- Semua model baru pakai `SoftDeletes`.
- Migrasi baru WAJIB didaftarkan di `.github/workflows/deploy.yml` step "Migrate (targeted)" — di task yang sama dengan migrasinya, bukan ditunda.
- Kode yang baca kolom baru di tabel existing (tidak ada di sini — semua kolom Repair ada di tabel BARU, jadi guard `Schema::hasColumn` TIDAK diperlukan untuk fitur ini).
- Pagination pakai `Paginator::useBootstrapFive()` — sudah global di `AppServiceProvider`, tidak perlu disentuh.
- Role baru ditambah HANYA di `App\Models\User::ROLES` — satu tempat.
- `vendor/bin/pint app tests` sebelum commit kalau menyentuh file PHP baru.
- Jalankan `php artisan test` (SQLite in-memory, tidak butuh MySQL lokal) setelah tiap task.
- Jalankan `./scripts/check-migration-coverage.sh` sebelum PR dibuka (gerbang CI migrasi).

---

### Task 1: Migrasi + Model `MasterPartProsesRepair`

**Files:**
- Create: `database/migrations/2026_09_09_100001_create_master_part_proses_repair_table.php`
- Create: `app/Models/MasterPartProsesRepair.php`
- Modify: `app/Models/MasterPart.php` (tambah relasi `prosesRepair()`)
- Modify: `.github/workflows/deploy.yml` (daftarkan migrasi baru)
- Test: `tests/Unit/MasterPartProsesRepairTest.php`

**Interfaces:**
- Produces: `MasterPartProsesRepair` model dengan `$fillable = ['id_part', 'id_proses', 'nama_proses', 'sph_standar_repair']`, relasi `part(): BelongsTo`, `transaksiRepair(): HasMany`. `MasterPart::prosesRepair(): HasMany`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPartProsesRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_bisa_dibuat_dan_terhubung_ke_part(): void
    {
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);

        $proses = MasterPartProsesRepair::create([
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS',
            'sph_standar_repair' => 300,
        ]);

        $this->assertTrue($proses->part->is($part));
        $this->assertTrue($part->prosesRepair->first()->is($proses));
    }

    public function test_soft_delete(): void
    {
        $part = MasterPart::create(['part_no' => 'P-2', 'part_name' => 'PART DUA']);
        $proses = MasterPartProsesRepair::create([
            'id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100,
        ]);

        $proses->delete();

        $this->assertSoftDeleted('master_part_proses_repair', ['id' => $proses->id]);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=MasterPartProsesRepairTest`
Expected: FAIL — `Class "App\Models\MasterPartProsesRepair" not found` (atau tabel belum ada).

- [ ] **Step 3: Buat migrasi**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_part_proses_repair', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_part')->constrained('master_part')->cascadeOnDelete();
            $table->string('id_proses');
            $table->string('nama_proses');
            $table->decimal('sph_standar_repair', 8, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['id_part', 'id_proses']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_part_proses_repair');
    }
};
```

- [ ] **Step 4: Buat model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterPartProsesRepair extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_part_proses_repair';

    protected $fillable = ['id_part', 'id_proses', 'nama_proses', 'sph_standar_repair'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_part_proses_repair');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MasterPart::class, 'id_part');
    }

    public function transaksiRepair(): HasMany
    {
        return $this->hasMany(TransaksiRepair::class, 'id_part_proses_repair');
    }
}
```

- [ ] **Step 5: Tambah relasi di `MasterPart.php`**

Edit `app/Models/MasterPart.php`, tambah setelah method `proses()`:

```php
    public function prosesRepair(): HasMany
    {
        return $this->hasMany(MasterPartProsesRepair::class, 'id_part');
    }
```

(`HasMany` sudah di-`use` di file ini — dipakai `proses()` juga.)

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MasterPartProsesRepairTest`
Expected: PASS (2 test)

- [ ] **Step 7: Daftarkan migrasi di `deploy.yml`**

Buka `.github/workflows/deploy.yml`, cari step "Migrate (targeted)" (daftar `--path=database/migrations/...` per file). Tambah baris untuk file migrasi baru di step yang sama, ikuti format baris existing persis (path lengkap ke file yang baru dibuat di Step 3).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_09_100001_create_master_part_proses_repair_table.php \
        app/Models/MasterPartProsesRepair.php app/Models/MasterPart.php \
        .github/workflows/deploy.yml tests/Unit/MasterPartProsesRepairTest.php
git commit -m "feat(repair): tambah master part proses repair"
```

---

### Task 2: Migrasi + Model `TransaksiRepair`

**Files:**
- Create: `database/migrations/2026_09_09_100002_create_transaksi_repair_table.php`
- Create: `app/Models/TransaksiRepair.php`
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Unit/TransaksiRepairTest.php`

**Interfaces:**
- Consumes: `MasterPartProsesRepair` (Task 1), `MasterShift`, `MasterKaryawan`, `User` (existing).
- Produces: `TransaksiRepair` model, `$fillable = ['tanggal_repair','id_shift','id_karyawan','id_part_proses_repair','jam_start','jam_finish','qty_aktual','actual_cycle_time','created_by','updated_by']`, accessor `waktu_kerja_jam` (float, jam), accessor `sph_aktual` (int). Static `hitungDurasiMenit(?string $jamStart, ?string $jamFinish): int` — SAMA implementasi persis dengan `TransaksiProduksi::hitungDurasiMenit()` (disalin, bukan dipanggil silang, supaya `TransaksiRepair` tidak bergantung ke `TransaksiProduksi`).

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransaksiRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_durasi_menit_terisi_otomatis_saat_disimpan(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);

        $repair = TransaksiRepair::create([
            'tanggal_repair' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
            'actual_cycle_time' => 72.0,
            'created_by' => $user->id,
        ]);

        $this->assertSame(120, $repair->durasi_menit);
        $this->assertSame(2.0, $repair->waktu_kerja_jam);
        $this->assertSame(50, $repair->sph_aktual);
    }

    public function test_durasi_menit_lewat_tengah_malam(): void
    {
        $this->assertSame(120, TransaksiRepair::hitungDurasiMenit('23:00', '01:00'));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=TransaksiRepairTest`
Expected: FAIL — class/tabel belum ada.

- [ ] **Step 3: Buat migrasi**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_repair', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal_repair');
            $table->foreignId('id_shift')->constrained('master_shift');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->foreignId('id_part_proses_repair')->constrained('master_part_proses_repair');
            $table->time('jam_start');
            $table->time('jam_finish');
            $table->integer('durasi_menit')->default(0);
            $table->integer('qty_aktual');
            $table->decimal('actual_cycle_time', 8, 2)->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['tanggal_repair', 'id_karyawan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_repair');
    }
};
```

- [ ] **Step 4: Buat model**

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TransaksiRepair extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'transaksi_repair';

    protected $fillable = [
        'tanggal_repair', 'id_shift', 'id_karyawan', 'id_part_proses_repair',
        'jam_start', 'jam_finish', 'qty_aktual', 'actual_cycle_time',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tanggal_repair' => 'date:Y-m-d',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('lhr_repair');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function partProsesRepair(): BelongsTo
    {
        return $this->belongsTo(MasterPartProsesRepair::class, 'id_part_proses_repair');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::saving(function (self $lhr) {
            if (Schema::hasColumn('transaksi_repair', 'durasi_menit')) {
                $lhr->durasi_menit = self::hitungDurasiMenit($lhr->jam_start, $lhr->jam_finish);
            }
        });
    }

    /** Sama persis TransaksiProduksi::hitungDurasiMenit() — disalin, bukan dipanggil silang. */
    public static function hitungDurasiMenit(?string $jamStart, ?string $jamFinish): int
    {
        try {
            $start = Carbon::parse('2000-01-01 '.$jamStart);
            $finish = Carbon::parse('2000-01-01 '.$jamFinish);
            if ($finish->lte($start)) {
                $finish->addDay();
            }

            return (int) $start->diffInMinutes($finish);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getWaktuKerjaJamAttribute(): float
    {
        $menit = $this->durasi_menit ?? self::hitungDurasiMenit($this->jam_start, $this->jam_finish);

        return round($menit / 60, 2);
    }

    /** SPH Aktual = qty_aktual / waktu_kerja_jam */
    public function getSphAktualAttribute(): int
    {
        $wkj = $this->waktu_kerja_jam;

        return $wkj > 0 ? (int) round($this->qty_aktual / $wkj) : 0;
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=TransaksiRepairTest`
Expected: PASS (2 test)

- [ ] **Step 6: Daftarkan migrasi di `deploy.yml`** (sama seperti Task 1 Step 7, migrasi dari Step 3 di atas)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_09_100002_create_transaksi_repair_table.php \
        app/Models/TransaksiRepair.php .github/workflows/deploy.yml \
        tests/Unit/TransaksiRepairTest.php
git commit -m "feat(repair): tambah transaksi repair"
```

---

### Task 3: Role `staff_repair` + akses Overview

**Files:**
- Modify: `app/Models/User.php`
- Modify: `routes/web.php` (baris role `overview`)
- Modify: `tests/Feature/OverviewPageTest.php`
- Test: `tests/Unit/UserRolesTest.php` (baru, kecil)

**Interfaces:**
- Produces: `User::ROLES` punya entry `'staff_repair' => 'Staff Repair'`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserRolesTest extends TestCase
{
    public function test_staff_repair_terdaftar_di_roles(): void
    {
        $this->assertArrayHasKey('staff_repair', User::ROLES);
        $this->assertSame('Staff Repair', User::ROLES['staff_repair']);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=UserRolesTest`
Expected: FAIL — key `staff_repair` tidak ada.

- [ ] **Step 3: Tambah role di `User::ROLES`**

Edit `app/Models/User.php`:

```php
    public const ROLES = [
        'admin' => 'Admin',
        'staff_produksi' => 'Staff Produksi',
        'staff_qc' => 'Staff QC',
        'staff_maintenance' => 'Staff Maintenance',
        'staff_repair' => 'Staff Repair',
        'manager' => 'Manager',
        'guest' => 'Guest (Read-Only)',
    ];
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=UserRolesTest`
Expected: PASS

- [ ] **Step 5: Tambah `staff_repair` ke role Overview**

Edit `routes/web.php`, cari baris:

```php
    Route::middleware('role:admin,manager,staff_produksi,staff_qc,staff_maintenance,guest')
        ->get('overview', [OverviewController::class, 'index'])
        ->name('overview');
```

Ganti jadi:

```php
    Route::middleware('role:admin,manager,staff_produksi,staff_qc,staff_maintenance,staff_repair,guest')
        ->get('overview', [OverviewController::class, 'index'])
        ->name('overview');
```

- [ ] **Step 6: Update `tests/Feature/OverviewPageTest.php`**

Cari baris (sekitar baris 350):

```php
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
```

Ganti jadi:

```php
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'] as $role) {
```

- [ ] **Step 7: Jalankan seluruh suite terkait, pastikan lulus**

Run: `php artisan test --filter=OverviewPageTest`
Expected: PASS (semua role termasuk `staff_repair` bisa akses `overview`)

- [ ] **Step 8: Commit**

```bash
git add app/Models/User.php routes/web.php tests/Feature/OverviewPageTest.php tests/Unit/UserRolesTest.php
git commit -m "feat(repair): tambah role staff_repair, akses overview"
```

---

### Task 4: Master Proses Repair — FormRequest + Controller + Routes + Views

**Files:**
- Create: `app/Http/Requests/StoreMasterPartProsesRepairRequest.php`
- Create: `app/Http/Controllers/Master/ProsesRepairController.php`
- Modify: `routes/web.php` (tambah resource di grup master admin-only)
- Create: `resources/views/master/proses-repair/index.blade.php`
- Create: `resources/views/master/proses-repair/create.blade.php`
- Create: `resources/views/master/proses-repair/edit.blade.php`
- Modify: `config/sidebar.php` (tambah item di grup `master`)
- Test: `tests/Feature/Master/ProsesRepairCrudTest.php`

**Interfaces:**
- Consumes: `MasterPartProsesRepair`, `MasterPart` (Task 1).
- Produces: routes `master.proses-repair.index|create|store|edit|update|destroy`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Master;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesRepairCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_crud_proses_repair(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->get(route('master.proses-repair.index'))->assertOk();

        $this->actingAs($admin)->post(route('master.proses-repair.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS',
            'sph_standar_repair' => 200,
        ])->assertRedirect(route('master.proses-repair.index'));

        $this->assertDatabaseHas('master_part_proses_repair', ['id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS']);

        $proses = MasterPartProsesRepair::first();

        $this->actingAs($admin)->put(route('master.proses-repair.update', $proses), [
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS UPDATE',
            'sph_standar_repair' => 250,
        ])->assertRedirect(route('master.proses-repair.index'));

        $this->assertDatabaseHas('master_part_proses_repair', ['id' => $proses->id, 'nama_proses' => 'REPAIR LAS UPDATE']);

        $this->actingAs($admin)->delete(route('master.proses-repair.destroy', $proses))
            ->assertRedirect(route('master.proses-repair.index'));

        $this->assertSoftDeleted('master_part_proses_repair', ['id' => $proses->id]);
    }

    public function test_staff_repair_tidak_bisa_akses_master_proses_repair(): void
    {
        $staff = User::factory()->create(['role' => 'staff_repair']);

        $this->actingAs($staff)->get(route('master.proses-repair.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ProsesRepairCrudTest`
Expected: FAIL — route `master.proses-repair.index` tidak ada.

- [ ] **Step 3: Buat FormRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterPartProsesRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('proses_repair')?->id;

        return [
            'id_part' => 'required|exists:master_part,id',
            'id_proses' => [
                'required', 'string', 'max:50',
                Rule::unique('master_part_proses_repair', 'id_proses')
                    ->where('id_part', $this->input('id_part'))
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'nama_proses' => 'required|string|max:100',
            'sph_standar_repair' => 'nullable|numeric|min:0',
        ];
    }
}
```

- [ ] **Step 4: Buat Controller**

```php
<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartProsesRepairRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use Illuminate\Http\Request;

class ProsesRepairController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $prosesRepair = MasterPartProsesRepair::with('part')
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $like = '%'.$search.'%';
                $sub->where('nama_proses', 'like', $like)
                    ->orWhere('id_proses', 'like', $like)
                    ->orWhereHas('part', fn ($p) => $p->where('part_name', 'like', $like)
                        ->orWhere('part_no', 'like', $like));
            }))
            ->orderBy('id_proses')
            ->paginate(20)->withQueryString();

        return view('master.proses-repair.index', compact('prosesRepair', 'search'));
    }

    public function create()
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair.create', compact('parts'));
    }

    public function store(StoreMasterPartProsesRepairRequest $request)
    {
        MasterPartProsesRepair::create($request->validated());

        return redirect()->route('master.proses-repair.index')->with('success', 'Proses Repair berhasil ditambahkan.');
    }

    public function edit(MasterPartProsesRepair $prosesRepair)
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair.edit', compact('prosesRepair', 'parts'));
    }

    public function update(StoreMasterPartProsesRepairRequest $request, MasterPartProsesRepair $prosesRepair)
    {
        $prosesRepair->update($request->validated());

        return redirect()->route('master.proses-repair.index')->with('success', 'Proses Repair berhasil diupdate.');
    }

    public function destroy(MasterPartProsesRepair $prosesRepair)
    {
        $prosesRepair->delete();

        return redirect()->route('master.proses-repair.index')->with('success', 'Proses Repair berhasil dihapus.');
    }
}
```

**Penting soal nama parameter:** `Route::resource('proses-repair', ...)` otomatis membuat placeholder URI `{proses_repair}` (snake_case dari nama resource multi-kata), sementara controller di atas pakai `$prosesRepair` (camelCase) — route model binding implisit Laravel mencocokkan lewat NAMA parameter, jadi keduanya harus sama persis atau binding gagal (model jadi null/404). Codebase ini sudah pernah kena kasus sama persis untuk resource multi-kata (`breakdown-dies`) dan solusinya BUKAN `Route::resource()`, tapi mendeklarasikan tiap route manual dengan placeholder camelCase eksplisit — ikuti pola itu, jangan pakai `Route::resource()` di sini.

- [ ] **Step 5: Tambah routes (manual, bukan `Route::resource`)**

Edit `routes/web.php`, tambah import di bagian `use` (urutan alfabetis dengan controller Master lain):

```php
use App\Http\Controllers\Master\ProsesRepairController;
```

Di dalam grup `Route::middleware('role:admin')->prefix('master')->name('master.')->group(...)`, tambah setelah baris resource master lain (mis. `Route::resource('losstime', LosstimeController::class);`):

```php
        Route::get('proses-repair', [ProsesRepairController::class, 'index'])->name('proses-repair.index');
        Route::get('proses-repair/create', [ProsesRepairController::class, 'create'])->name('proses-repair.create');
        Route::post('proses-repair', [ProsesRepairController::class, 'store'])->name('proses-repair.store');
        Route::get('proses-repair/{prosesRepair}/edit', [ProsesRepairController::class, 'edit'])->name('proses-repair.edit');
        Route::put('proses-repair/{prosesRepair}', [ProsesRepairController::class, 'update'])->name('proses-repair.update');
        Route::delete('proses-repair/{prosesRepair}', [ProsesRepairController::class, 'destroy'])->name('proses-repair.destroy');
```

Placeholder `{prosesRepair}` di URI sekarang cocok persis dengan nama parameter `$prosesRepair` di `ProsesRepairController::edit()`/`update()`/`destroy()` (Step 4), jadi Laravel implicit route model binding jalan tanpa perlu konfigurasi tambahan.

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ProsesRepairCrudTest`
Expected: PASS (2 test)

- [ ] **Step 7: Buat view index**

```blade
@extends('layouts.app')
@section('title', 'Master Proses Repair')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.proses-repair.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <a href="{{ route('master.proses-repair.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('master.proses-repair.index') }}" class="erp-dock-body">
        <div class="erp-grp">
            <b>Find</b>
            <input type="text" name="q" value="{{ $search }}" class="erp-in @if($search !== '') is-set @endif"
                   placeholder="Part atau Nama Proses">
        </div>
        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('master.proses-repair.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">
<div class="erp-scroll">
    <table class="erp-table" style="min-width:720px;width:100%">
        <thead style="position:sticky;top:0;z-index:10">
            <tr>
                <th style="width:130px">Part No.</th>
                <th>Part Name</th>
                <th style="width:100px">ID Proses</th>
                <th>Nama Proses</th>
                <th style="width:120px;text-align:right">SPH Standar</th>
                <th style="width:140px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($prosesRepair as $pr)
            <tr>
                <td>{{ $pr->part?->part_no ?? '—' }}</td>
                <td>{{ $pr->part?->part_name ?? '—' }}</td>
                <td style="font-family:monospace">{{ $pr->id_proses }}</td>
                <td>{{ $pr->nama_proses }}</td>
                <td style="text-align:right">{{ number_format($pr->sph_standar_repair) }}</td>
                <td>
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.proses-repair.edit', $pr) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.proses-repair.destroy', $pr) }}"
                              onsubmit="return confirm('Hapus proses repair ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Belum ada proses repair.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="erp-pg">{{ $prosesRepair->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($prosesRepair->total()) }}</b> proses repair</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>
</div>
@endsection
```

- [ ] **Step 8: Buat view create**

```blade
@extends('layouts.app')
@section('title', 'Tambah Proses Repair')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Tambah Proses Repair</h1>
    <a href="{{ route('master.proses-repair.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Tambah Proses Repair</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.proses-repair.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Part <span class="text-danger">*</span></label>
                <select name="id_part" class="form-select @error('id_part') is-invalid @enderror">
                    <option value="">-- Pilih Part --</option>
                    @foreach($parts as $p)
                        <option value="{{ $p->id }}" @selected(old('id_part') == $p->id)>{{ $p->part_no }} — {{ $p->part_name }}</option>
                    @endforeach
                </select>
                @error('id_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                       value="{{ old('id_proses') }}" placeholder="Contoh: RP1">
                @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                       value="{{ old('nama_proses') }}" placeholder="Contoh: REPAIR LAS">
                @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">SPH Standar Repair</label>
                <input type="number" step="0.01" name="sph_standar_repair"
                       class="form-control @error('sph_standar_repair') is-invalid @enderror"
                       value="{{ old('sph_standar_repair') }}" min="0">
                @error('sph_standar_repair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.proses-repair.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 9: Buat view edit**

Sama seperti `create.blade.php`, hanya `action="{{ route('master.proses-repair.update', $prosesRepair) }}"`, tambah `@method('PUT')`, dan tiap `old('id_part')` diganti `old('id_part', $prosesRepair->id_part)` (pola sama untuk `id_proses`, `nama_proses`, `sph_standar_repair` — `old('nama_proses', $prosesRepair->nama_proses)` dst).

```blade
@extends('layouts.app')
@section('title', 'Edit Proses Repair')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit Proses Repair</h1>
    <a href="{{ route('master.proses-repair.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Edit Proses Repair</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.proses-repair.update', $prosesRepair) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Part <span class="text-danger">*</span></label>
                <select name="id_part" class="form-select @error('id_part') is-invalid @enderror">
                    @foreach($parts as $p)
                        <option value="{{ $p->id }}" @selected(old('id_part', $prosesRepair->id_part) == $p->id)>{{ $p->part_no }} — {{ $p->part_name }}</option>
                    @endforeach
                </select>
                @error('id_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                       value="{{ old('id_proses', $prosesRepair->id_proses) }}">
                @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                       value="{{ old('nama_proses', $prosesRepair->nama_proses) }}">
                @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">SPH Standar Repair</label>
                <input type="number" step="0.01" name="sph_standar_repair"
                       class="form-control @error('sph_standar_repair') is-invalid @enderror"
                       value="{{ old('sph_standar_repair', $prosesRepair->sph_standar_repair) }}" min="0">
                @error('sph_standar_repair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.proses-repair.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 10: Tambah item sidebar**

Edit `config/sidebar.php`, di dalam grup `key => 'master'`, tambah di akhir array `items`:

```php
            ['label' => 'Proses Repair', 'icon' => 'bi-bandaid', 'route' => 'master.proses-repair.index', 'roles' => ['admin'], 'match' => ['master.proses-repair.*']],
```

- [ ] **Step 11: Jalankan seluruh suite Master + Sidebar, pastikan tidak ada regresi**

Run: `php artisan test --filter=Master`
Expected: PASS

- [ ] **Step 12: `vendor/bin/pint app tests` lalu commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/StoreMasterPartProsesRepairRequest.php \
        app/Http/Controllers/Master/ProsesRepairController.php \
        routes/web.php config/sidebar.php \
        resources/views/master/proses-repair \
        tests/Feature/Master/ProsesRepairCrudTest.php
git commit -m "feat(repair): CRUD master proses repair (admin-only)"
```

---

### Task 5: `RepairWriteService` + `StoreRepairBatchRequest`

**Files:**
- Create: `app/Services/RepairWriteService.php`
- Create: `app/Http/Requests/StoreRepairBatchRequest.php`
- Test: `tests/Unit/RepairWriteServiceTest.php`

**Interfaces:**
- Consumes: `TransaksiRepair` (Task 2).
- Produces: `RepairWriteService::simpan(array $baris): TransaksiRepair` — dipakai Task 6. `StoreRepairBatchRequest::validated()['rows']` — array baris tervalidasi, dipakai Task 6.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use App\Services\RepairWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simpan_menulis_satu_baris_dengan_actual_cycle_time_terhitung(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $this->actingAs(User::factory()->create(['role' => 'staff_repair']));

        $service = app(RepairWriteService::class);

        $repair = $service->simpan([
            'tanggal_repair' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ]);

        $this->assertInstanceOf(TransaksiRepair::class, $repair);
        $this->assertSame(72.0, $repair->actual_cycle_time);
        $this->assertNotNull($repair->created_by);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairWriteServiceTest`
Expected: FAIL — class `RepairWriteService` belum ada.

- [ ] **Step 3: Buat service**

```php
<?php

namespace App\Services;

use App\Models\TransaksiRepair;

/**
 * Menulis SATU baris LHR. Sengaja TIDAK membuka transaksi sendiri: pemanggil
 * (RepairController::batch) yang menentukan batasnya, supaya satu batch berisi
 * banyak baris bisa dibatalkan utuh.
 */
class RepairWriteService
{
    public function simpan(array $baris): TransaksiRepair
    {
        $durasiDetik = $this->durasiDetik($baris['jam_start'].':00', $baris['jam_finish'].':00');
        $cycleTime = (int) $baris['qty_aktual'] > 0 ? round($durasiDetik / $baris['qty_aktual'], 2) : 0.0;

        return TransaksiRepair::create([
            ...$baris,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);
    }

    /** Durasi dalam detik, rentang lewat tengah malam ditangani (selesai <= mulai berarti +1 hari). */
    private function durasiDetik(string $jamStart, string $jamFinish): int
    {
        $mulai = strtotime("2000-01-01 {$jamStart}");
        $selesai = strtotime("2000-01-01 {$jamFinish}");
        if ($selesai <= $mulai) {
            $selesai += 86400;
        }

        return $selesai - $mulai;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=RepairWriteServiceTest`
Expected: PASS

- [ ] **Step 5: Buat `StoreRepairBatchRequest`** (tanpa langsung dites HTTP — dites lewat Task 6)

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepairBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.tanggal_repair' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
            'rows.*.id_part_proses_repair' => 'required|exists:master_part_proses_repair,id',
            'rows.*.jam_start' => 'required|date_format:H:i',
            'rows.*.jam_finish' => 'required|date_format:H:i',
            'rows.*.qty_aktual' => 'required|integer|min:1',
        ];
    }

    /**
     * 50 baris × field flat bisa melewati max_input_vars bawaan PHP (1000) —
     * pola sama StoreProduksiBatchRequest. create.blade.php Repair mengirim
     * rows sebagai satu field JSON (rows_json), jadi diuraikan di sini dulu.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('rows_json') && ! $this->has('rows')) {
            $decoded = json_decode((string) $this->input('rows_json'), true);
            $this->merge(['rows' => is_array($decoded) ? $this->kosongJadiNull($decoded) : []]);
        }
    }

    private function kosongJadiNull(array $data): array
    {
        foreach ($data as $kunci => $nilai) {
            if (is_array($nilai)) {
                $data[$kunci] = $this->kosongJadiNull($nilai);
            } elseif ($nilai === '') {
                $data[$kunci] = null;
            }
        }

        return $data;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('rows');
            if (! is_array($rows)) {
                return;
            }

            foreach ($rows as $i => $row) {
                if (! is_array($row) || empty($row['jam_start']) || empty($row['jam_finish'])) {
                    continue;
                }
                if ($row['jam_start'] === $row['jam_finish']) {
                    $validator->errors()->add(
                        "rows.{$i}.jam_finish",
                        'Baris '.($i + 1).': jam mulai dan jam selesai tidak boleh sama.'
                    );
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'rows.required' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.min' => 'Minimal harus ada 1 baris untuk disimpan.',
            'rows.max' => 'Maksimal 50 baris per submit.',
            'rows.*.tanggal_repair.required' => 'Baris :position: Tanggal Repair wajib diisi.',
            'rows.*.id_shift.required' => 'Baris :position: Shift wajib dipilih.',
            'rows.*.id_karyawan.required' => 'Baris :position: Operator wajib dipilih.',
            'rows.*.id_part_proses_repair.required' => 'Baris :position: Part dan Proses Repair wajib dipilih.',
            'rows.*.id_part_proses_repair.exists' => 'Baris :position: Part/Proses Repair yang dipilih tidak valid.',
            'rows.*.jam_start.required' => 'Baris :position: Jam Start wajib diisi.',
            'rows.*.jam_finish.required' => 'Baris :position: Jam Finish wajib diisi.',
            'rows.*.qty_aktual.required' => 'Baris :position: Qty Act Repair wajib diisi.',
            'rows.*.qty_aktual.min' => 'Baris :position: Qty Act Repair minimal 1.',
        ];
    }

    public function attributes(): array
    {
        return [
            'rows.*.tanggal_repair' => 'Tanggal Repair',
            'rows.*.id_shift' => 'Shift',
            'rows.*.id_karyawan' => 'Operator',
            'rows.*.id_part_proses_repair' => 'Proses Repair',
            'rows.*.jam_start' => 'Jam Start',
            'rows.*.jam_finish' => 'Jam Finish',
            'rows.*.qty_aktual' => 'Qty Act Repair',
        ];
    }
}
```

- [ ] **Step 6: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Services/RepairWriteService.php app/Http/Requests/StoreRepairBatchRequest.php \
        tests/Unit/RepairWriteServiceTest.php
git commit -m "feat(repair): RepairWriteService + StoreRepairBatchRequest"
```

---

### Task 6: `UpdateRepairRequest` + `LhpRepairExport`

**Files:**
- Create: `app/Http/Requests/UpdateRepairRequest.php`
- Create: `app/Exports/LhpRepairExport.php`
- Test: `tests/Unit/LhpRepairExportTest.php`

**Interfaces:**
- Produces: `UpdateRepairRequest::validated(): array` (dipakai Task 7). `LhpRepairExport` implements `FromCollection, WithHeadings, WithColumnWidths, WithStyles`, constructor `(?string $dateFrom, ?string $dateTo, ?string $customer, ?string $partName, ?string $namaProses)`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Exports\LhpRepairExport;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LhpRepairExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_berisi_baris_repair_dengan_kolom_lengkap(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);

        TransaksiRepair::create([
            'tanggal_repair' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $export = new LhpRepairExport('2026-09-01', '2026-09-01', null, null, null);
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame('CUST A', $rows->first()[3]);
        $this->assertCount(16, $export->headings());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=LhpRepairExportTest`
Expected: FAIL — class belum ada.

- [ ] **Step 3: Buat `UpdateRepairRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRepairRequest extends FormRequest
{
    protected $errorBag = 'updateRepair';

    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'tanggal_repair' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'id_part_proses_repair' => 'required|exists:master_part_proses_repair,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_aktual' => 'required|integer|min:1',
        ];
    }
}
```

- [ ] **Step 4: Buat `LhpRepairExport`**

```php
<?php

namespace App\Exports;

use App\Models\TransaksiRepair;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LhpRepairExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
{
    public function __construct(
        private ?string $dateFrom = null,
        private ?string $dateTo = null,
        private ?string $customer = null,
        private ?string $partName = null,
        private ?string $namaProses = null,
    ) {}

    public function collection(): Enumerable
    {
        $q = TransaksiRepair::with(['karyawan', 'partProsesRepair.part', 'shift']);

        if ($this->dateFrom) {
            $q->whereDate('tanggal_repair', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $q->whereDate('tanggal_repair', '<=', $this->dateTo);
        }
        if ($this->customer) {
            $q->whereHas('partProsesRepair.part', fn ($q) => $q->where('customer', 'like', "%{$this->customer}%"));
        }
        if ($this->partName) {
            $q->whereHas('partProsesRepair.part', fn ($q) => $q->where('part_name', 'like', "%{$this->partName}%"));
        }
        if ($this->namaProses) {
            $q->whereHas('partProsesRepair', fn ($q) => $q->where('nama_proses', 'like', "%{$this->namaProses}%"));
        }

        return $q->orderBy('tanggal_repair')->orderBy('id')->get()->map(function ($t) {
            $pp = $t->partProsesRepair;
            $part = $pp?->part;
            $sphStd = $pp?->sph_standar_repair ?? 0;
            $sphAct = $t->sph_aktual;
            $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : '';

            return [
                $t->tanggal_repair,
                $t->shift?->nama_shift ?? '',
                $t->karyawan?->nama_karyawan ?? '',
                $part?->customer ?? '',
                $part?->part_no ?? '',
                $part?->part_name ?? '',
                $pp?->id_proses ?? '',
                $pp?->nama_proses ?? '',
                substr($t->jam_start, 0, 5),
                substr($t->jam_finish, 0, 5),
                $t->qty_aktual,
                $sphStd,
                $sphAct,
                round($t->waktu_kerja_jam, 2),
                round($t->actual_cycle_time, 2),
                $pct !== '' ? $pct.'%' : '',
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Tanggal', 'Shift', 'Operator', 'Customer', 'Part No', 'Part Name',
            'ID Proses', 'Nama Proses', 'Jam Start', 'Jam Finish', 'Qty Act Repair',
            'SPH Std', 'SPH Aktual', 'W. Kerja (jam)', 'CT Aktual (dtk)', '%',
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 13, 'B' => 12, 'C' => 20, 'D' => 14, 'E' => 14, 'F' => 22,
            'G' => 9, 'H' => 16, 'I' => 10, 'J' => 10, 'K' => 13,
            'L' => 9, 'M' => 11, 'N' => 15, 'O' => 15, 'P' => 8,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF111827']],
                'alignment' => ['wrapText' => true],
            ],
        ];
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=LhpRepairExportTest`
Expected: PASS

- [ ] **Step 6: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/UpdateRepairRequest.php app/Exports/LhpRepairExport.php \
        tests/Unit/LhpRepairExportTest.php
git commit -m "feat(repair): UpdateRepairRequest + LhpRepairExport"
```

---

### Task 7: `RepairController` (Input) + Routes + Sidebar grup Repair

**Files:**
- Create: `app/Http/Controllers/Input/RepairController.php`
- Modify: `routes/web.php`
- Modify: `config/sidebar.php` (grup baru `repair`)
- Test: `tests/Feature/Input/RepairBatchTest.php`
- Test: `tests/Feature/AuthorizationTest.php` (tambah assertion boundary)

**Interfaces:**
- Consumes: `RepairWriteService` (Task 5), `StoreRepairBatchRequest` (Task 5), `UpdateRepairRequest` (Task 6), `LhpRepairExport` (Task 6).
- Produces: routes `input.repair.index|create|batch|edit|update|destroy|export`.

- [ ] **Step 1: Tulis test yang gagal (batch flow, mirror `ProduksiBatchTest`)**

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairBatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);

        $this->ref = compact('shift', 'karyawan', 'proses');
        $this->actingAs(User::factory()->create(['role' => 'staff_repair']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_repair' => '2026-09-01',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_part_proses_repair' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ], $override);
    }

    public function test_batch_menyimpan_banyak_baris_sekaligus(): void
    {
        $response = $this->post(route('input.repair.batch'), [
            'rows' => [$this->baris(), $this->baris(['qty_aktual' => 50])],
        ]);

        $response->assertRedirect(route('input.repair.index'));
        $this->assertDatabaseCount('transaksi_repair', 2);
    }

    public function test_batch_menolak_baris_tanpa_qty(): void
    {
        $response = $this->post(route('input.repair.batch'), [
            'rows' => [$this->baris(['qty_aktual' => 0])],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('transaksi_repair', 0);
    }

    public function test_staff_produksi_tidak_bisa_akses_input_repair(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']))
            ->get(route('input.repair.index'))
            ->assertForbidden();
    }

    public function test_hanya_admin_bisa_edit_dan_hapus_repair(): void
    {
        $repair = TransaksiRepair::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);

        $this->get(route('input.repair.edit', $repair))->assertForbidden();
        $this->delete(route('input.repair.destroy', $repair))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('input.repair.edit', $repair))->assertOk();
        $this->actingAs($admin)->delete(route('input.repair.destroy', $repair))
            ->assertRedirect(route('input.repair.index'));
        $this->assertSoftDeleted('transaksi_repair', ['id' => $repair->id]);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairBatchTest`
Expected: FAIL — route `input.repair.*` belum ada.

- [ ] **Step 3: Buat Controller**

```php
<?php

namespace App\Http\Controllers\Input;

use App\Exports\LhpRepairExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepairBatchRequest;
use App\Http\Requests\UpdateRepairRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Services\RepairWriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RepairController extends Controller
{
    public function __construct(private RepairWriteService $repairWriteService) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $search = trim((string) $request->input('q', ''));
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterPartId = (int) $request->input('filter_part_id', 0);
        $filterProsesId = (int) $request->input('filter_proses_id', 0);
        $filterKarId = (int) $request->input('filter_karyawan_id', 0);

        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));

        $q = TransaksiRepair::with(['karyawan', 'partProsesRepair.part', 'shift']);

        if ($pakaiTanggal) {
            $q->whereDate('tanggal_repair', '>=', $dateFrom)
                ->whereDate('tanggal_repair', '<=', $dateTo);
        }
        if ($filterCustomer !== '') {
            $q->whereHas('partProsesRepair.part', fn ($p) => $p->where('customer', $filterCustomer));
        }
        if ($filterPartId > 0) {
            $q->whereHas('partProsesRepair', fn ($pp) => $pp->where('id_part', $filterPartId));
        }
        if ($filterProsesId > 0) {
            $q->where('id_part_proses_repair', $filterProsesId);
        }
        if ($filterKarId > 0) {
            $q->where('id_karyawan', $filterKarId);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawan', fn ($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('shift', fn ($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('partProsesRepair', fn ($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProsesRepair.part', fn ($p) => $p->where('part_no', 'like', $like)
                        ->orWhere('part_name', 'like', $like)
                        ->orWhere('customer', 'like', $like));
            });
        }

        $repairs = $q->orderByDesc('tanggal_repair')->orderByDesc('created_at')->paginate(30)->withQueryString();

        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepair')->orderBy('part_name')->get();
        $customers = MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values();
        $filterActive = $search !== '' || $filterCustomer !== '' || $filterPartId > 0 || $filterProsesId > 0 || $filterKarId > 0;

        return view('input.repair.index', compact(
            'repairs', 'dateFrom', 'dateTo', 'search', 'pakaiTanggal',
            'filterCustomer', 'filterPartId', 'filterProsesId', 'filterKarId',
            'karyawan', 'parts', 'customers', 'filterActive'
        ));
    }

    public function create()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepair')->orderBy('part_name')->get();

        return view('input.repair.create', compact('shifts', 'karyawan', 'parts'));
    }

    public function batch(StoreRepairBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->repairWriteService->simpan($row);
            }
        });

        $jumlah = count($rows);

        return redirect()->route('input.repair.index')
            ->with('success', "{$jumlah} baris LHR berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }

    public function edit(TransaksiRepair $repair)
    {
        $repair->load(['karyawan', 'partProsesRepair.part']);
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepair')->orderBy('part_name')->get();

        return view('input.repair.edit', compact('repair', 'shifts', 'karyawan', 'parts'));
    }

    public function update(UpdateRepairRequest $request, TransaksiRepair $repair)
    {
        $validated = $request->validated();

        $repair->update($validated);

        return redirect()->route('input.repair.index', $this->backQueryParams($request))
            ->with('success', 'LHR berhasil diupdate.');
    }

    private function backQueryParams(Request $request): array
    {
        parse_str((string) $request->input('back_query', ''), $params);

        return collect($params)->only([
            'date_from', 'date_to', 'q', 'filter_customer', 'filter_part_id',
            'filter_proses_id', 'filter_karyawan_id', 'pakai_tanggal', 'filter_apply', 'page',
        ])->toArray();
    }

    public function export(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $customer = $request->input('customer') ?: null;
        $partName = $request->input('part_name') ?: null;
        $namaProses = $request->input('nama_proses') ?: null;

        $filename = 'LHR_Repair_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(
            new LhpRepairExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function destroy(TransaksiRepair $repair)
    {
        $repair->delete();

        return back(fallback: route('input.repair.index'))->with('success', 'Data berhasil dihapus.');
    }
}
```

- [ ] **Step 4: Tambah import controller + routes**

Edit `routes/web.php`, tambah import:

```php
use App\Http\Controllers\Input\RepairController;
```

Tambah grup route baru (setelah grup route Dandori/QC, sebelum grup "EDIT/UPDATE LHP — admin only"):

```php
    // --- INPUT & DAFTAR LHR (staff_repair + admin) ---
    Route::middleware('role:admin,staff_repair')->prefix('input')->name('input.')->group(function () {
        Route::get('repair', [RepairController::class, 'index'])->name('repair.index');
        Route::get('repair/create', [RepairController::class, 'create'])->name('repair.create');
        Route::post('repair/batch', [RepairController::class, 'batch'])->name('repair.batch');
        Route::get('repair/export', [RepairController::class, 'export'])->name('repair.export');
    });
```

Di dalam grup `// --- EDIT/UPDATE LHP — admin only ---` (`role:admin`) yang sudah ada, tambah:

```php
        Route::get('repair/{repair}/edit', [RepairController::class, 'edit'])->name('repair.edit');
        Route::put('repair/{repair}', [RepairController::class, 'update'])->name('repair.update');
        Route::delete('repair/{repair}', [RepairController::class, 'destroy'])->name('repair.destroy');
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=RepairBatchTest`
Expected: PASS (4 test)

- [ ] **Step 6: Tambah grup sidebar `repair`**

Edit `config/sidebar.php`, tambah grup baru setelah grup `mtcdies` (sebelum grup `master`):

```php
    [
        'type' => 'group',
        'key' => 'repair',
        'label' => 'Repair',
        'icon' => 'bi-bandaid',
        'items' => [
            ['label' => 'Input LHR', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair.index', 'roles' => ['admin', 'staff_repair'], 'match' => ['input.repair.*']],
        ],
    ],
```

- [ ] **Step 7: Tambah assertion boundary di `AuthorizationTest.php`**

Tambah method baru di `tests/Feature/AuthorizationTest.php` (dekat method `test_master_data_is_admin_only` atau grup produksi/qc yang sudah ada):

```php
    public function test_input_repair_hanya_admin_dan_staff_repair(): void
    {
        $this->actingAs($this->user('admin'))->get(route('input.repair.index'))->assertOk();
        $this->actingAs($this->user('staff_repair'))->get(route('input.repair.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('input.repair.index'))->assertForbidden();
        $this->actingAs($this->user('staff_qc'))->get(route('input.repair.index'))->assertForbidden();
        $this->actingAs($this->user('staff_maintenance'))->get(route('input.repair.index'))->assertForbidden();
        $this->actingAs($this->user('manager'))->get(route('input.repair.index'))->assertForbidden();
    }
```

- [ ] **Step 8: Update `tests/Unit/SidebarMenuTest.php` dan `tests/Feature/Layout/SidebarNavTest.php`**

Di `tests/Unit/SidebarMenuTest.php`, ganti KETIGA baris literal (baris ~59, ~70, ~144):

```php
foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
```

jadi:

```php
foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'] as $role) {
```

(Baris ~144 sebelumnya TANPA `'admin'` di depan — cek dulu isi barisnya sebelum ganti, cukup tambahkan `'staff_repair'` ke array yang sudah ada di baris itu, jangan menyalin array admin ke situ.)

Di `tests/Feature/Layout/SidebarNavTest.php`, ganti baris const:

```php
private const ROLES = ['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'];
```

jadi:

```php
private const ROLES = ['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'];
```

- [ ] **Step 9: Jalankan seluruh suite terkait, pastikan lulus tanpa regresi**

Run: `php artisan test --filter=AuthorizationTest`
Run: `php artisan test --filter=SidebarMenuTest`
Run: `php artisan test --filter=SidebarNavTest`
Expected: semua PASS. Kalau `SidebarMenuTest::test_tiap_role_tidak_melewati_plafon_baris` gagal untuk role `admin` (top-level count admin sekarang 9, plafon `PLAFON_BARIS` juga 9) — SEHARUSNYA masih PASS karena `assertLessThanOrEqual`. Kalau merah, JANGAN naikkan `PLAFON_BARIS` — laporkan balik, itu tanda ada grup lain yang perlu digabung dulu.

- [ ] **Step 10: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Http/Controllers/Input/RepairController.php routes/web.php config/sidebar.php \
        tests/Feature/Input/RepairBatchTest.php tests/Feature/AuthorizationTest.php \
        tests/Unit/SidebarMenuTest.php tests/Feature/Layout/SidebarNavTest.php
git commit -m "feat(repair): RepairController input+daftar+export, sidebar grup Repair"
```

---

### Task 8: View `input/repair/create.blade.php` (form batch)

**Files:**
- Create: `resources/views/input/repair/create.blade.php`
- Test: `tests/Feature/Input/HalamanBatchRepairTest.php`

**Interfaces:**
- Consumes: `batchDraft(cfg)`, `taField(items)`, `partPicker(items, partsData)` — didefinisikan global di `resources/views/layouts/app.blade.php`, JANGAN diduplikasi di file ini.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_create_menampilkan_form_batch(): void
    {
        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);

        $response = $this->actingAs(User::factory()->create(['role' => 'staff_repair']))
            ->get(route('input.repair.create'));

        $response->assertOk();
        $response->assertSee('formBaris', false);
        $response->assertSee('konfigRepair', false);
        $response->assertSee(route('input.repair.batch'), false);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=HalamanBatchRepairTest`
Expected: FAIL — view belum ada.

- [ ] **Step 3: Buat view**

```blade
@extends('layouts.app')

@section('title', 'Input LHR')

@php
    $_taKaryawan = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $parts->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->prosesRepair->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
            'sph_standar_repair' => (float) $pr->sph_standar_repair,
        ])->values(),
    ])->values();
@endphp

@push('styles')
<style>
.lhp-baris { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.lhp-baris > label { flex: 0 0 150px; margin: 0; font-size: 0.875rem; color: #374151; }
.lhp-isi { flex: 0 1 640px; min-width: 0; }
.lhp-waktu { display: flex; align-items: center; gap: 10px; }
.lhp-waktu > .form-control { flex: 1 1 0; min-width: 0; }
.lhp-to { flex: 0 0 auto; font-size: 0.875rem; color: #6b7280; }
.lhp-draft { width: auto; max-width: none; }
.lhp-draft th, .lhp-draft td { white-space: nowrap; }
@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }
}
</style>
@endpush

@section('content')
<div class="erp-panel" x-data="batchDraft(konfigRepair())" x-init="mulai()">
    <div class="erp-panel-header">Input LHR (Laporan Harian Repair)</div>
    <div class="erp-panel-body">

        <template x-if="pesan">
            <div class="alert alert-info py-2" x-text="pesan"></div>
        </template>

        @if ($errors->any())
            <div class="alert alert-danger py-2">
                <div class="fw-semibold mb-1">Draft ditolak server:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $pesanGalat)
                        <li>{{ $pesanGalat }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form id="formBaris" novalidate @submit.prevent="simpanKeDraft()">

            <div class="lhp-baris">
                <label for="f_tanggal">Tanggal Repair <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="date" id="f_tanggal" name="tanggal_repair" class="form-control"
                           value="{{ now()->format('Y-m-d') }}">
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_shift">Shift <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <select id="f_shift" name="id_shift" class="form-select">
                        <option value="">-- Pilih Shift --</option>
                        @foreach ($shifts as $s)
                            <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="lhp-baris" x-data="taField(taKaryawan)">
                <label for="f_operator">Operator <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <div class="ta-wrap">
                        <input type="text" id="f_operator" class="form-control ta-input" x-model="query"
                               @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik nama operator...">
                        <input type="hidden" name="id_karyawan" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            <div x-data="partPicker(taParts, partsData)">
                <div class="lhp-baris">
                    <label for="f_part">Part Name <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <div class="ta-wrap">
                            <input type="text" id="f_part" class="form-control ta-input" x-model="query"
                                   @focus="open = true; idx = -1" @blur="tutup()" @keydown="tombol($event)"
                                   @input="val = null; open = true; idx = -1"
                                   placeholder="Ketik part no atau nama...">
                            <div class="ta-dropdown" x-show="open" x-cloak>
                                <template x-if="tersaring.length === 0">
                                    <div class="ta-empty">Tidak ditemukan</div>
                                </template>
                                <template x-for="(item, i) in tersaring" :key="item.v">
                                    <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                         @mousedown.prevent="pilih(item)" x-text="item.t"></div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="lhp-baris">
                    <label for="partNoTampil">Part No.</label>
                    <div class="lhp-isi">
                        <input type="text" class="form-control" id="partNoTampil" :value="partNo" readonly
                               placeholder="Terisi otomatis dari Part Name">
                    </div>
                </div>

                <div class="lhp-baris">
                    <label for="f_proses">Proses Repair <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <select id="f_proses" name="id_part_proses_repair" class="form-select"
                                :disabled="prosesList.length === 0">
                            <option value="">-- Pilih Part dulu --</option>
                            <template x-for="p in prosesList" :key="p.id">
                                <option :value="p.id"
                                        x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_repair})`">
                                </option>
                            </template>
                        </select>
                        <small class="text-muted" x-show="prosesList.length === 0 && partNo" x-cloak>
                            Belum ada proses repair untuk part ini, tambah di Master Data &raquo; Proses Repair dulu.
                        </small>
                    </div>
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_jam_start">Waktu Kerja <span class="text-danger">*</span></label>
                <div class="lhp-isi lhp-waktu">
                    <input type="time" id="f_jam_start" name="jam_start" class="form-control">
                    <span class="lhp-to">To</span>
                    <input type="time" id="f_jam_finish" name="jam_finish" class="form-control">
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_qty">Qty Act Repair <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="number" id="f_qty" name="qty_aktual" class="form-control" min="1">
                </div>
            </div>

            <template x-if="galat">
                <div class="alert alert-danger py-2 mt-3 mb-0" x-text="galat"></div>
            </template>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
                </button>
            </div>
        </form>

        <hr class="my-4">

        <div class="fw-semibold mb-2">DRAFT TERSIMPAN <span x-text="`(${rows.length})`"></span></div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0 lhp-draft">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th><th>Shift</th><th>Operator</th>
                        <th>Part No.</th><th>Part Name</th><th>Proses</th><th>Jam</th>
                        <th>Qty Act</th><th>SPH Std</th><th>SPH Act</th><th>W. Kerja</th><th>CT Act</th><th>%</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="14" class="text-center text-muted py-3">
                            Belum ada baris draft. Isi form di atas lalu klik SAVE TO DRAFT.
                        </td></tr>
                    </template>
                    <template x-for="(r, i) in rows" :key="r.uid">
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
                            <td x-text="r.tanggal_repair"></td>
                            <td x-text="r.shift_teks"></td>
                            <td x-text="r.karyawan_teks"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="`${r.jam_start}–${r.jam_finish}`"></td>
                            <td x-text="r.qty_aktual"></td>
                            <td x-text="r.sph_std.toFixed(0)"></td>
                            <td x-text="r.sph_act.toFixed(0)"></td>
                            <td x-text="r.jam_kerja.toFixed(2)"></td>
                            <td x-text="r.ct_act.toFixed(2)"></td>
                            <td x-text="`${r.persen.toFixed(0)}%`"></td>
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="small text-muted mt-2">
            SPH Act, CT Act, dan % di atas adalah pratinjau. Angka yang tersimpan dihitung ulang di server.
        </div>

        <form method="POST" action="{{ route('input.repair.batch') }}" id="formBatch" class="mt-3"
              @submit="pindahkanKeBackup()">
            @csrf
            <input type="hidden" name="rows_json" :value="JSON.stringify(payloadRows())">

            <div class="d-flex gap-2 justify-content-end">
                <span class="text-muted small align-self-center" x-show="editIndex !== null" x-cloak>
                    Selesaikan UPDATE BARIS atau Batal Edit dulu sebelum submit.
                </span>
                <button type="submit" class="btn btn-success" :disabled="rows.length === 0 || editIndex !== null">
                    <i class="bi bi-upload me-1"></i>SUBMIT DRAFT
                </button>
                <button type="button" class="btn btn-danger" @click="batal()">CANCEL</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taKaryawan = @json($_taKaryawan);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);

function konfigRepair() {
    const picker = () => Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhr_repair_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhr_repair_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.repair.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tanggal_repair: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
            id_part_proses_repair: 'Proses Repair', jam_start: 'Jam Start',
            jam_finish: 'Jam Finish', qty_aktual: 'Qty Act Repair',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                tanggal_repair: diri.nilai('tanggal_repair'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan: diri.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == diri.nilai('id_karyawan')) || {}).t || '',
                id_part_proses_repair: diri.nilai('id_part_proses_repair'),
                proses_teks: diri.teksTerpilih('id_part_proses_repair'),
                part_no: p.partNo,
                part_name: p.partName,
                jam_start: diri.nilai('jam_start'),
                jam_finish: diri.nilai('jam_finish'),
                qty_aktual: diri.nilai('qty_aktual'),
            };
        },

        periksa(calon, rows, diri) {
            if (Number(calon.qty_aktual) < 1) return 'Qty Act Repair minimal 1.';
            if (diri.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                return 'Jam Finish tidak boleh sama dengan Jam Start.';
            }

            return null;
        },

        pratinjau(calon, diri) {
            const jamKerja = diri.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses_repair);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_repair : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        resetField: ['jam_start', 'jam_finish', 'qty_aktual'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses_repair"]');
            if (proses) proses.value = '';
        },

        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tanggal_repair"]').value = row.tanggal_repair;
            f.querySelector('[name="id_shift"]').value = row.id_shift;
            f.querySelector('[name="jam_start"]').value = row.jam_start;
            f.querySelector('[name="jam_finish"]').value = row.jam_finish;
            f.querySelector('[name="qty_aktual"]').value = row.qty_aktual;

            const operator = Alpine.$data(document.getElementById('f_operator').closest('[x-data]'));
            operator.pick({ v: row.id_karyawan, t: row.karyawan_teks });

            const p = picker();
            const dataPart = partsData.find(p => p.proses.some(pr => pr.id == row.id_part_proses_repair));
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses_repair"]');
                    if (proses) proses.value = row.id_part_proses_repair;
                });
            } else {
                p.query = ''; p.val = null; p.partNo = ''; p.partName = ''; p.prosesList = [];
                const proses = f.querySelector('[name="id_part_proses_repair"]');
                if (proses) proses.value = '';
            }
        },

        payload(r) {
            return {
                tanggal_repair: r.tanggal_repair,
                id_shift: r.id_shift,
                id_karyawan: r.id_karyawan,
                id_part_proses_repair: r.id_part_proses_repair,
                jam_start: r.jam_start,
                jam_finish: r.jam_finish,
                qty_aktual: r.qty_aktual,
            };
        },
    };
}
</script>
@endpush
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=HalamanBatchRepairTest`
Expected: PASS

- [ ] **Step 5: Uji di browser (manual, bukan otomatis) — isi 2 baris, submit, cek data masuk**

Jalankan `php artisan serve` (atau dev server existing), login sebagai `staff_repair`, buka `/input/repair/create`, isi form, klik SAVE TO DRAFT dua kali dengan part/proses berbeda, klik SUBMIT DRAFT, pastikan redirect ke index dengan pesan sukses dan 2 baris masuk database.

- [ ] **Step 6: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/repair/create.blade.php tests/Feature/Input/HalamanBatchRepairTest.php
git commit -m "feat(repair): halaman batch input LHR"
```

---

### Task 9: View `input/repair/index.blade.php` (daftar + filter + export)

**Files:**
- Create: `resources/views/input/repair/index.blade.php`
- Test: `tests/Feature/Input/HalamanDaftarRepairTest.php`

**Interfaces:**
- Consumes: `filterSearchComp`, `partGroupComp` (global di layout), `<x-export-modal>` (component existing di `resources/views/components/export-modal.blade.php`).

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanDaftarRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_daftar_menampilkan_baris_repair(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);

        TransaksiRepair::create([
            'tanggal_repair' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair.index'));

        $response->assertOk();
        $response->assertSee('PART SATU');
        $response->assertSee('REPAIRMAN SATU');
        $response->assertSee(route('input.repair.create'), false);
    }

    public function test_staff_repair_tidak_lihat_tombol_edit_hapus(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);
        TransaksiRepair::create([
            'tanggal_repair' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair.index'));

        $response->assertDontSee(route('input.repair.edit', TransaksiRepair::first()), false);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=HalamanDaftarRepairTest`
Expected: FAIL — view belum ada.

- [ ] **Step 3: Buat view**

```blade
@extends('layouts.app')
@section('title', 'LHR Repair')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@php
$_partsForFilter = $parts->map(fn ($p) => [
    'id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer,
    'proses' => $p->prosesRepair->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_allProsesForFilter = $parts->flatMap(fn ($p) => $p->prosesRepair)->unique('id')
    ->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_customersTA = $customers->map(fn ($c) => ['v' => $c, 't' => $c])->values();
$_partsTA     = $_partsForFilter->map(fn ($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']])->values();
$_prosesTA    = $_allProsesForFilter->map(fn ($pr) => ['v' => $pr['id'], 't' => $pr['label']])->values();
$_karyawanTA  = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();

$_karLabel    = $filterKarId ? ($karyawan->firstWhere('id', $filterKarId)?->nama_karyawan ?? '') : '';
$_partLabel   = $filterPartId ? ($parts->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$_prosesObjF  = $filterProsesId ? $parts->flatMap(fn ($p) => $p->prosesRepair)->firstWhere('id', $filterProsesId) : null;
$_prosesLabel = $_prosesObjF ? $_prosesObjF->id_proses.' – '.$_prosesObjF->nama_proses : '';
@endphp

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('input.repair.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Input Baru
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>
    @if(auth()->user()->hasRole('admin'))
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportRepairPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @endif
    <a href="{{ route('input.repair.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('input.repair.index') }}" class="erp-dock-body">

        <div class="erp-grp">
            <b>Find</b>
            <div x-data="filterSearchComp(_karyawanTA, {{ $filterKarId ?: 0 }}, '{{ addslashes($_karLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_karyawan_id" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Nama Operator"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
        </div>

        <div class="erp-grp">
            <b>Part &amp; Customer</b>
            <div x-data="filterSearchComp(_customersTA, '{{ $filterCustomer }}', '{{ addslashes($filterCustomer) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_customer" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Nama Customer"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>

            <div x-data="partGroupComp({{ $filterPartId ?: 0 }}, '{{ addslashes($_partLabel) }}', {{ $filterProsesId ?: 0 }}, '{{ addslashes($_prosesLabel) }}')"
                 style="display:flex;flex-direction:column;gap:4px">
                <div @click.outside="partOpen=false" style="position:relative">
                    <input type="hidden" name="filter_part_id" :value="partVal">
                    <input type="text" x-model="partQuery" @focus="partOpen=true"
                           @input="partOpen=true;partVal='';prosesVal='';prosesQuery=''"
                           @keydown.escape="partOpen=false" placeholder="Nama Part"
                           class="erp-in" :class="partVal ? 'is-set' : ''">
                    <button type="button" x-show="partVal"
                            @mousedown.prevent="partVal='';partQuery='';prosesVal='';prosesQuery=''"
                            class="erp-clr">&times;</button>
                    <div x-show="partOpen && filteredParts.length > 0" x-cloak class="erp-dd">
                        <template x-for="item in filteredParts" :key="item.v">
                            <div @mousedown.prevent="pickPart(item)" class="erp-dd-item" :title="item.t">
                                <span style="font-weight:700" x-text="item.t"></span>
                                <span class="sub" x-text="item.sub"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div @click.outside="prosesOpen=false" style="position:relative">
                    <input type="hidden" name="filter_proses_id" :value="prosesVal">
                    <input type="text" x-model="prosesQuery" @focus="prosesOpen=true"
                           @input="prosesOpen=true;prosesVal=''" @keydown.escape="prosesOpen=false"
                           placeholder="Proses Repair" class="erp-in" :class="prosesVal ? 'is-set' : ''">
                    <button type="button" x-show="prosesVal" @mousedown.prevent="prosesVal='';prosesQuery=''" class="erp-clr">&times;</button>
                    <div x-show="prosesOpen && filteredProses.length > 0" x-cloak class="erp-dd">
                        <template x-for="item in filteredProses" :key="item.v">
                            <div @mousedown.prevent="prosesVal=item.v;prosesQuery=item.t;prosesOpen=false"
                                 class="erp-dd-item" x-text="item.t"></div>
                        </template>
                    </div>
                </div>

                <input type="text" class="erp-in" readonly tabindex="-1"
                       :value="partNo || 'Nomor Part'" :title="partNo">
            </div>
        </div>

        <div class="erp-grp" x-data="{ pakaiTgl: {{ $pakaiTanggal ? 'true' : 'false' }} }">
            <label class="erp-chk">
                <input type="checkbox" name="pakai_tanggal" value="1" x-model="pakaiTgl">
                <b>Filter by Date</b>
            </label>
            <div class="erp-dock-row">
                <span>From</span>
                <input type="date" name="date_from" value="{{ $dateFrom }}" class="erp-in" :disabled="!pakaiTgl">
            </div>
            <div class="erp-dock-row">
                <span>To</span>
                <input type="date" name="date_to" value="{{ $dateTo }}" class="erp-in" :disabled="!pakaiTgl">
            </div>
        </div>

        <div class="erp-grp">
            <input type="hidden" name="filter_apply" value="1">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('input.repair.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">

@if(auth()->user()->hasRole('admin'))
<x-export-modal
    panelId="exportRepairPanel"
    :exportUrl="route('input.repair.export')"
    title="Export LHR Repair"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
/>
@endif

<div class="erp-scroll">
        <table class="erp-table" style="min-width:1200px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th style="width:85px">Tanggal</th>
                    <th style="width:70px">Shift</th>
                    <th style="width:130px">Operator</th>
                    <th style="width:110px">Customer</th>
                    <th style="width:120px">Part No.</th>
                    <th style="width:200px">Part Name</th>
                    <th style="width:150px">Proses</th>
                    <th style="width:110px">Jam</th>
                    <th style="width:80px;text-align:right">Qty Act</th>
                    <th style="width:70px;text-align:right">SPH Std</th>
                    <th style="width:70px;text-align:right">SPH Act</th>
                    <th style="width:70px;text-align:right">W. Kerja</th>
                    <th style="width:70px;text-align:right">CT Act</th>
                    <th style="width:60px;text-align:right">%</th>
                    @if(auth()->user()->hasRole('admin'))
                    <th style="width:140px">Aksi</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse($repairs as $r)
                @php
                    $pp = $r->partProsesRepair;
                    $part = $pp?->part;
                    $sphStd = $pp?->sph_standar_repair ?? 0;
                    $sphAct = $r->sph_aktual;
                    $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal_repair)->format('d/m/Y') }}</td>
                    <td style="white-space:nowrap">{{ $r->shift?->nama_shift ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $r->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td style="color:#5d7283">{{ $part?->customer ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_no ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_name ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $pp?->nama_proses ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $r->jam_start }} – {{ $r->jam_finish }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($r->qty_aktual) }}</td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($sphStd) }}</td>
                    <td style="text-align:right;font-weight:600;color:{{ $sphAct >= $sphStd ? '#27ae60' : '#c0392b' }}">
                        {{ number_format($sphAct) }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($r->waktu_kerja_jam, 1) }}j</td>
                    <td style="text-align:right">{{ number_format($r->actual_cycle_time, 1) }}s</td>
                    <td style="text-align:right;font-weight:600;color:{{ $pct === null ? '#9ca3af' : ($pct >= 100 ? '#15803d' : '#b91c1c') }}">
                        {{ $pct !== null ? $pct.'%' : '—' }}
                    </td>
                    @if(auth()->user()->hasRole('admin'))
                    <td>
                        <div class="action-btns" style="flex-wrap:nowrap">
                            <a href="{{ route('input.repair.edit', $r) }}?back_query={{ urlencode(http_build_query(request()->query())) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.repair.destroy', $r) }}"
                                  onsubmit="return confirm('Hapus data ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ auth()->user()->hasRole('admin') ? 15 : 14 }}" style="text-align:center;color:#888;padding:20px">Belum ada data LHR.</td></tr>
            @endforelse
            </tbody>
        </table>
</div>

<div class="erp-pg">{{ $repairs->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($repairs->total()) }}</b> baris LHR</span>
    @if($filterActive)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>

<script>
@if (session('batch_tersimpan'))
(function() {
    try {
        localStorage.removeItem('optima_draft_lhr_repair_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_lhr_repair_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
</script>

</div>
@endsection

@push('scripts')
<script>
const _customersTA = @json($_customersTA);
const _partsTA     = @json($_partsTA);
const _prosesTA    = @json($_prosesTA);
const _karyawanTA  = @json($_karyawanTA);
</script>
@endpush
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=HalamanDaftarRepairTest`
Expected: PASS (2 test)

- [ ] **Step 5: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/repair/index.blade.php tests/Feature/Input/HalamanDaftarRepairTest.php
git commit -m "feat(repair): halaman daftar+filter+export LHR"
```

---

### Task 10: View `input/repair/edit.blade.php`

**Files:**
- Create: `resources/views/input/repair/edit.blade.php`
- Test: tambahkan method ke `tests/Feature/Input/RepairBatchTest.php` (Task 7 sudah punya file ini)

**Interfaces:**
- Consumes: route `input.repair.update` (Task 7), `UpdateRepairRequest` (Task 6).

- [ ] **Step 1: Tulis test yang gagal**

Tambah ke `tests/Feature/Input/RepairBatchTest.php`:

```php
    public function test_admin_bisa_update_repair_lewat_form_edit(): void
    {
        $repair = TransaksiRepair::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('input.repair.update', $repair), $this->baris([
            'qty_aktual' => 200,
        ]));

        $response->assertRedirect(route('input.repair.index'));
        $this->assertSame(200, $repair->fresh()->qty_aktual);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairBatchTest::test_admin_bisa_update_repair_lewat_form_edit`
Expected: FAIL — view `input.repair.edit` belum ada (controller `edit()` di Task 7 sudah `return view('input.repair.edit', ...)`, jadi request GET edit juga akan gagal kalau dites; test ini fokus ke PUT saja yang tidak butuh view untuk lulus, tapi Step 3 tetap wajib supaya `edit()` GET tidak 500 di pemakaian nyata).

- [ ] **Step 3: Buat view**

```blade
@extends('layouts.app')
@section('title', 'Edit LHR')

@php
    $_taKaryawan = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit LHR</h1>
    <a href="{{ route('input.repair.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->updateRepair->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->updateRepair->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:640px">
    <div class="erp-panel-header">Form Edit LHR</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.repair.update', $repair) }}">
            @csrf @method('PUT')
            <input type="hidden" name="back_query" value="{{ request('back_query') }}">

            <div class="mb-3">
                <label class="form-label">Tanggal Repair <span class="text-danger">*</span></label>
                <input type="date" name="tanggal_repair" class="form-control @error('tanggal_repair', 'updateRepair') is-invalid @enderror"
                       value="{{ old('tanggal_repair', $repair->tanggal_repair->format('Y-m-d')) }}">
                @error('tanggal_repair', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Shift <span class="text-danger">*</span></label>
                <select name="id_shift" class="form-select @error('id_shift', 'updateRepair') is-invalid @enderror">
                    @foreach ($shifts as $s)
                        <option value="{{ $s->id }}" @selected(old('id_shift', $repair->id_shift) == $s->id)>{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
                @error('id_shift', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Operator <span class="text-danger">*</span></label>
                <select name="id_karyawan" class="form-select @error('id_karyawan', 'updateRepair') is-invalid @enderror">
                    @foreach ($karyawan as $k)
                        <option value="{{ $k->id }}" @selected(old('id_karyawan', $repair->id_karyawan) == $k->id)>{{ $k->nama_karyawan }}</option>
                    @endforeach
                </select>
                @error('id_karyawan', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Part &amp; Proses Repair <span class="text-danger">*</span></label>
                <select name="id_part_proses_repair" class="form-select @error('id_part_proses_repair', 'updateRepair') is-invalid @enderror">
                    @foreach ($parts as $p)
                        @foreach ($p->prosesRepair as $pr)
                            <option value="{{ $pr->id }}" @selected(old('id_part_proses_repair', $repair->id_part_proses_repair) == $pr->id)>
                                {{ $p->part_no }} — {{ $p->part_name }} — {{ $pr->id_proses }} {{ $pr->nama_proses }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                @error('id_part_proses_repair', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-2 mb-3">
                <div class="col">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start', 'updateRepair') is-invalid @enderror"
                           value="{{ old('jam_start', $repair->jam_start) }}">
                    @error('jam_start', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish', 'updateRepair') is-invalid @enderror"
                           value="{{ old('jam_finish', $repair->jam_finish) }}">
                    @error('jam_finish', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Qty Act Repair <span class="text-danger">*</span></label>
                <input type="number" name="qty_aktual" min="1" class="form-control @error('qty_aktual', 'updateRepair') is-invalid @enderror"
                       value="{{ old('qty_aktual', $repair->qty_aktual) }}">
                @error('qty_aktual', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.repair.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=RepairBatchTest`
Expected: PASS (semua method di file ini, termasuk yang baru)

- [ ] **Step 5: Uji manual — buka `/input/repair/{id}/edit` sebagai admin di browser, pastikan form tampil terisi dan submit berhasil**

- [ ] **Step 6: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/repair/edit.blade.php tests/Feature/Input/RepairBatchTest.php
git commit -m "feat(repair): halaman edit LHR (admin only)"
```

---

### Task 11: Verifikasi akhir — suite penuh + gerbang migrasi

**Files:** tidak ada file baru — task verifikasi murni.

- [ ] **Step 1: Jalankan seluruh test suite**

Run: `php artisan test`
Expected: seluruh test PASS, termasuk yang sudah ada sebelumnya (tidak ada regresi).

- [ ] **Step 2: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: PASS — kedua migrasi baru (Task 1 & 2) sudah terdaftar di `deploy.yml`.

- [ ] **Step 3: Format seluruh kode**

Run: `vendor/bin/pint app tests`
Expected: tidak ada perubahan tersisa (semua sudah diformat di task masing-masing) — kalau ada, commit terpisah `chore: pint`.

- [ ] **Step 4: Uji manual end-to-end di browser**

1. Login admin → Master Data → Proses Repair → tambah 1 part + proses repair.
2. Buat user baru role `staff_repair` (Master → Sistem → Users).
3. Login sebagai `staff_repair` → sidebar harus menampilkan grup "Repair" dan "Overview", TIDAK menampilkan grup lain.
4. Input LHR → isi 2 baris → submit → cek muncul di daftar.
5. Login admin → buka daftar LHR → cek tombol Edit/Hapus muncul, staff_repair tidak melihatnya.
6. Export Excel dari daftar LHR sebagai admin → file terunduh, kolom sesuai.

- [ ] **Step 5: Commit final kalau ada sisa perubahan format**

```bash
git status
# kalau ada perubahan pint yang belum di-commit:
git add -A
git commit -m "chore(repair): pint formatting"
```
