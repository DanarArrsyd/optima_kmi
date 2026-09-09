# LHR Produksi Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah sub-modul kedua "LHR Produksi" ke grup sidebar Repair — alur repair yang dikerjakan operator produksi sendiri, tabel & master data terpisah total dari LHR Regular yang sudah ada.

**Architecture:** Duplikasi 1:1 struktur modul Repair (LHR Regular) yang sudah live: tabel `master_part_proses_repair_produksi` + `transaksi_repair_produksi`, model, service, FormRequest, controller, export, dan 6 view baru. Field form identik LHR Regular. Beda utama: role akses input/daftar/export mencakup `staff_produksi` juga (bukan cuma `admin,staff_repair`), dan dua bug yang sempat kejadian di LHR Regular (CT stale saat edit, validasi SPH nullable padahal kolom NOT NULL) diimplementasikan BENAR sejak awal, bukan ditambal belakangan.

**Tech Stack:** Laravel 13, PHP 8.4, MySQL (SQLite di test), Bootstrap 5.3.3 + Alpine.js (komponen `batchDraft`/`taField`/`partPicker`/`filterSearchComp`/`partGroupComp` sudah ada di `resources/views/layouts/app.blade.php`, JANGAN diduplikasi), Maatwebsite Excel 4.x.

## Global Constraints

- CI & prod PHP 8.4 — jangan pakai sintaks 8.5-only.
- Semua model baru pakai `SoftDeletes`.
- Migrasi baru WAJIB didaftarkan di `.github/workflows/deploy.yml` step "Migrate (targeted)" — di task yang sama dengan migrasinya.
- `RepairProduksiWriteService` WAJIB punya method public `hitungCycleTime()` SEJAK TASK PERTAMA yang membuatnya (dipakai `simpan()` DAN `RepairProduksiController::update()`) — JANGAN ulangi bug LHR Regular di mana `update()` lupa menghitung ulang `actual_cycle_time`.
- Kolom `sph_standar_repair_produksi` di migrasi adalah `decimal(8,2)->default(0)` **NOT NULL** — validasi FormRequest WAJIB `required|numeric|min:0`, JANGAN `nullable` (bug yang sempat kejadian di LHR Regular, bikin 500 saat field dikosongkan).
- Route untuk resource `proses-repair-produksi` WAJIB manual (bukan `Route::resource()`), placeholder URI camelCase `{prosesRepairProduksi}` cocok persis dengan nama parameter controller `$prosesRepairProduksi` — Laravel implicit route-model-binding cocokkan lewat NAMA, `Route::resource()` otomatis bikin placeholder snake_case yang TIDAK cocok.
- Role akses `input.repair-produksi.index|create|batch|export` → `admin,staff_repair,staff_produksi`. `input.repair-produksi.edit|update|destroy` → `admin` SAJA (ikut konvensi seluruh keluarga LHP — tombol Edit/Hapus di UI juga admin-only).
- Export button & modal di view index WAJIB role-gate `admin,staff_repair,staff_produksi` (SEMUA role yang punya akses route export) — bukan admin-only.
- Tidak ada `@json()` yang di-inline langsung ke atribut `x-data="..."` — semua array data lewat `const` di `@push('scripts')`.
- `MasterPartProsesRepairProduksi.id_part` tetap FK ke `MasterPart` yang sama (part fisik satu sumber kebenaran).
- `master_part_proses_repair_produksi` & `transaksi_repair_produksi` WAJIB didaftarkan ke `app/Services/Chatbot/BusinessTableCatalog.php` DI TASK YANG SAMA modul ini selesai — jangan ditunda seperti LHR Regular.
- Jalankan `php artisan test` setelah tiap task. Jalankan `vendor/bin/pint app tests` sebelum commit file PHP baru. Jalankan `./scripts/check-migration-coverage.sh` sebelum PR.

---

### Task 1: Migrasi + Model `MasterPartProsesRepairProduksi`

**Files:**
- Create: `database/migrations/2026_09_10_100001_create_master_part_proses_repair_produksi_table.php`
- Create: `app/Models/MasterPartProsesRepairProduksi.php`
- Modify: `app/Models/MasterPart.php` (tambah relasi `prosesRepairProduksi()`)
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Unit/MasterPartProsesRepairProduksiTest.php`

**Interfaces:**
- Produces: `MasterPartProsesRepairProduksi` model, `$fillable = ['id_part', 'id_proses', 'nama_proses', 'sph_standar_repair_produksi']`, relasi `part(): BelongsTo`, `transaksiRepairProduksi(): HasMany`. `MasterPart::prosesRepairProduksi(): HasMany`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPartProsesRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bisa_dibuat_dan_terhubung_ke_part(): void
    {
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);

        $proses = MasterPartProsesRepairProduksi::create([
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS PRODUKSI',
            'sph_standar_repair_produksi' => 300,
        ]);

        $this->assertTrue($proses->part->is($part));
        $this->assertTrue($part->prosesRepairProduksi->first()->is($proses));
    }

    public function test_soft_delete(): void
    {
        $part = MasterPart::create(['part_no' => 'P-2', 'part_name' => 'PART DUA']);
        $proses = MasterPartProsesRepairProduksi::create([
            'id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100,
        ]);

        $proses->delete();

        $this->assertSoftDeleted('master_part_proses_repair_produksi', ['id' => $proses->id]);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=MasterPartProsesRepairProduksiTest`
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
        Schema::create('master_part_proses_repair_produksi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_part')->constrained('master_part')->cascadeOnDelete();
            $table->string('id_proses');
            $table->string('nama_proses');
            $table->decimal('sph_standar_repair_produksi', 8, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['id_part', 'id_proses']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_part_proses_repair_produksi');
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

class MasterPartProsesRepairProduksi extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_part_proses_repair_produksi';

    protected $fillable = ['id_part', 'id_proses', 'nama_proses', 'sph_standar_repair_produksi'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_part_proses_repair_produksi');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MasterPart::class, 'id_part');
    }

    public function transaksiRepairProduksi(): HasMany
    {
        return $this->hasMany(TransaksiRepairProduksi::class, 'id_part_proses_repair_produksi');
    }
}
```

- [ ] **Step 5: Tambah relasi di `MasterPart.php`**

Edit `app/Models/MasterPart.php`, tambah setelah method `prosesRepair()`:

```php
    public function prosesRepairProduksi(): HasMany
    {
        return $this->hasMany(MasterPartProsesRepairProduksi::class, 'id_part');
    }
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MasterPartProsesRepairProduksiTest`
Expected: PASS (2 test)

- [ ] **Step 7: Daftarkan migrasi di `deploy.yml`**

Buka `.github/workflows/deploy.yml`, cari step "Migrate (targeted)" (baris `--path=database/migrations/2026_09_09_100002_create_transaksi_repair_table.php` adalah baris terakhir dari modul LHR Regular). Tambah baris baru setelahnya untuk file migrasi Step 3 di atas, format persis baris lain.

- [ ] **Step 8: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add database/migrations/2026_09_10_100001_create_master_part_proses_repair_produksi_table.php \
        app/Models/MasterPartProsesRepairProduksi.php app/Models/MasterPart.php \
        .github/workflows/deploy.yml tests/Unit/MasterPartProsesRepairProduksiTest.php
git commit -m "feat(repair-produksi): tambah master part proses repair produksi"
```

---

### Task 2: Migrasi + Model `TransaksiRepairProduksi`

