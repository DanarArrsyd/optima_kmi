# Maintenance Mesin (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Maintenance Mesin module — breakdown logging plus MTTR/MTBF/Availability dashboards — reusing the existing `master_mesin` table.

**Architecture:** Standard OPTIMA Laravel patterns. One transaksi table (`transaksi_breakdown_mesin`) holds breakdown records with server-computed derived fields. Dashboards are computed on-the-fly by `MaintenanceDashboardService` (no precomputed dashboard tables), mirroring `DashboardService`. UI follows `input/produksi` (list + form) and the VCB pages (AJAX dashboards + Chart.js).

**Tech Stack:** Laravel 13, PHP 8.5, MySQL, Bootstrap 5.3, Alpine.js 3.14, Chart.js 4.4, PHPUnit 12 (sqlite in-memory, dashboard service only).

## Global Constraints

- Laravel 13 / PHP 8.5. MySQL prod DB (`optima_db`), sqlite in-memory for tests.
- SoftDeletes on all models.
- Pagination uses `Paginator::useBootstrapFive()` (already global in `AppServiceProvider`).
- `id_mesin` column on transaksi tables is an `unsignedBigInteger` FK → `master_mesin.id` (the business code is the *string* column `master_mesin.id_mesin`). Relations: `belongsTo(MasterMesin::class, 'id_mesin')`.
- Role middleware `CheckRole` is generic (`role:admin,staff_maintenance,manager`). Roles are plain strings on `users.role`; `User::hasRole()` exists.
- Derived fields (`no_lkm`, `repair_time`, `total_downtime`, `status_breakdown`) are ALWAYS computed server-side, never trusted from input.
- Defensive DB access: any read of `master_mesin.tanggal_instalasi` must tolerate the column being absent on the hosting DB (try-catch / Schema check), per project convention.
- No.LKM format: `{nomorUrut}/LKM/MTC/KMI/{romawiBulan}/{yy}` where romawiBulan is the breakdown month in Roman numerals (I..XII), yy is 2-digit year.
- Status breakdown thresholds (by repair_time hours): `<1` → `Ringan`, `1..<3` → `Sedang`, `>=3` → `Berat`.

---

### Task 1: DB foundation — migrations + models

**Files:**
- Create: `database/migrations/2026_06_26_100000_add_tanggal_instalasi_to_master_mesin.php`
- Create: `database/migrations/2026_06_26_100100_create_transaksi_breakdown_mesin_table.php`
- Create: `app/Models/TransaksiBreakdownMesin.php`
- Modify: `app/Models/MasterMesin.php`

**Interfaces:**
- Produces: table `transaksi_breakdown_mesin`; model `App\Models\TransaksiBreakdownMesin` with fillable fields and `mesin()` relation; `MasterMesin::$fillable` includes `tanggal_instalasi`; `MasterMesin::breakdownMesin()` hasMany.

- [ ] **Step 1: Write the master_mesin alter migration**

`database/migrations/2026_06_26_100000_add_tanggal_instalasi_to_master_mesin.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_mesin', function (Blueprint $table) {
            $table->date('tanggal_instalasi')->nullable()->after('kapasitas_max_stroke');
        });
    }

    public function down(): void
    {
        Schema::table('master_mesin', function (Blueprint $table) {
            $table->dropColumn('tanggal_instalasi');
        });
    }
};
```

- [ ] **Step 2: Write the breakdown table migration**

`database/migrations/2026_06_26_100100_create_transaksi_breakdown_mesin_table.php`:
```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_breakdown_mesin', function (Blueprint $table) {
            $table->id();
            $table->string('no_lkm')->unique();
            $table->unsignedBigInteger('id_mesin');
            $table->date('tanggal_breakdown');
            $table->time('jam_breakdown');
            $table->date('tanggal_mulai_perbaikan');
            $table->time('jam_mulai_perbaikan');
            $table->date('tanggal_selesai_perbaikan');
            $table->time('jam_selesai_perbaikan');
            $table->text('problem_mesin');
            $table->text('penyebab_breakdown');
            $table->text('tindakan_perbaikan');
            $table->string('status_breakdown'); // Ringan | Sedang | Berat
            $table->string('pic_maintenance');
            $table->decimal('repair_time', 8, 2)->default(0);    // hours
            $table->decimal('total_downtime', 8, 2)->default(0); // hours
            $table->softDeletes();
            $table->timestamps();

            $table->index('id_mesin');
            $table->index('tanggal_breakdown');
            $table->foreign('id_mesin')->references('id')->on('master_mesin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_breakdown_mesin');
    }
};
```

- [ ] **Step 3: Create the model**

`app/Models/TransaksiBreakdownMesin.php`:
```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiBreakdownMesin extends Model
{
    use SoftDeletes;

    protected $table = 'transaksi_breakdown_mesin';

    protected $fillable = [
        'no_lkm', 'id_mesin',
        'tanggal_breakdown', 'jam_breakdown',
        'tanggal_mulai_perbaikan', 'jam_mulai_perbaikan',
        'tanggal_selesai_perbaikan', 'jam_selesai_perbaikan',
        'problem_mesin', 'penyebab_breakdown', 'tindakan_perbaikan',
        'status_breakdown', 'pic_maintenance',
        'repair_time', 'total_downtime',
    ];

    protected $casts = [
        'tanggal_breakdown'         => 'date',
        'tanggal_mulai_perbaikan'   => 'date',
        'tanggal_selesai_perbaikan' => 'date',
        'repair_time'               => 'decimal:2',
        'total_downtime'            => 'decimal:2',
    ];

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }
}
```

- [ ] **Step 4: Update MasterMesin model**

In `app/Models/MasterMesin.php`, add `'tanggal_instalasi'` to `$fillable` and add the relation. Result:
```php
    protected $fillable = ['id_mesin', 'nama_mesin', 'line', 'tipe_mesin', 'kapasitas_max_stroke', 'tanggal_instalasi'];
```
Add method alongside the other relations:
```php
    public function breakdownMesin(): HasMany
    {
        return $this->hasMany(TransaksiBreakdownMesin::class, 'id_mesin');
    }
```

- [ ] **Step 5: Run migrations and verify in tinker**