**Files:**
- Create: `database/migrations/2026_09_10_100002_create_transaksi_repair_produksi_table.php`
- Create: `app/Models/TransaksiRepairProduksi.php`
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Unit/TransaksiRepairProduksiTest.php`

**Interfaces:**
- Consumes: `MasterPartProsesRepairProduksi` (Task 1).
- Produces: `TransaksiRepairProduksi` model, `$fillable = ['tanggal_repair_produksi','id_shift','id_karyawan','id_part_proses_repair_produksi','jam_start','jam_finish','qty_aktual','actual_cycle_time','created_by','updated_by']`, accessor `waktu_kerja_jam` (float), `sph_aktual` (int), static `hitungDurasiMenit()`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransaksiRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_durasi_menit_terisi_otomatis_saat_disimpan(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);

        $repair = TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id,
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
        $this->assertSame(120, TransaksiRepairProduksi::hitungDurasiMenit('23:00', '01:00'));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=TransaksiRepairProduksiTest`
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
        Schema::create('transaksi_repair_produksi', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal_repair_produksi');
            $table->foreignId('id_shift')->constrained('master_shift');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->foreignId('id_part_proses_repair_produksi')->constrained('master_part_proses_repair_produksi');
            $table->time('jam_start');
            $table->time('jam_finish');
            $table->integer('durasi_menit')->default(0);
            $table->integer('qty_aktual');
            $table->decimal('actual_cycle_time', 8, 2)->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['tanggal_repair_produksi', 'id_karyawan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_repair_produksi');
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

class TransaksiRepairProduksi extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'transaksi_repair_produksi';

    protected $fillable = [
        'tanggal_repair_produksi', 'id_shift', 'id_karyawan', 'id_part_proses_repair_produksi',
        'jam_start', 'jam_finish', 'qty_aktual', 'actual_cycle_time',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tanggal_repair_produksi' => 'date:Y-m-d',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('lhr_repair_produksi');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function partProsesRepairProduksi(): BelongsTo
    {
        return $this->belongsTo(MasterPartProsesRepairProduksi::class, 'id_part_proses_repair_produksi');
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
            if (Schema::hasColumn('transaksi_repair_produksi', 'durasi_menit')) {
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

Run: `php artisan test --filter=TransaksiRepairProduksiTest`
Expected: PASS (2 test)

- [ ] **Step 6: Daftarkan migrasi di `deploy.yml`** (baris baru setelah baris Task 1)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_09_10_100002_create_transaksi_repair_produksi_table.php \
        app/Models/TransaksiRepairProduksi.php .github/workflows/deploy.yml \
        tests/Unit/TransaksiRepairProduksiTest.php
git commit -m "feat(repair-produksi): tambah transaksi repair produksi"
```

---

### Task 3: Master Proses Repair Produksi — FormRequest + Controller + Routes + Views + Sidebar

**Files:**
- Create: `app/Http/Requests/StoreMasterPartProsesRepairProduksiRequest.php`
- Create: `app/Http/Controllers/Master/ProsesRepairProduksiController.php`
- Modify: `routes/web.php`
- Create: `resources/views/master/proses-repair-produksi/index.blade.php`
- Create: `resources/views/master/proses-repair-produksi/create.blade.php`
- Create: `resources/views/master/proses-repair-produksi/edit.blade.php`
- Modify: `config/sidebar.php`
- Test: `tests/Feature/Master/ProsesRepairProduksiCrudTest.php`

**Interfaces:**
- Consumes: `MasterPartProsesRepairProduksi`, `MasterPart` (Task 1).
- Produces: routes `master.proses-repair-produksi.index|create|store|edit|update|destroy`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Master;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesRepairProduksiCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_crud_proses_repair_produksi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->get(route('master.proses-repair-produksi.index'))->assertOk();

        $this->actingAs($admin)->post(route('master.proses-repair-produksi.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS PRODUKSI',
            'sph_standar_repair_produksi' => 200,
        ])->assertRedirect(route('master.proses-repair-produksi.index'));

        $this->assertDatabaseHas('master_part_proses_repair_produksi', ['id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS PRODUKSI']);

        $proses = MasterPartProsesRepairProduksi::first();

        $this->actingAs($admin)->put(route('master.proses-repair-produksi.update', $proses), [
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS UPDATE',
            'sph_standar_repair_produksi' => 250,
        ])->assertRedirect(route('master.proses-repair-produksi.index'));

        $this->assertDatabaseHas('master_part_proses_repair_produksi', ['id' => $proses->id, 'nama_proses' => 'REPAIR LAS UPDATE']);

        $this->actingAs($admin)->delete(route('master.proses-repair-produksi.destroy', $proses))
            ->assertRedirect(route('master.proses-repair-produksi.index'));

        $this->assertSoftDeleted('master_part_proses_repair_produksi', ['id' => $proses->id]);
    }

    public function test_sph_standar_repair_produksi_kosong_gagal_validasi_bukan_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->post(route('master.proses-repair-produksi.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS PRODUKSI',
            'sph_standar_repair_produksi' => '',
        ])->assertSessionHasErrors('sph_standar_repair_produksi');

        $this->assertDatabaseMissing('master_part_proses_repair_produksi', ['id_proses' => 'RPP1']);
    }

    public function test_staff_produksi_tidak_bisa_akses_master_proses_repair_produksi(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('master.proses-repair-produksi.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=ProsesRepairProduksiCrudTest`
Expected: FAIL — route belum ada.

- [ ] **Step 3: Buat FormRequest**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMasterPartProsesRepairProduksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        $id = $this->route('prosesRepairProduksi')?->id;

        return [
            'id_part' => 'required|exists:master_part,id',
            'id_proses' => [
                'required', 'string', 'max:50',
                Rule::unique('master_part_proses_repair_produksi', 'id_proses')
                    ->where('id_part', $this->input('id_part'))
                    ->ignore($id)
                    ->whereNull('deleted_at'),
            ],
            'nama_proses' => 'required|string|max:100',
            'sph_standar_repair_produksi' => 'required|numeric|min:0',
        ];
    }
}
```

- [ ] **Step 4: Buat Controller**

```php
<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartProsesRepairProduksiRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use Illuminate\Http\Request;

class ProsesRepairProduksiController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $prosesRepairProduksi = MasterPartProsesRepairProduksi::with('part')
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $like = '%'.$search.'%';
                $sub->where('nama_proses', 'like', $like)
                    ->orWhere('id_proses', 'like', $like)
                    ->orWhereHas('part', fn ($p) => $p->where('part_name', 'like', $like)
                        ->orWhere('part_no', 'like', $like));
            }))
            ->orderBy('id_proses')
            ->paginate(20)->withQueryString();

        return view('master.proses-repair-produksi.index', compact('prosesRepairProduksi', 'search'));
    }

    public function create()
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair-produksi.create', compact('parts'));
    }

    public function store(StoreMasterPartProsesRepairProduksiRequest $request)
    {
        MasterPartProsesRepairProduksi::create($request->validated());

        return redirect()->route('master.proses-repair-produksi.index')->with('success', 'Proses Repair Produksi berhasil ditambahkan.');
    }

    public function edit(MasterPartProsesRepairProduksi $prosesRepairProduksi)
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair-produksi.edit', compact('prosesRepairProduksi', 'parts'));
    }

    public function update(StoreMasterPartProsesRepairProduksiRequest $request, MasterPartProsesRepairProduksi $prosesRepairProduksi)
    {
        $prosesRepairProduksi->update($request->validated());

        return redirect()->route('master.proses-repair-produksi.index')->with('success', 'Proses Repair Produksi berhasil diupdate.');
    }

    public function destroy(MasterPartProsesRepairProduksi $prosesRepairProduksi)
    {
        $prosesRepairProduksi->delete();

        return redirect()->route('master.proses-repair-produksi.index')->with('success', 'Proses Repair Produksi berhasil dihapus.');
    }
}
```

- [ ] **Step 5: Tambah routes**

Edit `routes/web.php`, tambah import (urutan alfabetis dengan controller Master lain, dekat `ProsesRepairController`):

```php
use App\Http\Controllers\Master\ProsesRepairProduksiController;
```

Di dalam grup master admin-only, tambah SETELAH baris `Route::delete('proses-repair/{prosesRepair}', ...)` (baris 79 saat ini):

```php
        Route::get('proses-repair-produksi', [ProsesRepairProduksiController::class, 'index'])->name('proses-repair-produksi.index');
        Route::get('proses-repair-produksi/create', [ProsesRepairProduksiController::class, 'create'])->name('proses-repair-produksi.create');
        Route::post('proses-repair-produksi', [ProsesRepairProduksiController::class, 'store'])->name('proses-repair-produksi.store');
        Route::get('proses-repair-produksi/{prosesRepairProduksi}/edit', [ProsesRepairProduksiController::class, 'edit'])->name('proses-repair-produksi.edit');
        Route::put('proses-repair-produksi/{prosesRepairProduksi}', [ProsesRepairProduksiController::class, 'update'])->name('proses-repair-produksi.update');
        Route::delete('proses-repair-produksi/{prosesRepairProduksi}', [ProsesRepairProduksiController::class, 'destroy'])->name('proses-repair-produksi.destroy');