Run:
```bash
php artisan migrate
php artisan tinker --execute="\App\Models\MasterMesin::first()->tanggal_instalasi; echo \Schema::hasTable('transaksi_breakdown_mesin') ? 'OK' : 'MISSING';"
```
Expected: prints `OK`, no errors.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_26_100000_add_tanggal_instalasi_to_master_mesin.php database/migrations/2026_06_26_100100_create_transaksi_breakdown_mesin_table.php app/Models/TransaksiBreakdownMesin.php app/Models/MasterMesin.php
git commit -m "feat(mtc): breakdown mesin table + tanggal_instalasi column + models"
```

---

### Task 2: Role staff_maintenance + routes skeleton + nav + redirect

**Files:**
- Create: `app/Http/Controllers/Maintenance/BreakdownMesinController.php` (stub index)
- Create: `app/Http/Controllers/Maintenance/MttrController.php` (stub)
- Create: `app/Http/Controllers/Maintenance/MtbfController.php` (stub)
- Create: `app/Http/Controllers/Maintenance/AvailabilityController.php` (stub)
- Modify: `routes/web.php`
- Modify: `resources/views/layouts/app.blade.php` (sidebar nav + dashboard redirect is in routes)
- Modify: wherever the role dropdown/validation lives (`app/Http/Controllers/Master/UserController.php` and its user form view)

**Interfaces:**
- Consumes: models from Task 1.
- Produces: route names `maintenance.breakdown.index/create/store/edit/update/destroy`, `maintenance.breakdown.last-no-lkm`, `maintenance.mttr`, `maintenance.mttr.data`, `maintenance.mtbf`, `maintenance.mtbf.data`, `maintenance.availability`, `maintenance.availability.data`. Controllers exist (stubs return a view name placeholder or `[]`).

- [ ] **Step 1: Create stub controllers**

`app/Http/Controllers/Maintenance/BreakdownMesinController.php`:
```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BreakdownMesinController extends Controller
{
    public function index(Request $request)
    {
        return view('maintenance.breakdown.index', ['breakdowns' => collect()]);
    }
}
```

`app/Http/Controllers/Maintenance/MttrController.php`:
```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MttrController extends Controller
{
    public function index() { return view('maintenance.mttr'); }
    public function data(Request $request) { return response()->json([]); }
}
```

`app/Http/Controllers/Maintenance/MtbfController.php` and `AvailabilityController.php`: identical shape to `MttrController`, returning `maintenance.mtbf` / `maintenance.availability`.

- [ ] **Step 2: Register routes**

In `routes/web.php`, add `use` imports at the top:
```php
use App\Http\Controllers\Maintenance\BreakdownMesinController;
use App\Http\Controllers\Maintenance\MttrController;
use App\Http\Controllers\Maintenance\MtbfController;
use App\Http\Controllers\Maintenance\AvailabilityController;
```
Inside the `Route::middleware('auth')->group(...)` body, add:
```php
    // --- MAINTENANCE MESIN (admin, staff_maintenance, manager) ---
    Route::middleware('role:admin,staff_maintenance,manager')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('breakdown/last-no-lkm', [BreakdownMesinController::class, 'lastNoLkm'])->name('breakdown.last-no-lkm');
        Route::resource('breakdown', BreakdownMesinController::class)->only(['index', 'create', 'store']);
        Route::get('mttr',          [MttrController::class, 'index'])->name('mttr');
        Route::get('mttr/data',     [MttrController::class, 'data'])->name('mttr.data');
        Route::get('mtbf',          [MtbfController::class, 'index'])->name('mtbf');
        Route::get('mtbf/data',     [MtbfController::class, 'data'])->name('mtbf.data');
        Route::get('availability',      [AvailabilityController::class, 'index'])->name('availability');
        Route::get('availability/data', [AvailabilityController::class, 'data'])->name('availability.data');
    });

    // --- MAINTENANCE edit/hapus (admin only) ---
    Route::middleware('role:admin')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('breakdown/{breakdown}/edit', [BreakdownMesinController::class, 'edit'])->name('breakdown.edit');
        Route::put('breakdown/{breakdown}',      [BreakdownMesinController::class, 'update'])->name('breakdown.update');
        Route::delete('breakdown/{breakdown}',   [BreakdownMesinController::class, 'destroy'])->name('breakdown.destroy');
    });
```
Note: the `breakdown/last-no-lkm` line MUST come before `Route::resource('breakdown', ...)` to avoid `{breakdown}` model-binding swallowing `last-no-lkm` (project convention — see existing import routes ordering).

- [ ] **Step 3: Add staff_maintenance to the dashboard redirect**

In `routes/web.php`, in the `/dashboard` closure `match(true)`, add a branch so maintenance staff land on MTTR:
```php
        return redirect(match(true) {
            $role === 'staff_maintenance' => '/maintenance/mttr',
            in_array($role, ['admin', 'staff_produksi', 'manager']) => '/vcb/produksi',
            $role === 'staff_qc' => '/vcb/quality',
            default => '/vcb/produksi',
        });
```

- [ ] **Step 4: Add staff_maintenance to the user role options**

Find the role list. Run:
```bash
grep -rn "staff_produksi" app/Http/Controllers/Master/UserController.php resources/views/master/users app/Http/Requests | head
```
Add `'staff_maintenance'` everywhere the other staff roles are enumerated (validation `in:` rule and the role `<select>` options in the user form view). Add the option as:
```html
<option value="staff_maintenance">Staff Maintenance</option>
```
and in the validation rule, extend the allowed list, e.g. `'role' => 'required|in:admin,staff_produksi,staff_qc,manager,staff_maintenance'`.

- [ ] **Step 5: Add the sidebar nav group**

In `resources/views/layouts/app.blade.php`, locate the sidebar nav (search for an existing group such as the VCB or Master links). Add a new group, visible to the three roles, following the existing markup pattern:
```blade
@if(in_array(auth()->user()->role, ['admin','staff_maintenance','manager']))
<div class="nav-section">
    <div class="nav-section-title">Maintenance</div>
    <a href="{{ route('maintenance.breakdown.index') }}" class="nav-link {{ request()->routeIs('maintenance.breakdown.*') ? 'active' : '' }}">
        <i class="bi bi-wrench-adjustable"></i> Breakdown Mesin
    </a>
    <a href="{{ route('maintenance.mttr') }}" class="nav-link {{ request()->routeIs('maintenance.mttr') ? 'active' : '' }}">
        <i class="bi bi-clock-history"></i> MTTR
    </a>
    <a href="{{ route('maintenance.mtbf') }}" class="nav-link {{ request()->routeIs('maintenance.mtbf') ? 'active' : '' }}">
        <i class="bi bi-arrow-repeat"></i> MTBF
    </a>
    <a href="{{ route('maintenance.availability') }}" class="nav-link {{ request()->routeIs('maintenance.availability') ? 'active' : '' }}">
        <i class="bi bi-graph-up"></i> Availability
    </a>
</div>
@endif
```
Match the exact class names used by the neighbouring nav groups (copy their wrapper/link classes; the snippet above uses `nav-section`/`nav-link` as placeholders — replace with the real ones found in the file).

- [ ] **Step 6: Create placeholder views so routes don't 500**

Create minimal blades extending the layout so each route renders:
- `resources/views/maintenance/breakdown/index.blade.php`
- `resources/views/maintenance/mttr.blade.php`
- `resources/views/maintenance/mtbf.blade.php`
- `resources/views/maintenance/availability.blade.php`

Each, minimally:
```blade
@extends('layouts.app')
@section('content')<div class="erp-panel"><div class="erp-panel-header">Maintenance</div><div class="erp-panel-body">Coming soon</div></div>@endsection
```

- [ ] **Step 7: Verify routing + gate manually**

Run:
```bash
php artisan route:list --path=maintenance
```
Expected: all `maintenance.*` routes listed.
Then in the browser: log in as admin → open `/maintenance/mttr` (renders). Create or update a user to role `staff_maintenance`, log in as them → `/maintenance/breakdown` renders, `/vcb/quality` returns 403.

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/Maintenance resources/views/maintenance resources/views/layouts/app.blade.php app/Http/Controllers/Master/UserController.php resources/views/master/users
git commit -m "feat(mtc): staff_maintenance role, routes, sidebar nav, placeholder views"
```

---

### Task 3: Breakdown create + store + list (with No.LKM + derived fields)

**Files:**
- Create: `app/Services/BreakdownMesinService.php`
- Create: `app/Http/Requests/StoreBreakdownMesinRequest.php`
- Modify: `app/Http/Controllers/Maintenance/BreakdownMesinController.php`
- Create/replace: `resources/views/maintenance/breakdown/index.blade.php`
- Create: `resources/views/maintenance/breakdown/create.blade.php`

**Interfaces:**
- Consumes: `TransaksiBreakdownMesin`, `MasterMesin` (Task 1).
- Produces:
  - `BreakdownMesinService::toRoman(int $month): string`
  - `BreakdownMesinService::generateNoLkm(int $nomorUrut, string $tanggalBreakdown): string`
  - `BreakdownMesinService::nextNomorUrut(): int`
  - `BreakdownMesinService::hoursBetween(string $startDate, string $startTime, string $endDate, string $endTime): float`
  - `BreakdownMesinService::statusFromRepairHours(float $hours): string`
  - `BreakdownMesinService::computeDerived(array $input): array` → returns `['repair_time'=>float,'total_downtime'=>float,'status_breakdown'=>string]`

- [ ] **Step 1: Create the service**

`app/Services/BreakdownMesinService.php`:
```php
<?php
namespace App\Services;

use App\Models\TransaksiBreakdownMesin;
use Illuminate\Support\Carbon;

class BreakdownMesinService
{
    private const ROMAN = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];

    public function toRoman(int $month): string
    {
        return self::ROMAN[$month] ?? 'I';
    }

    public function generateNoLkm(int $nomorUrut, string $tanggalBreakdown): string
    {
        $date = Carbon::parse($tanggalBreakdown);
        $roman = $this->toRoman((int) $date->month);
        $yy = $date->format('y');
        return "{$nomorUrut}/LKM/MTC/KMI/{$roman}/{$yy}";
    }

    public function nextNomorUrut(): int
    {
        // Highest leading number across existing no_lkm values, +1.
        $max = TransaksiBreakdownMesin::withTrashed()->get('no_lkm')
            ->map(fn ($r) => (int) explode('/', (string) $r->no_lkm)[0])
            ->max();
        return ((int) $max) + 1;
    }

    public function hoursBetween(string $startDate, string $startTime, string $endDate, string $endTime): float
    {
        try {
            $start = Carbon::parse("{$startDate} {$startTime}");
            $end   = Carbon::parse("{$endDate} {$endTime}");
            $hours = $start->floatDiffInRealHours($end, false);
            return max(0.0, round($hours, 2));
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    public function statusFromRepairHours(float $hours): string
    {
        if ($hours < 1) return 'Ringan';
        if ($hours < 3) return 'Sedang';
        return 'Berat';
    }

    public function computeDerived(array $in): array
    {
        $repair = $this->hoursBetween(
            $in['tanggal_mulai_perbaikan'], $in['jam_mulai_perbaikan'],
            $in['tanggal_selesai_perbaikan'], $in['jam_selesai_perbaikan']
        );
        $downtime = $this->hoursBetween(
            $in['tanggal_breakdown'], $in['jam_breakdown'],
            $in['tanggal_selesai_perbaikan'], $in['jam_selesai_perbaikan']
        );
        return [
            'repair_time'      => $repair,
            'total_downtime'   => $downtime,
            'status_breakdown' => $this->statusFromRepairHours($repair),
        ];
    }
}
```

- [ ] **Step 2: Create the form request**

`app/Http/Requests/StoreBreakdownMesinRequest.php`:
```php
<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBreakdownMesinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return in_array($this->user()?->role, ['admin', 'staff_maintenance', 'manager'], true);
    }

    public function rules(): array
    {
        return [
            'nomor_urut'                 => ['required', 'integer', 'min:1'],
            'id_mesin'                   => ['required', 'exists:master_mesin,id'],
            'tanggal_breakdown'          => ['required', 'date'],
            'jam_breakdown'              => ['required'],
            'tanggal_mulai_perbaikan'    => ['required', 'date', 'after_or_equal:tanggal_breakdown'],
            'jam_mulai_perbaikan'        => ['required'],
            'tanggal_selesai_perbaikan'  => ['required', 'date', 'after_or_equal:tanggal_mulai_perbaikan'],
            'jam_selesai_perbaikan'      => ['required'],
            'problem_mesin'              => ['required', 'string'],
            'penyebab_breakdown'         => ['required', 'string'],
            'tindakan_perbaikan'         => ['required', 'string'],
            'pic_maintenance'            => ['required', 'string', 'max:255'],
        ];
    }
}
```