```

Placeholder `{prosesRepairProduksi}` cocok persis parameter controller `$prosesRepairProduksi`.

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=ProsesRepairProduksiCrudTest`
Expected: PASS (3 test)

- [ ] **Step 7: Buat view index**

```blade
@extends('layouts.app')
@section('title', 'Master Proses Repair Produksi')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.proses-repair-produksi.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <a href="{{ route('master.proses-repair-produksi.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('master.proses-repair-produksi.index') }}" class="erp-dock-body">
        <div class="erp-grp">
            <b>Find</b>
            <input type="text" name="q" value="{{ $search }}" class="erp-in @if($search !== '') is-set @endif"
                   placeholder="Part atau Nama Proses">
        </div>
        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('master.proses-repair-produksi.index') }}" class="erp-btn">Reset</a>
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
        @forelse($prosesRepairProduksi as $pr)
            <tr>
                <td>{{ $pr->part?->part_no ?? '—' }}</td>
                <td>{{ $pr->part?->part_name ?? '—' }}</td>
                <td style="font-family:monospace">{{ $pr->id_proses }}</td>
                <td>{{ $pr->nama_proses }}</td>
                <td style="text-align:right">{{ number_format($pr->sph_standar_repair_produksi) }}</td>
                <td>
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.proses-repair-produksi.edit', $pr) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.proses-repair-produksi.destroy', $pr) }}"
                              onsubmit="return confirm('Hapus proses repair produksi ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Belum ada proses repair produksi.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="erp-pg">{{ $prosesRepairProduksi->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($prosesRepairProduksi->total()) }}</b> proses repair produksi</span>
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
@section('title', 'Tambah Proses Repair Produksi')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Tambah Proses Repair Produksi</h1>
    <a href="{{ route('master.proses-repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Tambah Proses Repair Produksi</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.proses-repair-produksi.store') }}">
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
                       value="{{ old('id_proses') }}" placeholder="Contoh: RPP1">
                @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                       value="{{ old('nama_proses') }}" placeholder="Contoh: REPAIR LAS PRODUKSI">
                @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">SPH Standar Repair Produksi <span class="text-danger">*</span></label>
                <input type="number" step="0.01" name="sph_standar_repair_produksi"
                       class="form-control @error('sph_standar_repair_produksi') is-invalid @enderror"
                       value="{{ old('sph_standar_repair_produksi') }}" min="0">
                @error('sph_standar_repair_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.proses-repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 9: Buat view edit**

```blade
@extends('layouts.app')
@section('title', 'Edit Proses Repair Produksi')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit Proses Repair Produksi</h1>
    <a href="{{ route('master.proses-repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Edit Proses Repair Produksi</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.proses-repair-produksi.update', $prosesRepairProduksi) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Part <span class="text-danger">*</span></label>
                <select name="id_part" class="form-select @error('id_part') is-invalid @enderror">
                    @foreach($parts as $p)
                        <option value="{{ $p->id }}" @selected(old('id_part', $prosesRepairProduksi->id_part) == $p->id)>{{ $p->part_no }} — {{ $p->part_name }}</option>
                    @endforeach
                </select>
                @error('id_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                       value="{{ old('id_proses', $prosesRepairProduksi->id_proses) }}">
                @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                       value="{{ old('nama_proses', $prosesRepairProduksi->nama_proses) }}">
                @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">SPH Standar Repair Produksi <span class="text-danger">*</span></label>
                <input type="number" step="0.01" name="sph_standar_repair_produksi"
                       class="form-control @error('sph_standar_repair_produksi') is-invalid @enderror"
                       value="{{ old('sph_standar_repair_produksi', $prosesRepairProduksi->sph_standar_repair_produksi) }}" min="0">
                @error('sph_standar_repair_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.proses-repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 10: Tambah item sidebar**

Edit `config/sidebar.php`, di grup `key => 'master'`, tambah SETELAH baris item "Proses Repair" (baris 141 saat ini):

```php
            ['label' => 'Proses Repair Produksi', 'icon' => 'bi-bandaid', 'route' => 'master.proses-repair-produksi.index', 'roles' => ['admin'], 'match' => ['master.proses-repair-produksi.*']],
```

- [ ] **Step 11: Jalankan suite Master, pastikan tidak ada regresi**

Run: `php artisan test --filter=Master`
Expected: PASS

- [ ] **Step 12: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/StoreMasterPartProsesRepairProduksiRequest.php \
        app/Http/Controllers/Master/ProsesRepairProduksiController.php \
        routes/web.php config/sidebar.php \
        resources/views/master/proses-repair-produksi \
        tests/Feature/Master/ProsesRepairProduksiCrudTest.php
git commit -m "feat(repair-produksi): CRUD master proses repair produksi (admin-only)"
```

---

### Task 4: `RepairProduksiWriteService` + `StoreRepairProduksiBatchRequest`

**Files:**
- Create: `app/Services/RepairProduksiWriteService.php`
- Create: `app/Http/Requests/StoreRepairProduksiBatchRequest.php`
- Test: `tests/Unit/RepairProduksiWriteServiceTest.php`

**Interfaces:**
- Consumes: `TransaksiRepairProduksi` (Task 2).
- Produces: `RepairProduksiWriteService::simpan(array $baris): TransaksiRepairProduksi`, `RepairProduksiWriteService::hitungCycleTime(string $jamStart, string $jamFinish, int $qtyAktual): float` (PUBLIC — dipakai Task 6's `update()` juga, WAJIB ada sejak task ini). `StoreRepairProduksiBatchRequest::validated()['rows']`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use App\Services\RepairProduksiWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairProduksiWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simpan_menulis_satu_baris_dengan_actual_cycle_time_terhitung(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));

        $service = app(RepairProduksiWriteService::class);

        $repair = $service->simpan([
            'tanggal_repair_produksi' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ]);

        $this->assertInstanceOf(TransaksiRepairProduksi::class, $repair);
        $this->assertSame(72.0, $repair->actual_cycle_time);
        $this->assertNotNull($repair->created_by);
    }

    public function test_hitung_cycle_time_public_dan_bisa_dipanggil_ulang(): void
    {
        $service = app(RepairProduksiWriteService::class);

        $this->assertSame(24.0, $service->hitungCycleTime('08:00:00', '10:00:00', 300));
        $this->assertSame(0.0, $service->hitungCycleTime('08:00:00', '10:00:00', 0));
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairProduksiWriteServiceTest`
Expected: FAIL — class belum ada.

- [ ] **Step 3: Buat service**

```php
<?php

namespace App\Services;

use App\Models\TransaksiRepairProduksi;

/**
 * Menulis SATU baris LHR Produksi. Sengaja TIDAK membuka transaksi sendiri:
 * pemanggil (RepairProduksiController::batch) yang menentukan batasnya.
 */
class RepairProduksiWriteService
{
    public function simpan(array $baris): TransaksiRepairProduksi
    {
        $cycleTime = $this->hitungCycleTime($baris['jam_start'].':00', $baris['jam_finish'].':00', (int) $baris['qty_aktual']);

        return TransaksiRepairProduksi::create([
            ...$baris,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);
    }

    /** Cycle time aktual (detik / qty), dipakai saat create MAUPUN update. */
    public function hitungCycleTime(string $jamStart, string $jamFinish, int $qtyAktual): float
    {
        if ($qtyAktual === 0) {
            return 0.0;
        }

        return round($this->durasiDetik($jamStart, $jamFinish) / $qtyAktual, 2);
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

Run: `php artisan test --filter=RepairProduksiWriteServiceTest`
Expected: PASS (2 test)

- [ ] **Step 5: Buat `StoreRepairProduksiBatchRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRepairProduksiBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.tanggal_repair_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
            'rows.*.id_part_proses_repair_produksi' => 'required|exists:master_part_proses_repair_produksi,id',
            'rows.*.jam_start' => 'required|date_format:H:i',
            'rows.*.jam_finish' => 'required|date_format:H:i',
            'rows.*.qty_aktual' => 'required|integer|min:1',
        ];
    }

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
            'rows.*.tanggal_repair_produksi.required' => 'Baris :position: Tanggal Repair wajib diisi.',
            'rows.*.id_shift.required' => 'Baris :position: Shift wajib dipilih.',
            'rows.*.id_karyawan.required' => 'Baris :position: Operator wajib dipilih.',
            'rows.*.id_part_proses_repair_produksi.required' => 'Baris :position: Part dan Proses Repair Produksi wajib dipilih.',
            'rows.*.id_part_proses_repair_produksi.exists' => 'Baris :position: Part/Proses Repair Produksi yang dipilih tidak valid.',
            'rows.*.jam_start.required' => 'Baris :position: Jam Start wajib diisi.',
            'rows.*.jam_finish.required' => 'Baris :position: Jam Finish wajib diisi.',
            'rows.*.qty_aktual.required' => 'Baris :position: Qty Act Repair wajib diisi.',
            'rows.*.qty_aktual.min' => 'Baris :position: Qty Act Repair minimal 1.',
        ];
    }

    public function attributes(): array
    {
        return [
            'rows.*.tanggal_repair_produksi' => 'Tanggal Repair',
            'rows.*.id_shift' => 'Shift',
            'rows.*.id_karyawan' => 'Operator',
            'rows.*.id_part_proses_repair_produksi' => 'Proses Repair Produksi',
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
git add app/Services/RepairProduksiWriteService.php app/Http/Requests/StoreRepairProduksiBatchRequest.php \
        tests/Unit/RepairProduksiWriteServiceTest.php
git commit -m "feat(repair-produksi): RepairProduksiWriteService + StoreRepairProduksiBatchRequest"
```

---

### Task 5: `UpdateRepairProduksiRequest` + `LhpRepairProduksiExport`

**Files:**
- Create: `app/Http/Requests/UpdateRepairProduksiRequest.php`
- Create: `app/Exports/LhpRepairProduksiExport.php`
- Test: `tests/Unit/LhpRepairProduksiExportTest.php`

**Interfaces:**
- Produces: `UpdateRepairProduksiRequest::validated(): array` (dipakai Task 6). `LhpRepairProduksiExport` implements `FromCollection, WithHeadings, WithColumnWidths, WithStyles`, constructor `(?string $dateFrom, ?string $dateTo, ?string $customer, ?string $partName, ?string $namaProses)`, 16 kolom.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit;

use App\Exports\LhpRepairProduksiExport;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LhpRepairProduksiExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_berisi_baris_repair_produksi_dengan_kolom_lengkap(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);

        TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $export = new LhpRepairProduksiExport('2026-09-01', '2026-09-01', null, null, null);
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame('CUST A', $rows->first()[3]);
        $this->assertCount(16, $export->headings());
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=LhpRepairProduksiExportTest`
Expected: FAIL — class belum ada.

- [ ] **Step 3: Buat `UpdateRepairProduksiRequest`**

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRepairProduksiRequest extends FormRequest
{
    protected $errorBag = 'updateRepairProduksi';

    public function authorize(): bool
    {
        return $this->user()->hasRole('admin');
    }

    public function rules(): array
    {
        return [
            'tanggal_repair_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'id_part_proses_repair_produksi' => 'required|exists:master_part_proses_repair_produksi,id',
            'jam_start' => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
            'qty_aktual' => 'required|integer|min:1',
        ];
    }
}
```

- [ ] **Step 4: Buat `LhpRepairProduksiExport`**

```php
<?php

namespace App\Exports;

use App\Models\TransaksiRepairProduksi;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LhpRepairProduksiExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
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
        $q = TransaksiRepairProduksi::with(['karyawan', 'partProsesRepairProduksi.part', 'shift']);

        if ($this->dateFrom) {
            $q->whereDate('tanggal_repair_produksi', '>=', $this->dateFrom);
        }
        if ($this->dateTo) {
            $q->whereDate('tanggal_repair_produksi', '<=', $this->dateTo);
        }
        if ($this->customer) {
            $q->whereHas('partProsesRepairProduksi.part', fn ($q) => $q->where('customer', 'like', "%{$this->customer}%"));
        }
        if ($this->partName) {
            $q->whereHas('partProsesRepairProduksi.part', fn ($q) => $q->where('part_name', 'like', "%{$this->partName}%"));
        }
        if ($this->namaProses) {
            $q->whereHas('partProsesRepairProduksi', fn ($q) => $q->where('nama_proses', 'like', "%{$this->namaProses}%"));
        }

        return $q->orderBy('tanggal_repair_produksi')->orderBy('id')->get()->map(function ($t) {
            $pp = $t->partProsesRepairProduksi;
            $part = $pp?->part;
            $sphStd = $pp?->sph_standar_repair_produksi ?? 0;
            $sphAct = $t->sph_aktual;
            $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : '';

            return [
                $t->tanggal_repair_produksi,
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

Run: `php artisan test --filter=LhpRepairProduksiExportTest`
Expected: PASS

- [ ] **Step 6: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/UpdateRepairProduksiRequest.php app/Exports/LhpRepairProduksiExport.php \
        tests/Unit/LhpRepairProduksiExportTest.php
git commit -m "feat(repair-produksi): UpdateRepairProduksiRequest + LhpRepairProduksiExport"
```

---

### Task 6: `RepairProduksiController` (Input) + Routes + Sidebar (rename + item baru)

**Files:**
- Create: `app/Http/Controllers/Input/RepairProduksiController.php`
- Modify: `routes/web.php`
- Modify: `config/sidebar.php`
- Test: `tests/Feature/Input/RepairProduksiBatchTest.php`
- Test: `tests/Feature/AuthorizationTest.php`

**Interfaces:**
- Consumes: `RepairProduksiWriteService`, `StoreRepairProduksiBatchRequest` (Task 4), `UpdateRepairProduksiRequest`, `LhpRepairProduksiExport` (Task 5).
- Produces: routes `input.repair-produksi.index|create|batch|edit|update|destroy|export`.

**Catatan sekuensial penting:** view `input/repair-produksi/index|create|edit.blade.php` BELUM ada sampai Task 7-9 selesai. Test di task ini HANYA boleh assert hal yang tidak butuh render sukses: boundary 403 untuk role yang salah, dan `update()`/`destroy()` yang cuma redirect (tidak render view). JANGAN tulis `assertOk()` untuk GET index/create/edit di task ini — itu bakal 500 karena view belum ada. Assertion render-sukses itu ditambahkan di Task 7 (create), 8 (index), 9 (edit) begitu view masing-masing sudah dibuat — ini keputusan desain sejak awal, BUKAN trim darurat seperti yang terjadi di LHR Regular.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairProduksiBatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);

        $this->ref = compact('shift', 'karyawan', 'proses');
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_repair_produksi' => '2026-09-01',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_part_proses_repair_produksi' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ], $override);
    }

    public function test_batch_menyimpan_banyak_baris_sekaligus(): void
    {
        $response = $this->post(route('input.repair-produksi.batch'), [
            'rows' => [$this->baris(), $this->baris(['qty_aktual' => 50])],
        ]);

        $response->assertRedirect(route('input.repair-produksi.index'));
        $this->assertDatabaseCount('transaksi_repair_produksi', 2);
    }

    public function test_batch_menolak_baris_tanpa_qty(): void
    {
        $response = $this->post(route('input.repair-produksi.batch'), [
            'rows' => [$this->baris(['qty_aktual' => 0])],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('transaksi_repair_produksi', 0);
    }

    public function test_staff_qc_tidak_bisa_akses_input_repair_produksi(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff_qc']))
            ->get(route('input.repair-produksi.index'))
            ->assertForbidden();
    }

    public function test_hanya_admin_bisa_edit_dan_hapus_repair_produksi(): void
    {
        $repair = TransaksiRepairProduksi::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);

        $this->get(route('input.repair-produksi.edit', $repair))->assertForbidden();
        $this->delete(route('input.repair-produksi.destroy', $repair))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        // assertOk() untuk GET edit SENGAJA belum ditulis di sini — view belum
        // ada sampai Task 9. Cukup uji destroy (redirect, nol render).
        $this->actingAs($admin)->delete(route('input.repair-produksi.destroy', $repair))
            ->assertRedirect(route('input.repair-produksi.index'));
        $this->assertSoftDeleted('transaksi_repair_produksi', ['id' => $repair->id]);
    }

    public function test_update_repair_produksi_menghitung_ulang_actual_cycle_time(): void
    {
        $repair = TransaksiRepairProduksi::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('input.repair-produksi.update', $repair), $this->baris([
            'jam_start' => '08:00', 'jam_finish' => '10:00', 'qty_aktual' => 300,
        ]))->assertRedirect(route('input.repair-produksi.index'));

        $this->assertSame(24.0, $repair->fresh()->actual_cycle_time);
    }
}
```

Test suite di atas berisi 5 test — `test_batch_menyimpan_banyak_baris_sekaligus`, `test_batch_menolak_baris_tanpa_qty`, `test_staff_qc_tidak_bisa_akses_input_repair_produksi`, `test_hanya_admin_bisa_edit_dan_hapus_repair_produksi`, `test_update_repair_produksi_menghitung_ulang_actual_cycle_time` — semuanya TIDAK butuh view apa pun untuk lulus (403 checks dan redirect checks doang), sesuai catatan sekuensial di atas. Assertion render-sukses untuk index/create/edit ditambahkan masing-masing di Task 7 Step 1, Task 8 Step 1, dan Task 9 Step 1.

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairProduksiBatchTest`
Expected: FAIL — route belum ada.

- [ ] **Step 3: Buat Controller**

```php
<?php

namespace App\Http\Controllers\Input;

use App\Exports\LhpRepairProduksiExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepairProduksiBatchRequest;
use App\Http\Requests\UpdateRepairProduksiRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Services\RepairProduksiWriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RepairProduksiController extends Controller
{
    public function __construct(private RepairProduksiWriteService $repairProduksiWriteService) {}

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

        $q = TransaksiRepairProduksi::with(['karyawan', 'partProsesRepairProduksi.part', 'shift']);

        if ($pakaiTanggal) {
            $q->whereDate('tanggal_repair_produksi', '>=', $dateFrom)
                ->whereDate('tanggal_repair_produksi', '<=', $dateTo);
        }
        if ($filterCustomer !== '') {
            $q->whereHas('partProsesRepairProduksi.part', fn ($p) => $p->where('customer', $filterCustomer));
        }
        if ($filterPartId > 0) {
            $q->whereHas('partProsesRepairProduksi', fn ($pp) => $pp->where('id_part', $filterPartId));
        }
        if ($filterProsesId > 0) {
            $q->where('id_part_proses_repair_produksi', $filterProsesId);
        }
        if ($filterKarId > 0) {
            $q->where('id_karyawan', $filterKarId);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawan', fn ($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('shift', fn ($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('partProsesRepairProduksi', fn ($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProsesRepairProduksi.part', fn ($p) => $p->where('part_no', 'like', $like)
                        ->orWhere('part_name', 'like', $like)
                        ->orWhere('customer', 'like', $like));
            });
        }

        $repairs = $q->orderByDesc('tanggal_repair_produksi')->orderByDesc('created_at')->paginate(30)->withQueryString();

        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepairProduksi')->orderBy('part_name')->get();
        $customers = MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values();
        $filterActive = $search !== '' || $filterCustomer !== '' || $filterPartId > 0 || $filterProsesId > 0 || $filterKarId > 0;

        return view('input.repair-produksi.index', compact(
            'repairs', 'dateFrom', 'dateTo', 'search', 'pakaiTanggal',
            'filterCustomer', 'filterPartId', 'filterProsesId', 'filterKarId',
            'karyawan', 'parts', 'customers', 'filterActive'
        ));
    }

    public function create()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepairProduksi')->orderBy('part_name')->get();

        return view('input.repair-produksi.create', compact('shifts', 'karyawan', 'parts'));
    }

    public function batch(StoreRepairProduksiBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->repairProduksiWriteService->simpan($row);
            }
        });

        $jumlah = count($rows);

        return redirect()->route('input.repair-produksi.index')
            ->with('success', "{$jumlah} baris LHR Produksi berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }

    public function edit(TransaksiRepairProduksi $repairProduksi)
    {
        $repairProduksi->load(['karyawan', 'partProsesRepairProduksi.part']);
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepairProduksi')->orderBy('part_name')->get();

        return view('input.repair-produksi.edit', ['repair' => $repairProduksi] + compact('shifts', 'karyawan', 'parts'));
    }

    public function update(UpdateRepairProduksiRequest $request, TransaksiRepairProduksi $repairProduksi)
    {
        $validated = $request->validated();

        $cycleTime = $this->repairProduksiWriteService->hitungCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            (int) $validated['qty_aktual']
        );

        $repairProduksi->update([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
        ]);

        return redirect()->route('input.repair-produksi.index', $this->backQueryParams($request))
            ->with('success', 'LHR Produksi berhasil diupdate.');
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

        $filename = 'LHR_RepairProduksi_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(
            new LhpRepairProduksiExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function destroy(TransaksiRepairProduksi $repairProduksi)
    {
        $repairProduksi->delete();

        return back(fallback: route('input.repair-produksi.index'))->with('success', 'Data berhasil dihapus.');
    }
}
```

Catatan: route param name di URI adalah `{repairProduksi}` (bukan `{repair}`) — controller di atas pakai `$repairProduksi` di semua method supaya cocok. Route wajib dideklarasikan dengan placeholder `{repairProduksi}` persis (Step 4).

- [ ] **Step 4: Tambah import controller + routes**

Edit `routes/web.php`, tambah import:

```php
use App\Http\Controllers\Input\RepairProduksiController;
```

Tambah grup route baru (setelah grup route LHR Regular yang sudah ada — baris `Route::delete('repair/{repair}', ...)`):

```php
    // --- INPUT & DAFTAR LHR PRODUKSI (staff_produksi + staff_repair + admin) ---
    Route::middleware('role:admin,staff_repair,staff_produksi')->prefix('input')->name('input.')->group(function () {
        Route::get('repair-produksi', [RepairProduksiController::class, 'index'])->name('repair-produksi.index');
        Route::get('repair-produksi/create', [RepairProduksiController::class, 'create'])->name('repair-produksi.create');
        Route::post('repair-produksi/batch', [RepairProduksiController::class, 'batch'])->name('repair-produksi.batch');
        Route::get('repair-produksi/export', [RepairProduksiController::class, 'export'])->name('repair-produksi.export');
    });
```

Di dalam grup admin-only "EDIT/UPDATE LHP" yang sudah ada (tempat baris `repair.edit|update|destroy` LHR Regular berada), tambah:

```php
        Route::get('repair-produksi/{repairProduksi}/edit', [RepairProduksiController::class, 'edit'])->name('repair-produksi.edit');
        Route::put('repair-produksi/{repairProduksi}', [RepairProduksiController::class, 'update'])->name('repair-produksi.update');
        Route::delete('repair-produksi/{repairProduksi}', [RepairProduksiController::class, 'destroy'])->name('repair-produksi.destroy');
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=RepairProduksiBatchTest`
Expected: PASS (5 test)

- [ ] **Step 6: Ubah sidebar — rename item lama + tambah item baru**

Edit `config/sidebar.php`, grup `key => 'repair'`, ganti:

```php
            ['label' => 'Input LHR', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair.index', 'roles' => ['admin', 'staff_repair'], 'match' => ['input.repair.*']],
```

jadi DUA baris:

```php
            ['label' => 'LHR Regular', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair.index', 'roles' => ['admin', 'staff_repair'], 'match' => ['input.repair.*']],
            ['label' => 'LHR Produksi', 'icon' => 'bi-clipboard-data', 'route' => 'input.repair-produksi.index', 'roles' => ['admin', 'staff_repair', 'staff_produksi'], 'match' => ['input.repair-produksi.*']],
```

**PENTING soal `match`:** pola `input.repair.*` TIDAK menelan `input.repair-produksi.*` (nama route beda karena ada `-produksi` di tengah, bukan child path) — jadi dua entri di atas TIDAK saling menyalakan satu sama lain. Verifikasi ini eksplisit di Step 7.

- [ ] **Step 7: Tambah assertion boundary di `AuthorizationTest.php`**

Tambah method baru di `tests/Feature/AuthorizationTest.php` (dekat method boundary LHR Regular):

```php
    public function test_input_repair_produksi_bisa_diakses_staff_produksi_staff_repair_admin(): void
    {
        $this->actingAs($this->user('staff_produksi'))->post(route('input.repair-produksi.batch'), ['rows' => []])
            ->assertSessionHasErrors(); // authorized (403 tidak terjadi), gagal validasi rows kosong — itu bukti lolos middleware
        $this->actingAs($this->user('staff_qc'))->post(route('input.repair-produksi.batch'), ['rows' => []])
            ->assertForbidden();
        $this->actingAs($this->user('staff_maintenance'))->post(route('input.repair-produksi.batch'), ['rows' => []])
            ->assertForbidden();
        $this->actingAs($this->user('manager'))->post(route('input.repair-produksi.batch'), ['rows' => []])
            ->assertForbidden();
    }

```

Verifikasi manual (bukan test otomatis, cukup dicek pas Step 8 di bawah lewat kode `config/sidebar.php`): pola `match` untuk item "LHR Regular" adalah `['input.repair.*']` dan untuk "LHR Produksi" adalah `['input.repair-produksi.*']` — dua string route berbeda (`repair` vs `repair-produksi`), jadi `SidebarMenu`'s route-matching TIDAK akan menyalakan keduanya sekaligus. Test otomatis untuk "tepat satu entri menyala" sudah ada generik di `SidebarNavTest::test_tepat_satu_entri_menyala_di_tiap_halaman` (dijalankan Step 8) — itu cukup, tidak perlu test khusus tambahan di sini.

- [ ] **Step 8: Jalankan `AuthorizationTest`, `SidebarMenuTest`, `SidebarNavTest`, pastikan lulus**

Run: `php artisan test --filter=AuthorizationTest`
Run: `php artisan test --filter=SidebarMenuTest`
Run: `php artisan test --filter=SidebarNavTest`
Expected: `AuthorizationTest` PASS. `SidebarMenuTest`/`SidebarNavTest` KEMUNGKINAN BESAR PASS TANPA PERUBAHAN karena grup `repair` cuma nambah 1 anak (dari 1 jadi 2) — plafon anak (`ANAK_TERBESAR`, limit 16) jauh dari terlampaui, dan top-level tetap 9 (grup `repair` sudah ada sejak LHR Regular, tidak nambah baris top-level baru). Kalau ternyata ada test yang generic-loop semua route sidebar dan gagal karena `input.repair-produksi.index` belum render (500) — itu WAJAR di titik ini karena view belum ada; SidebarNavTest generic-loop test HARUS ditangani sama seperti Task 7 LHR Regular (exclude sementara dengan constant eksplisit + komentar "Task 8 wajib mencabut", lalu benar-benar dicabut di Task 8).

- [ ] **Step 9: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Http/Controllers/Input/RepairProduksiController.php routes/web.php config/sidebar.php \
        tests/Feature/Input/RepairProduksiBatchTest.php tests/Feature/AuthorizationTest.php
git commit -m "feat(repair-produksi): RepairProduksiController input+daftar+export, sidebar LHR Produksi"
```

---

### Task 7: View `input/repair-produksi/create.blade.php` (form batch)

**Files:**
- Create: `resources/views/input/repair-produksi/create.blade.php`
- Test: `tests/Feature/Input/HalamanBatchRepairProduksiTest.php`

**Interfaces:**
- Consumes: `batchDraft(cfg)`, `taField(items)`, `partPicker(items, partsData)` — global di layout, JANGAN diduplikasi.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_create_menampilkan_form_batch(): void
    {
        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);

        $response = $this->actingAs(User::factory()->create(['role' => 'staff_produksi']))
            ->get(route('input.repair-produksi.create'));

        $response->assertOk();
        $response->assertSee('formBaris', false);
        $response->assertSee('konfigRepairProduksi', false);
        $response->assertSee(route('input.repair-produksi.batch'), false);
    }

    public function test_admin_juga_bisa_buka_halaman_create(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('input.repair-produksi.create'));

        $response->assertOk();
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=HalamanBatchRepairProduksiTest`
Expected: FAIL — view belum ada.

- [ ] **Step 3: Buat view**

```blade
@extends('layouts.app')

@section('title', 'Input LHR Produksi')

@php
    $_taKaryawan = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $parts->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->prosesRepairProduksi->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
            'sph_standar_repair_produksi' => (float) $pr->sph_standar_repair_produksi,
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
<div class="erp-panel" x-data="batchDraft(konfigRepairProduksi())" x-init="mulai()">
    <div class="erp-panel-header">Input LHR Produksi (Laporan Harian Repair Produksi)</div>
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
                    <input type="date" id="f_tanggal" name="tanggal_repair_produksi" class="form-control"
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
                    <label for="f_proses">Proses Repair Produksi <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <select id="f_proses" name="id_part_proses_repair_produksi" class="form-select"
                                :disabled="prosesList.length === 0">
                            <option value="">-- Pilih Part dulu --</option>
                            <template x-for="p in prosesList" :key="p.id">
                                <option :value="p.id"
                                        x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_repair_produksi})`">
                                </option>
                            </template>
                        </select>
                        <small class="text-muted" x-show="prosesList.length === 0 && partNo" x-cloak>
                            Belum ada proses repair produksi untuk part ini, tambah di Master Data &raquo; Proses Repair Produksi dulu.
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
                            <td x-text="r.tanggal_repair_produksi"></td>
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

        <form method="POST" action="{{ route('input.repair-produksi.batch') }}" id="formBatch" class="mt-3"
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

function konfigRepairProduksi() {
    const picker = () => Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhr_repair_produksi_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhr_repair_produksi_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.repair-produksi.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tanggal_repair_produksi: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
            id_part_proses_repair_produksi: 'Proses Repair Produksi', jam_start: 'Jam Start',
            jam_finish: 'Jam Finish', qty_aktual: 'Qty Act Repair',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                tanggal_repair_produksi: diri.nilai('tanggal_repair_produksi'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan: diri.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == diri.nilai('id_karyawan')) || {}).t || '',
                id_part_proses_repair_produksi: diri.nilai('id_part_proses_repair_produksi'),
                proses_teks: diri.teksTerpilih('id_part_proses_repair_produksi'),
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
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses_repair_produksi);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_repair_produksi : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        resetField: ['jam_start', 'jam_finish', 'qty_aktual'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses_repair_produksi"]');
            if (proses) proses.value = '';
        },

        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tanggal_repair_produksi"]').value = row.tanggal_repair_produksi;
            f.querySelector('[name="id_shift"]').value = row.id_shift;
            f.querySelector('[name="jam_start"]').value = row.jam_start;
            f.querySelector('[name="jam_finish"]').value = row.jam_finish;
            f.querySelector('[name="qty_aktual"]').value = row.qty_aktual;

            const operator = Alpine.$data(document.getElementById('f_operator').closest('[x-data]'));
            operator.pick({ v: row.id_karyawan, t: row.karyawan_teks });

            const p = picker();
            const dataPart = partsData.find(dp => dp.proses.some(pr => pr.id == row.id_part_proses_repair_produksi));
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses_repair_produksi"]');
                    if (proses) proses.value = row.id_part_proses_repair_produksi;
                });
            } else {
                p.query = ''; p.val = null; p.partNo = ''; p.partName = ''; p.prosesList = [];
                const proses = f.querySelector('[name="id_part_proses_repair_produksi"]');
                if (proses) proses.value = '';
            }
        },

        payload(r) {
            return {
                tanggal_repair_produksi: r.tanggal_repair_produksi,
                id_shift: r.id_shift,
                id_karyawan: r.id_karyawan,
                id_part_proses_repair_produksi: r.id_part_proses_repair_produksi,
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

Run: `php artisan test --filter=HalamanBatchRepairProduksiTest`
Expected: PASS (2 test)

- [ ] **Step 5: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/repair-produksi/create.blade.php tests/Feature/Input/HalamanBatchRepairProduksiTest.php
git commit -m "feat(repair-produksi): halaman batch input LHR Produksi"
```