- [ ] **Step 3: Implement controller index/create/store/lastNoLkm**

Replace `app/Http/Controllers/Maintenance/BreakdownMesinController.php`:
```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakdownMesinRequest;
use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Services\BreakdownMesinService;
use Illuminate\Http\Request;

class BreakdownMesinController extends Controller
{
    public function __construct(private BreakdownMesinService $svc) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));
        $mesinId  = (int) $request->input('mesin_id', 0);

        $breakdowns = TransaksiBreakdownMesin::with('mesin')
            ->whereBetween('tanggal_breakdown', [$dateFrom, $dateTo])
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('maintenance.breakdown.index', [
            'breakdowns' => $breakdowns,
            'mesinList'  => MasterMesin::orderBy('id_mesin')->get(),
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'mesinId'    => $mesinId,
        ]);
    }

    public function create()
    {
        return view('maintenance.breakdown.create', [
            'mesinList'  => MasterMesin::orderBy('id_mesin')->get(),
            'nextNomor'  => $this->svc->nextNomorUrut(),
        ]);
    }

    public function lastNoLkm()
    {
        return response()->json(['next_nomor' => $this->svc->nextNomorUrut()]);
    }

    public function store(StoreBreakdownMesinRequest $request)
    {
        $data = $request->validated();
        $noLkm = $this->svc->generateNoLkm((int) $data['nomor_urut'], $data['tanggal_breakdown']);

        if (TransaksiBreakdownMesin::where('no_lkm', $noLkm)->exists()) {
            return back()->withInput()->withErrors(['nomor_urut' => "No LKM {$noLkm} sudah ada, gunakan nomor lain."]);
        }

        $derived = $this->svc->computeDerived($data);
        TransaksiBreakdownMesin::create([
            'no_lkm'                    => $noLkm,
            'id_mesin'                  => $data['id_mesin'],
            'tanggal_breakdown'         => $data['tanggal_breakdown'],
            'jam_breakdown'             => $data['jam_breakdown'],
            'tanggal_mulai_perbaikan'   => $data['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan'       => $data['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $data['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan'     => $data['jam_selesai_perbaikan'],
            'problem_mesin'             => $data['problem_mesin'],
            'penyebab_breakdown'        => $data['penyebab_breakdown'],
            'tindakan_perbaikan'        => $data['tindakan_perbaikan'],
            'pic_maintenance'           => $data['pic_maintenance'],
            ...$derived,
        ]);

        return redirect()->route('maintenance.breakdown.index')->with('success', "Breakdown {$noLkm} tersimpan.");
    }
}
```

- [ ] **Step 4: Build the create form view**

`resources/views/maintenance/breakdown/create.blade.php` — a standard Bootstrap form posting to `maintenance.breakdown.store`. Mirror the markup conventions of `resources/views/input/produksi/create.blade.php` (panel wrapper, `@error` display, `old()` values). Fields: `nomor_urut` (number, value `{{ old('nomor_urut', $nextNomor) }}`, with a small note showing the generated No.LKM preview), `id_mesin` (`<select>` from `$mesinList`, option label `"{$m->id_mesin} - {$m->nama_mesin}"`, value `$m->id`), the four date+time pairs, the three textareas, and `pic_maintenance`. Submit + cancel buttons. Use `@csrf`.

```blade
@extends('layouts.app')
@section('content')
<div class="erp-panel">
  <div class="erp-panel-header">Input Breakdown Mesin</div>
  <div class="erp-panel-body">
    <form method="POST" action="{{ route('maintenance.breakdown.store') }}">
      @csrf
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Nomor Urut LKM</label>
          <input type="number" name="nomor_urut" min="1" value="{{ old('nomor_urut', $nextNomor) }}" class="form-control @error('nomor_urut') is-invalid @enderror">
          @error('nomor_urut')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-5">
          <label class="form-label">Mesin</label>
          <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror">
            <option value="">— pilih —</option>
            @foreach($mesinList as $m)
              <option value="{{ $m->id }}" @selected(old('id_mesin')==$m->id)>{{ $m->id_mesin }} - {{ $m->nama_mesin }}</option>
            @endforeach
          </select>
          @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        @php $pairs = [
          ['tanggal_breakdown','jam_breakdown','Breakdown'],
          ['tanggal_mulai_perbaikan','jam_mulai_perbaikan','Mulai Perbaikan'],
          ['tanggal_selesai_perbaikan','jam_selesai_perbaikan','Selesai Perbaikan'],
        ]; @endphp
        @foreach($pairs as [$dCol,$tCol,$label])
        <div class="col-md-3">
          <label class="form-label">Tanggal {{ $label }}</label>
          <input type="date" name="{{ $dCol }}" value="{{ old($dCol) }}" class="form-control @error($dCol) is-invalid @enderror">
          @error($dCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        <div class="col-md-3">
          <label class="form-label">Jam {{ $label }}</label>
          <input type="time" name="{{ $tCol }}" value="{{ old($tCol) }}" class="form-control @error($tCol) is-invalid @enderror">
          @error($tCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @endforeach

        @foreach(['problem_mesin'=>'Problem Mesin','penyebab_breakdown'=>'Penyebab Breakdown','tindakan_perbaikan'=>'Tindakan Perbaikan'] as $col=>$label)
        <div class="col-md-4">
          <label class="form-label">{{ $label }}</label>
          <textarea name="{{ $col }}" rows="2" class="form-control @error($col) is-invalid @enderror">{{ old($col) }}</textarea>
          @error($col)<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
        @endforeach

        <div class="col-md-4">
          <label class="form-label">PIC Maintenance</label>
          <input type="text" name="pic_maintenance" value="{{ old('pic_maintenance') }}" class="form-control @error('pic_maintenance') is-invalid @enderror">
          @error('pic_maintenance')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
      </div>
      <div class="mt-3">
        <button class="btn btn-primary" type="submit">Simpan</button>
        <a href="{{ route('maintenance.breakdown.index') }}" class="btn btn-secondary">Batal</a>
      </div>
    </form>
  </div>
</div>
@endsection
```

- [ ] **Step 5: Build the list view (sticky-freeze)**

Replace `resources/views/maintenance/breakdown/index.blade.php`. Header bar with a "Input Breakdown" button (`route('maintenance.breakdown.create')`), a date-range + mesin filter form (GET), a success flash, then an `erp-table tbl-freeze` inside `<div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 260px);min-height:200px">`. Freeze columns Tanggal → Mesin using the shared `.tbl-freeze` classes (`sc sc-1` … `sc sc-5 sc-sep`) exactly as in `resources/views/admin/anomali/index.blade.php`. Columns: Tanggal (sc-1), No.LKM (sc-2), Mesin code (sc-3), Nama Mesin (sc-4), Status (sc-5 sc-sep), then non-frozen: Jam Breakdown, Repair (j), Downtime (j), PIC, Problem, Aksi (Edit/Hapus, admin only). Render `$breakdowns->links()` below. The sticky offset script is already global in the layout — no per-page JS needed.

```blade
@extends('layouts.app')
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="erp-panel">
  <div class="erp-panel-header d-flex justify-content-between align-items-center">
    <span>Daftar Breakdown Mesin</span>
    <a href="{{ route('maintenance.breakdown.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Input Breakdown</a>
  </div>
  <form method="GET" class="d-flex gap-2 p-2 flex-wrap">
    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm" style="width:160px">
    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm" style="width:160px">
    <select name="mesin_id" class="form-select form-select-sm" style="width:200px">
      <option value="0">Semua Mesin</option>
      @foreach($mesinList as $m)<option value="{{ $m->id }}" @selected($mesinId==$m->id)>{{ $m->id_mesin }} - {{ $m->nama_mesin }}</option>@endforeach
    </select>
    <button class="btn btn-sm btn-secondary">Filter</button>
  </form>
  <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 260px);min-height:200px">
    <table class="erp-table tbl-freeze" style="min-width:1300px">
      <thead style="position:sticky;top:0;z-index:10">
        <tr>
          <th class="sc sc-1" style="width:90px">Tanggal</th>
          <th class="sc sc-2" style="width:150px">No. LKM</th>
          <th class="sc sc-3" style="width:70px">Mesin</th>
          <th class="sc sc-4" style="width:140px">Nama Mesin</th>
          <th class="sc sc-5 sc-sep" style="width:80px">Status</th>
          <th style="width:90px">Jam BD</th>
          <th style="width:80px;text-align:right">Repair (j)</th>
          <th style="width:90px;text-align:right">Downtime (j)</th>
          <th style="width:120px">PIC</th>
          <th>Problem</th>
          @if(auth()->user()->role==='admin')<th style="width:120px">Aksi</th>@endif
        </tr>
      </thead>
      <tbody>
      @forelse($breakdowns as $b)
        <tr>
          <td class="sc sc-1">{{ \Carbon\Carbon::parse($b->tanggal_breakdown)->format('d/m/Y') }}</td>
          <td class="sc sc-2" style="white-space:nowrap">{{ $b->no_lkm }}</td>
          <td class="sc sc-3">{{ $b->mesin?->id_mesin ?? '—' }}</td>
          <td class="sc sc-4" style="white-space:nowrap">{{ $b->mesin?->nama_mesin ?? '—' }}</td>
          <td class="sc sc-5 sc-sep">{{ $b->status_breakdown }}</td>
          <td>{{ \Illuminate\Support\Str::of($b->jam_breakdown)->substr(0,5) }}</td>
          <td style="text-align:right">{{ number_format($b->repair_time,1) }}</td>
          <td style="text-align:right">{{ number_format($b->total_downtime,1) }}</td>
          <td>{{ $b->pic_maintenance }}</td>
          <td style="font-size:0.82rem">{{ \Illuminate\Support\Str::limit($b->problem_mesin,60) }}</td>
          @if(auth()->user()->role==='admin')
          <td style="white-space:nowrap">
            <a href="{{ route('maintenance.breakdown.edit',$b) }}" class="btn btn-warning btn-sm">Edit</a>
            <form method="POST" action="{{ route('maintenance.breakdown.destroy',$b) }}" class="d-inline" onsubmit="return confirm('Hapus breakdown ini?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Hapus</button></form>
          </td>
          @endif
        </tr>
      @empty
        <tr><td colspan="11" style="text-align:center;color:#9ca3af">Belum ada data breakdown.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="p-2">{{ $breakdowns->links() }}</div>
</div>
@endsection
```

- [ ] **Step 6: Verify create + list manually**

Run `php artisan serve` (or use XAMPP). As `staff_maintenance`: open `/maintenance/breakdown/create`, submit a record (breakdown 08:00, mulai 09:00, selesai 11:00 same day). Expected: redirect to list, flash success, row shows `repair_time` 2.0, `total_downtime` 3.0, `status_breakdown` Sedang, `no_lkm` like `1/LKM/MTC/KMI/<roman>/<yy>`. Submit the same nomor_urut + same month again → validation error "No LKM ... sudah ada". Confirm the list freeze columns stay put on horizontal scroll.

- [ ] **Step 7: Commit**

```bash
git add app/Services/BreakdownMesinService.php app/Http/Requests/StoreBreakdownMesinRequest.php app/Http/Controllers/Maintenance/BreakdownMesinController.php resources/views/maintenance/breakdown
git commit -m "feat(mtc): breakdown create/store/list with No.LKM + derived fields"
```

---

### Task 4: Breakdown edit / update / destroy (admin)

**Files:**
- Modify: `app/Http/Controllers/Maintenance/BreakdownMesinController.php`
- Create: `app/Http/Requests/UpdateBreakdownMesinRequest.php`
- Create: `resources/views/maintenance/breakdown/edit.blade.php`

**Interfaces:**
- Consumes: `BreakdownMesinService`, `TransaksiBreakdownMesin`.
- Produces: working `edit/update/destroy` actions.

- [ ] **Step 1: Create the update request**

`app/Http/Requests/UpdateBreakdownMesinRequest.php` — identical rules to `StoreBreakdownMesinRequest` but `authorize()` returns `$this->user()?->role === 'admin'`. Copy the full `rules()` array from Task 3 Step 2 verbatim (same fields, same constraints).

- [ ] **Step 2: Add edit/update/destroy to controller**