---

### Task 8: View `input/repair-produksi/index.blade.php` (daftar + filter + export) + cabut exclusion sementara

**Files:**
- Create: `resources/views/input/repair-produksi/index.blade.php`
- Create: `tests/Feature/Input/HalamanDaftarRepairProduksiTest.php`
- Modify: `tests/Feature/AuthorizationTest.php` (kalau Task 6 sempat nambah exclusion sementara — lihat catatan di Task 6 Step 8)
- Modify: `tests/Feature/Layout/SidebarNavTest.php` (idem, kalau ada exclusion sementara ditambahkan)

**Interfaces:**
- Consumes: `filterSearchComp`, `partGroupComp` (global layout), `<x-export-modal>`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanDaftarRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_daftar_menampilkan_baris_repair_produksi(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);

        TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair-produksi.index'));

        $response->assertOk();
        $response->assertSee('PART SATU');
        $response->assertSee('OPERATOR SATU');
        $response->assertSee(route('input.repair-produksi.create'), false);
    }

    public function test_staff_produksi_tetap_lihat_tombol_export_tapi_bukan_edit_hapus(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);
        $repair = TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair-produksi.index'));

        $response->assertSee('exportRepairProduksiPanel', false);
        $response->assertSee('Export Excel');
        $response->assertDontSee(route('input.repair-produksi.edit', $repair), false);
    }
}
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=HalamanDaftarRepairProduksiTest`
Expected: FAIL — view belum ada.

- [ ] **Step 3: Buat view**

```blade
@extends('layouts.app')
@section('title', 'LHR Repair Produksi')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@php
$_partsForFilter = $parts->map(fn ($p) => [
    'id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer,
    'proses' => $p->prosesRepairProduksi->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_allProsesForFilter = $parts->flatMap(fn ($p) => $p->prosesRepairProduksi)->unique('id')
    ->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_customersTA = $customers->map(fn ($c) => ['v' => $c, 't' => $c])->values();
$_partsTA     = $_partsForFilter->map(fn ($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']])->values();
$_prosesTA    = $_allProsesForFilter->map(fn ($pr) => ['v' => $pr['id'], 't' => $pr['label']])->values();
$_karyawanTA  = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();

$_karLabel    = $filterKarId ? ($karyawan->firstWhere('id', $filterKarId)?->nama_karyawan ?? '') : '';
$_partLabel   = $filterPartId ? ($parts->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$_prosesObjF  = $filterProsesId ? $parts->flatMap(fn ($p) => $p->prosesRepairProduksi)->firstWhere('id', $filterProsesId) : null;
$_prosesLabel = $_prosesObjF ? $_prosesObjF->id_proses.' – '.$_prosesObjF->nama_proses : '';
@endphp

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('input.repair-produksi.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Input Baru
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>
    @if(auth()->user()->hasRole(['admin', 'staff_repair', 'staff_produksi']))
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportRepairProduksiPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @endif
    <a href="{{ route('input.repair-produksi.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('input.repair-produksi.index') }}" class="erp-dock-body">

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
                           placeholder="Proses Repair Produksi" class="erp-in" :class="prosesVal ? 'is-set' : ''">
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
            <a href="{{ route('input.repair-produksi.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">

@if(auth()->user()->hasRole(['admin', 'staff_repair', 'staff_produksi']))
<x-export-modal
    panelId="exportRepairProduksiPanel"
    :exportUrl="route('input.repair-produksi.export')"
    title="Export LHR Repair Produksi"
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
                    $pp = $r->partProsesRepairProduksi;
                    $part = $pp?->part;
                    $sphStd = $pp?->sph_standar_repair_produksi ?? 0;
                    $sphAct = $r->sph_aktual;
                    $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal_repair_produksi)->format('d/m/Y') }}</td>
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
                            <a href="{{ route('input.repair-produksi.edit', $r) }}?back_query={{ urlencode(http_build_query(request()->query())) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.repair-produksi.destroy', $r) }}"
                                  onsubmit="return confirm('Hapus data ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ auth()->user()->hasRole('admin') ? 15 : 14 }}" style="text-align:center;color:#888;padding:20px">Belum ada data LHR Produksi.</td></tr>
            @endforelse
            </tbody>
        </table>
</div>

<div class="erp-pg">{{ $repairs->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($repairs->total()) }}</b> baris LHR Produksi</span>
    @if($filterActive)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>

<script>
@if (session('batch_tersimpan'))
(function() {
    try {
        localStorage.removeItem('optima_draft_lhr_repair_produksi_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_lhr_repair_produksi_terkirim_u{{ auth()->id() }}');
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
const _customersTA   = @json($_customersTA);
const _partsTA       = @json($_partsTA);
const _partsFullData = @json($_partsForFilter);
const _prosesTA      = @json($_prosesTA);
const _karyawanTA    = @json($_karyawanTA);
</script>
@endpush
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=HalamanDaftarRepairProduksiTest`
Expected: PASS (2 test)

- [ ] **Step 5: Cabut exclusion sementara (kalau ada dari Task 6 Step 8)**

Cek `tests/Feature/AuthorizationTest.php` dan `tests/Feature/Layout/SidebarNavTest.php` — kalau Task 6 sempat menambah constant/komentar penanda "Task 8 wajib mencabut" (karena generic sidebar-render loop gagal saat view belum ada), CABUT SEKARANG dan pastikan test generic itu jalan normal terhadap `input.repair-produksi.index` tanpa pengecualian. Kalau Task 6 tidak perlu menambah exclusion apa pun (karena grup `repair` sudah eksis sejak LHR Regular dan test generic ternyata tidak menyentuh route baru sebelum ini), lewati step ini — jangan menghapus sesuatu yang tidak ada.

- [ ] **Step 6: Jalankan `SidebarNavTest` + `AuthorizationTest` penuh, pastikan lulus**

Run: `php artisan test --filter=SidebarNavTest`
Run: `php artisan test --filter=AuthorizationTest`
Expected: PASS, tanpa exclusion tersisa.

- [ ] **Step 7: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/repair-produksi/index.blade.php tests/Feature/Input/HalamanDaftarRepairProduksiTest.php \
        tests/Feature/AuthorizationTest.php tests/Feature/Layout/SidebarNavTest.php
git commit -m "feat(repair-produksi): halaman daftar+filter+export LHR Produksi"
```

---

### Task 9: View `input/repair-produksi/edit.blade.php` + lengkapi assertion yang ditunda

**Files:**
- Create: `resources/views/input/repair-produksi/edit.blade.php`
- Modify: `tests/Feature/Input/RepairProduksiBatchTest.php` (tambah assertion GET edit admin yang ditunda dari Task 6)

**Interfaces:**
- Consumes: route `input.repair-produksi.update` (Task 6), `UpdateRepairProduksiRequest` (Task 5).

- [ ] **Step 1: Tambah test ke `RepairProduksiBatchTest.php`**

Tambahkan method baru:

```php
    public function test_admin_bisa_buka_dan_update_repair_produksi_lewat_form_edit(): void
    {
        $repair = TransaksiRepairProduksi::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('input.repair-produksi.edit', $repair))->assertOk();

        $response = $this->actingAs($admin)->put(route('input.repair-produksi.update', $repair), $this->baris([
            'qty_aktual' => 200,
        ]));

        $response->assertRedirect(route('input.repair-produksi.index'));
        $this->assertSame(200, $repair->fresh()->qty_aktual);
    }
```

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairProduksiBatchTest::test_admin_bisa_buka_dan_update_repair_produksi_lewat_form_edit`
Expected: FAIL — view `input.repair-produksi.edit` belum ada, GET meledak 500.

- [ ] **Step 3: Buat view**

```blade
@extends('layouts.app')
@section('title', 'Edit LHR Produksi')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit LHR Produksi</h1>
    <a href="{{ route('input.repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->updateRepairProduksi->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->updateRepairProduksi->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:640px">
    <div class="erp-panel-header">Form Edit LHR Produksi</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.repair-produksi.update', $repair) }}">
            @csrf @method('PUT')
            <input type="hidden" name="back_query" value="{{ request('back_query') }}">

            <div class="mb-3">
                <label class="form-label">Tanggal Repair <span class="text-danger">*</span></label>
                <input type="date" name="tanggal_repair_produksi" class="form-control @error('tanggal_repair_produksi', 'updateRepairProduksi') is-invalid @enderror"
                       value="{{ old('tanggal_repair_produksi', $repair->tanggal_repair_produksi->format('Y-m-d')) }}">
                @error('tanggal_repair_produksi', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Shift <span class="text-danger">*</span></label>
                <select name="id_shift" class="form-select @error('id_shift', 'updateRepairProduksi') is-invalid @enderror">
                    @foreach ($shifts as $s)
                        <option value="{{ $s->id }}" @selected(old('id_shift', $repair->id_shift) == $s->id)>{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
                @error('id_shift', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Operator <span class="text-danger">*</span></label>
                <select name="id_karyawan" class="form-select @error('id_karyawan', 'updateRepairProduksi') is-invalid @enderror">
                    @foreach ($karyawan as $k)
                        <option value="{{ $k->id }}" @selected(old('id_karyawan', $repair->id_karyawan) == $k->id)>{{ $k->nama_karyawan }}</option>
                    @endforeach
                </select>
                @error('id_karyawan', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Part &amp; Proses Repair Produksi <span class="text-danger">*</span></label>
                <select name="id_part_proses_repair_produksi" class="form-select @error('id_part_proses_repair_produksi', 'updateRepairProduksi') is-invalid @enderror">
                    @foreach ($parts as $p)
                        @foreach ($p->prosesRepairProduksi as $pr)
                            <option value="{{ $pr->id }}" @selected(old('id_part_proses_repair_produksi', $repair->id_part_proses_repair_produksi) == $pr->id)>
                                {{ $p->part_no }} — {{ $p->part_name }} — {{ $pr->id_proses }} {{ $pr->nama_proses }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                @error('id_part_proses_repair_produksi', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-2 mb-3">
                <div class="col">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start', 'updateRepairProduksi') is-invalid @enderror"
                           value="{{ old('jam_start', $repair->jam_start) }}">
                    @error('jam_start', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish', 'updateRepairProduksi') is-invalid @enderror"
                           value="{{ old('jam_finish', $repair->jam_finish) }}">
                    @error('jam_finish', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Qty Act Repair <span class="text-danger">*</span></label>
                <input type="number" name="qty_aktual" min="1" class="form-control @error('qty_aktual', 'updateRepairProduksi') is-invalid @enderror"
                       value="{{ old('qty_aktual', $repair->qty_aktual) }}">
                @error('qty_aktual', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=RepairProduksiBatchTest`
Expected: PASS (semua method di file ini)

- [ ] **Step 5: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/repair-produksi/edit.blade.php tests/Feature/Input/RepairProduksiBatchTest.php
git commit -m "feat(repair-produksi): halaman edit LHR Produksi (admin only)"
```

---

### Task 10: Chatbot `BusinessTableCatalog` + Verifikasi akhir

**Files:**
- Modify: `app/Services/Chatbot/BusinessTableCatalog.php`
- Create: `tests/Unit/Chatbot/RepairProduksiAwarenessTest.php`

**Interfaces:**
- Produces: `BusinessTableCatalog` mengenali `master_part_proses_repair_produksi` dan `transaksi_repair_produksi`.

- [ ] **Step 1: Tulis test yang gagal**

```php
<?php

namespace Tests\Unit\Chatbot;

use App\Services\Chatbot\BusinessTableCatalog;
use App\Services\Chatbot\SqlGuard;
use Tests\TestCase;

class RepairProduksiAwarenessTest extends TestCase
{
    public function test_katalog_memuat_kedua_tabel_repair_produksi(): void
    {
        $catalog = app(BusinessTableCatalog::class);

        $this->assertTrue($catalog->has('master_part_proses_repair_produksi'));
        $this->assertTrue($catalog->has('transaksi_repair_produksi'));
    }

    public function test_deskripsi_tabel_repair_produksi_menyebut_kolom_kuncinya(): void
    {
        $catalog = app(BusinessTableCatalog::class);

        $this->assertStringContainsString('sph_standar_repair_produksi', $catalog->describe('master_part_proses_repair_produksi'));
        $this->assertStringContainsString('qty_aktual', $catalog->describe('transaksi_repair_produksi'));
        $this->assertStringContainsString('actual_cycle_time', $catalog->describe('transaksi_repair_produksi'));
    }

    public function test_sqlguard_mengizinkan_query_ke_tabel_repair_produksi(): void
    {
        $guard = app(SqlGuard::class);

        $hasil = $guard->sanitize('SELECT id_karyawan, COUNT(*) FROM transaksi_repair_produksi GROUP BY id_karyawan');

        $this->assertArrayNotHasKey('error', $hasil);
        $this->assertArrayHasKey('sql', $hasil);
    }
}
```

Catatan: sesuaikan nama method `has()`/`describe()`/`sanitize()` dengan API sebenarnya `BusinessTableCatalog`/`SqlGuard` — baca dulu `app/Services/Chatbot/BusinessTableCatalog.php` dan `app/Services/Chatbot/SqlGuard.php` sebelum menulis test ini persis (kedua class ini sudah ada, dipakai LHR Regular sebagai cetakan di `tests/Unit/Chatbot/RepairAwarenessTest.php` — CONTEK test file itu untuk API yang benar, jangan menebak nama method).

- [ ] **Step 2: Jalankan, pastikan gagal**

Run: `php artisan test --filter=RepairProduksiAwarenessTest`
Expected: FAIL — dua tabel belum terdaftar di katalog.

- [ ] **Step 3: Tambah entri ke `BusinessTableCatalog`**

Edit `app/Services/Chatbot/BusinessTableCatalog.php`. Cari entri `master_part_proses_repair` dan `transaksi_repair` (ditambahkan modul LHR Regular) sebagai contoh persis format/gaya deskripsinya, lalu tambah dua entri baru di dekatnya:

- `master_part_proses_repair_produksi` — deskripsi mirror `master_part_proses_repair`, sebut kolom `id_part`, `id_proses`, `nama_proses`, `sph_standar_repair_produksi`. Jelaskan ini proses repair yang dikerjakan OPERATOR PRODUKSI (beda dari `master_part_proses_repair` yang dikerjakan tim repair).
- `transaksi_repair_produksi` — deskripsi mirror `transaksi_repair`, sebut kolom `tanggal_repair_produksi`, `id_shift`, `id_karyawan`, `id_part_proses_repair_produksi`, `jam_start`, `jam_finish`, `qty_aktual`, `actual_cycle_time`. Catat tidak ada konsep NG/qty_ng di sini.

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=RepairProduksiAwarenessTest`
Expected: PASS (3 test)

- [ ] **Step 5: `vendor/bin/pint app tests`, commit**

```bash
vendor/bin/pint app tests
git add app/Services/Chatbot/BusinessTableCatalog.php tests/Unit/Chatbot/RepairProduksiAwarenessTest.php
git commit -m "feat(repair-produksi): daftarkan tabel ke BusinessTableCatalog"
```

- [ ] **Step 6: Jalankan seluruh test suite**

Run: `php artisan test`
Expected: semua PASS, tidak ada regresi terhadap suite existing (termasuk LHR Regular).

- [ ] **Step 7: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: PASS — dua migrasi baru (Task 1 & 2) terdaftar di `deploy.yml`.

- [ ] **Step 8: Uji manual end-to-end di browser**

1. Login admin → Master Data → Proses Repair Produksi → tambah 1 part + proses.
2. Sidebar grup Repair sekarang tampil 2 sub-menu: "LHR Regular" dan "LHR Produksi".
3. Login sebagai `staff_produksi` → sidebar harus menampilkan "LHR Produksi" (BUKAN "LHR Regular", yang cuma untuk admin/staff_repair).
4. Login sebagai `staff_repair` → sidebar harus menampilkan KEDUA sub-menu.
5. Input LHR Produksi sebagai staff_produksi → isi 2 baris → submit → cek muncul di daftar.
6. Export Excel dari daftar LHR Produksi sebagai staff_produksi → berhasil (bukan admin-only).
7. Login admin → edit satu baris LHR Produksi, ubah jam/qty → cek `actual_cycle_time` di tabel berubah sesuai (bukan stale).
8. Buka AI Assistant, tanya soal data repair produksi → chatbot merespons dengan data, bukan "tidak ada data".

- [ ] **Step 9: Commit final kalau ada sisa perubahan format**

```bash
git status
git add -A
git commit -m "chore(repair-produksi): pint formatting" # kalau ada sisa
```