Append these methods to `BreakdownMesinController` (add `use App\Http\Requests\UpdateBreakdownMesinRequest;`):
```php
    public function edit(TransaksiBreakdownMesin $breakdown)
    {
        return view('maintenance.breakdown.edit', [
            'b'         => $breakdown,
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
        ]);
    }

    public function update(UpdateBreakdownMesinRequest $request, TransaksiBreakdownMesin $breakdown)
    {
        $data  = $request->validated();
        $noLkm = $this->svc->generateNoLkm((int) $data['nomor_urut'], $data['tanggal_breakdown']);

        $dupe = TransaksiBreakdownMesin::where('no_lkm', $noLkm)->where('id', '!=', $breakdown->id)->exists();
        if ($dupe) {
            return back()->withInput()->withErrors(['nomor_urut' => "No LKM {$noLkm} sudah ada, gunakan nomor lain."]);
        }

        $derived = $this->svc->computeDerived($data);
        $breakdown->update([
            'no_lkm'                    => $noLkm,
            'id_mesin'                  => $data['id_mesin'],
            'tanggal_breakdown'         => $data['tanggal_breakdown'],
            'jam_breakdown'             => $data['jam_breakdown'],
            'tanggal_mulai_perbaikan'   => $data['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan'       => $data['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $data['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan'     => $data['jam_selesai_perbaikan'],
            'problem_mesin'             => $data['problem_mesin'],
            'penyebab_breakdown'        => $data['penyebab_breakdown'],
            'tindakan_perbaikan'        => $data['tindakan_perbaikan'],
            'pic_maintenance'           => $data['pic_maintenance'],
            ...$derived,
        ]);

        return redirect()->route('maintenance.breakdown.index')->with('success', "Breakdown {$noLkm} diperbarui.");
    }

    public function destroy(TransaksiBreakdownMesin $breakdown)
    {
        $breakdown->delete();
        return redirect()->route('maintenance.breakdown.index')->with('success', 'Breakdown dihapus.');
    }
```

- [ ] **Step 3: Build the edit view**

`resources/views/maintenance/breakdown/edit.blade.php` — copy `create.blade.php`, change the form `action` to `route('maintenance.breakdown.update', $b)` and add `@method('PUT')` after `@csrf`. Prefill every field from `$b` using `old('field', $b->field)`. For `nomor_urut`, derive from the stored `no_lkm`: `old('nomor_urut', (int) explode('/', $b->no_lkm)[0])`. Time inputs use `substr($b->jam_x,0,5)`. Header text "Edit Breakdown Mesin". Submit label "Update".

- [ ] **Step 4: Verify edit/update/destroy manually**

As admin: edit the record from Task 3, change selesai time to 09:30 → after save, repair_time 0.5, status Ringan. Confirm a non-admin (staff_maintenance) gets 403 on `/maintenance/breakdown/{id}/edit`. Delete the record → it disappears from the list (soft-deleted).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Requests/UpdateBreakdownMesinRequest.php app/Http/Controllers/Maintenance/BreakdownMesinController.php resources/views/maintenance/breakdown/edit.blade.php
git commit -m "feat(mtc): breakdown edit/update/destroy (admin)"
```

---

### Task 5: MaintenanceDashboardService + tests (formula)

**Files:**
- Create: `app/Services/MaintenanceDashboardService.php`
- Create: `phpunit.xml`
- Create: `tests/TestCase.php`
- Create: `tests/CreatesApplication.php`
- Create: `tests/Unit/MaintenanceDashboardServiceTest.php`

**Interfaces:**
- Consumes: `TransaksiBreakdownMesin`, `MasterMesin`.
- Produces: `MaintenanceDashboardService::compute(Carbon $from, Carbon $to, ?int $mesinId = null): array`. Returns a list of per-mesin rows:
  `['id_mesin'=>int,'kode_mesin'=>string,'nama_mesin'=>string,'count'=>int,'sum_repair'=>float,'sum_downtime'=>float,'mttr_jam'=>float,'mtbf_jam'=>?float,'mtbf_hari'=>?float,'availability_pct'=>?float,'instalasi_set'=>bool]`. When `tanggal_instalasi` is null, `mtbf_*` and `availability_pct` are `null` and `instalasi_set` is `false`.

- [ ] **Step 1: Create the service**

`app/Services/MaintenanceDashboardService.php`:
```php
<?php
namespace App\Services;

use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class MaintenanceDashboardService
{
    public function compute(Carbon $from, Carbon $to, ?int $mesinId = null): array
    {
        $hasInstalasi = Schema::hasColumn('master_mesin', 'tanggal_instalasi');

        $mesins = MasterMesin::when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->orderBy('id_mesin')->get();

        $stats = TransaksiBreakdownMesin::query()
            ->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()])
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->get(['id_mesin', 'repair_time', 'total_downtime'])
            ->groupBy('id_mesin');

        $rows = [];
        foreach ($mesins as $m) {
            $group        = $stats->get($m->id) ?? collect();
            $count        = $group->count();
            $sumRepair    = round((float) $group->sum('repair_time'), 2);
            $sumDowntime  = round((float) $group->sum('total_downtime'), 2);
            $mttr         = $count > 0 ? round($sumRepair / $count, 2) : 0.0;

            $instalasi    = $hasInstalasi ? $m->tanggal_instalasi : null;
            $mtbfJam = $mtbfHari = $availPct = null;
            $instalasiSet = false;

            if ($instalasi) {
                $instalasiSet  = true;
                $windowStart   = Carbon::parse($instalasi)->max($from->copy()->startOfDay());
                $operatingHours = max(0.0, $windowStart->floatDiffInRealHours($to->copy()->endOfDay(), false));
                $uptime         = max(0.0, $operatingHours - $sumDowntime);
                $mtbfJam        = $count > 0 ? round($uptime / $count, 2) : round($uptime, 2);
                $mtbfHari       = round($mtbfJam / 24, 2);
                $availPct       = $operatingHours > 0 ? round($uptime / $operatingHours * 100, 2) : 100.0;
            }

            $rows[] = [
                'id_mesin'         => $m->id,
                'kode_mesin'       => $m->id_mesin,
                'nama_mesin'       => $m->nama_mesin,
                'count'            => $count,
                'sum_repair'       => $sumRepair,
                'sum_downtime'     => $sumDowntime,
                'mttr_jam'         => $mttr,
                'mtbf_jam'         => $mtbfJam,
                'mtbf_hari'        => $mtbfHari,
                'availability_pct' => $availPct,
                'instalasi_set'    => $instalasiSet,
            ];
        }

        return $rows;
    }
}
```
Note: `Carbon::max()` returns the later of two instances, so `$windowStart` never precedes installation.

- [ ] **Step 2: Scaffold phpunit (sqlite in-memory)**

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="Unit"><directory>tests/Unit</directory></testsuite>
        <testsuite name="Feature"><directory>tests/Feature</directory></testsuite>
    </testsuites>
    <source><include><directory>app</directory></include></source>
    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="sqlite"/>
        <env name="DB_DATABASE" value=":memory:"/>
        <env name="BCRYPT_ROUNDS" value="4"/>
        <env name="CACHE_STORE" value="array"/>
        <env name="SESSION_DRIVER" value="array"/>
        <env name="QUEUE_CONNECTION" value="sync"/>
    </php>
</phpunit>
```

`tests/CreatesApplication.php`:
```php
<?php
namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }
}
```

`tests/TestCase.php`:
```php
<?php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
```

- [ ] **Step 3: Write the failing test**

`tests/Unit/MaintenanceDashboardServiceTest.php`:
```php
<?php
namespace Tests\Unit;

use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mesin(?string $instalasi): MasterMesin
    {
        return MasterMesin::create([
            'id_mesin' => 'M'.uniqid(), 'nama_mesin' => 'Test', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $instalasi,
        ]);
    }

    public function test_mttr_is_average_repair_time_over_breakdown_count(): void
    {
        $m = $this->mesin('2026-01-01');
        foreach ([['2026-06-01', 2.0, 3.0], ['2026-06-10', 4.0, 5.0]] as [$tgl, $repair, $down]) {
            TransaksiBreakdownMesin::create([
                'no_lkm' => uniqid(), 'id_mesin' => $m->id,
                'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
                'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Sedang', 'pic_maintenance' => 'PIC',
                'repair_time' => $repair, 'total_downtime' => $down,
            ]);
        }

        $rows = (new MaintenanceDashboardService())->compute(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertEqualsWithDelta(3.0, $rows[0]['mttr_jam'], 0.01); // (2+4)/2
        $this->assertEqualsWithDelta(8.0, $rows[0]['sum_downtime'], 0.01);
        $this->assertTrue($rows[0]['instalasi_set']);
        $this->assertGreaterThan(0, $rows[0]['availability_pct']);
        $this->assertLessThanOrEqual(100, $rows[0]['availability_pct']);
    }

    public function test_null_instalasi_marks_row_and_nulls_mtbf_availability(): void
    {
        $m = $this->mesin(null);
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-06-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'PIC',
            'repair_time' => 1.0, 'total_downtime' => 2.0,
        ]);

        $rows = (new MaintenanceDashboardService())->compute(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertFalse($rows[0]['instalasi_set']);
        $this->assertNull($rows[0]['mtbf_jam']);
        $this->assertNull($rows[0]['availability_pct']);
        $this->assertSame(1.0, $rows[0]['mttr_jam']);
    }

    public function test_date_window_excludes_breakdowns_outside_range(): void
    {
        $m = $this->mesin('2026-01-01');
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-03-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-03-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-03-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'PIC',
            'repair_time' => 1.0, 'total_downtime' => 2.0,
        ]);

        $rows = (new MaintenanceDashboardService())->compute(
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')
        );

        $this->assertSame(0, $rows[0]['count']);
        $this->assertSame(0.0, $rows[0]['mttr_jam']);
    }
}
```

- [ ] **Step 4: Run the tests, expect FAIL first if service path wrong, then PASS**

Run:
```bash
php artisan test --testsuite=Unit
```
Expected: 3 passing tests. If `RefreshDatabase` errors on missing migrations, ensure both Task 1 migrations are present (they run automatically against sqlite). Fix any column mismatch, re-run until green.

- [ ] **Step 5: Commit**

```bash
git add app/Services/MaintenanceDashboardService.php phpunit.xml tests/CreatesApplication.php tests/TestCase.php tests/Unit/MaintenanceDashboardServiceTest.php
git commit -m "feat(mtc): MaintenanceDashboardService with MTTR/MTBF/Availability + unit tests"
```

---

### Task 6: Dashboard controllers + views (MTTR / MTBF / Availability)

**Files:**
- Modify: `app/Http/Controllers/Maintenance/MttrController.php`
- Modify: `app/Http/Controllers/Maintenance/MtbfController.php`
- Modify: `app/Http/Controllers/Maintenance/AvailabilityController.php`
- Replace: `resources/views/maintenance/mttr.blade.php`, `mtbf.blade.php`, `availability.blade.php`

**Interfaces:**
- Consumes: `MaintenanceDashboardService::compute()` (Task 5).
- Produces: each `index` renders a filterable dashboard; each `data` returns JSON `{ rows: [...], summary: {...} }` for AJAX.

- [ ] **Step 1: Implement the three controllers**

`app/Http/Controllers/Maintenance/MttrController.php`:
```php
<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MttrController extends Controller
{
    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.mttr', ['mesinList' => MasterMesin::orderBy('id_mesin')->get()]);
    }

    public function data(Request $request)
    {
        $from    = Carbon::parse($request->input('date_from', date('Y-01-01')));
        $to      = Carbon::parse($request->input('date_to', date('Y-m-d')));
        $mesinId = (int) $request->input('mesin_id', 0) ?: null;
        $rows    = $this->svc->compute($from, $to, $mesinId);

        $withData = collect($rows)->where('count', '>', 0);
        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'avg_mttr'  => round((float) $withData->avg('mttr_jam'), 2),
                'total_bd'  => (int) collect($rows)->sum('count'),
            ],
        ]);
    }
}
```
`MtbfController` and `AvailabilityController`: same structure, but the `index` returns `maintenance.mtbf` / `maintenance.availability`, and the `summary` highlights the relevant metric:
- MTBF summary: `'avg_mtbf' => round((float) collect($rows)->whereNotNull('mtbf_jam')->avg('mtbf_jam'), 2)`.
- Availability summary: `'avg_availability' => round((float) collect($rows)->whereNotNull('availability_pct')->avg('availability_pct'), 2)`.

- [ ] **Step 2: Build the MTTR dashboard view**

`resources/views/maintenance/mttr.blade.php` — Alpine component that fetches `route('maintenance.mttr.data')` with the filter params, renders a summary card, a Chart.js bar chart (mttr_jam per mesin), and a detail table. Use the VCB pages (`resources/views/vcb/mesin.blade.php`) as the structural reference for the AJAX + Chart.js wiring (Chart.js is already loaded globally per CLAUDE.md).

```blade
@extends('layouts.app')
@section('content')
<div class="erp-panel" x-data="mttrDash()" x-init="load()">
  <div class="erp-panel-header">Dashboard MTTR Mesin</div>
  <div class="d-flex gap-2 p-2 flex-wrap">
    <input type="date" x-model="dateFrom" class="form-control form-control-sm" style="width:160px">
    <input type="date" x-model="dateTo" class="form-control form-control-sm" style="width:160px">
    <select x-model="mesinId" class="form-select form-select-sm" style="width:200px">
      <option value="0">Semua Mesin</option>
      @foreach($mesinList as $m)<option value="{{ $m->id }}">{{ $m->id_mesin }} - {{ $m->nama_mesin }}</option>@endforeach
    </select>
    <button class="btn btn-sm btn-secondary" @click="load()">Terapkan</button>
  </div>
  <div class="erp-panel-body">
    <div class="mb-3">Rata-rata MTTR: <strong x-text="summary.avg_mttr ?? '—'"></strong> jam · Total breakdown: <strong x-text="summary.total_bd ?? 0"></strong></div>
    <canvas id="mttrChart" height="90"></canvas>
    <table class="erp-table mt-3" style="min-width:600px">
      <thead><tr><th>Mesin</th><th>Nama</th><th style="text-align:right">Breakdown</th><th style="text-align:right">MTTR (jam)</th></tr></thead>
      <tbody>
        <template x-for="r in rows" :key="r.id_mesin">
          <tr><td x-text="r.kode_mesin"></td><td x-text="r.nama_mesin"></td><td style="text-align:right" x-text="r.count"></td><td style="text-align:right" x-text="r.mttr_jam"></td></tr>
        </template>
      </tbody>
    </table>
  </div>
</div>
<script>
function mttrDash() {
  return {
    dateFrom: '{{ date('Y-01-01') }}', dateTo: '{{ date('Y-m-d') }}', mesinId: '0',
    rows: [], summary: {}, chart: null,
    async load() {
      const url = new URL('{{ route('maintenance.mttr.data') }}', window.location.origin);
      url.search = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo, mesin_id: this.mesinId });
      const res = await fetch(url); const json = await res.json();
      this.rows = json.rows; this.summary = json.summary; this.draw();
    },
    draw() {
      const ctx = document.getElementById('mttrChart');
      if (this.chart) this.chart.destroy();
      this.chart = new Chart(ctx, {
        type: 'bar',
        data: { labels: this.rows.map(r => r.kode_mesin),
                datasets: [{ label: 'MTTR (jam)', data: this.rows.map(r => r.mttr_jam), backgroundColor: '#1f2937' }] },
        options: { responsive: true, plugins: { legend: { display: false } } }
      });
    }
  };
}
</script>
@endsection
```

- [ ] **Step 3: Build the MTBF + Availability views**

`resources/views/maintenance/mtbf.blade.php` — copy the MTTR view; rename the component to `mtbfDash`, change route to `maintenance.mtbf.data`, chart canvas id `mtbfChart`, dataset to `r.mtbf_jam` (label "MTBF (jam)"), summary line "Rata-rata MTBF: avg_mtbf jam", and the table's metric column to MTBF (jam) plus a column for Availability if desired. Add a small note row: when `r.instalasi_set` is false, show "instalasi belum diset" instead of a number (`<span x-show="!r.instalasi_set">instalasi belum diset</span><span x-show="r.instalasi_set" x-text="r.mtbf_jam"></span>`).

`resources/views/maintenance/availability.blade.php` — same copy; component `availDash`, route `maintenance.availability.data`, canvas `availChart`, dataset `r.availability_pct` (label "Availability %"), summary "Rata-rata Availability: avg_availability %", and the same `instalasi_set` guard in the table cell.

- [ ] **Step 4: Verify dashboards manually**

With the breakdown record(s) from Task 3 present and the mesin given a `tanggal_instalasi` (set one via tinker: `MasterMesin::first()->update(['tanggal_instalasi'=>'2026-01-01'])`), open `/maintenance/mttr`, `/maintenance/mtbf`, `/maintenance/availability`. Expected: chart renders, summary numbers populate, table lists each mesin, date filter re-fetches. For a mesin with null instalasi, MTBF/Availability cells show "instalasi belum diset" and no JS error in console.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Maintenance resources/views/maintenance/mttr.blade.php resources/views/maintenance/mtbf.blade.php resources/views/maintenance/availability.blade.php
git commit -m "feat(mtc): MTTR/MTBF/Availability dashboards with Chart.js + filters"
```

---

## Self-Review

**Spec coverage:**
- Role `staff_maintenance` → Task 2 (routes, redirect, user UI). ✓
- Master mesin `tanggal_instalasi` → Task 1. ✓
- Breakdown table + derived fields → Task 1 (schema) + Task 3 (server compute). ✓
- No.LKM generate + dedup → Task 3 (service + store) + Task 4 (update). ✓
- Input form + list (sticky-freeze) → Task 3. ✓
- Edit/hapus admin-only → Task 4. ✓
- 3 dashboards with date filter + lifetime default → Task 5 (service) + Task 6 (UI). ✓
- Defensive `tanggal_instalasi` (Schema check, null handling) → Task 5 (`Schema::hasColumn`, `instalasi_set`). ✓
- Tests for dashboard formula only → Task 5. ✓
- Sidebar nav → Task 2. ✓

**Placeholder scan:** Nav class names in Task 2 Step 5 are explicitly flagged as placeholders to replace with the real neighbouring classes — this is an instruction, not an unfilled blank. All code steps contain complete code.

**Type consistency:** `compute()` row keys (`mttr_jam`, `mtbf_jam`, `availability_pct`, `instalasi_set`, `count`, `kode_mesin`) are used identically in Task 6 controllers/views. Service method names (`generateNoLkm`, `computeDerived`, `nextNomorUrut`, `statusFromRepairHours`, `hoursBetween`) match between Task 3 definition and Task 4 reuse.

**Notes / deferred (YAGNI):** Breakdown import (Excel) was listed optional in the spec and is intentionally omitted from Fase 1 — add later if needed. Dies (Fase 2) is a separate spec/plan.
