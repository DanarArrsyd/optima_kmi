# OPTIMA Plan 2: Input Forms + Service Layer

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the service layer (ProduksiService, QcService, DandoriService) and all three input forms (LHP Produksi, LHP Quality, Input Dandori) with correct calculations persisted to DB.

**Architecture:** Service classes handle all math (duration, cycle time, menit_over). Controllers are thin: validate → call service → persist → redirect. Dandori form uses Alpine.js for reactive total calculation client-side; actual menit_over calculation happens server-side in DandoriService.

**Pre-requisite:** Plan 1 must be complete (migrations, models, auth, routes all working).

**Tech Stack:** PHP/Laravel 11, Carbon for time math, Alpine.js 3 for form reactivity

---

## Task 1: ProduksiService

**Files:**
- Create: `app/Services/ProduksiService.php`
- Create: `tests/Unit/Services/ProduksiServiceTest.php`

- [ ] **Step 1: Write failing unit tests**

`tests/Unit/Services/ProduksiServiceTest.php`:
```php
<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\ProduksiService;

class ProduksiServiceTest extends TestCase
{
    private ProduksiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProduksiService();
    }

    public function test_duration_same_day(): void
    {
        // 08:00 → 16:00 = 8 jam = 28800 detik
        $this->assertEquals(28800, $this->service->calculateDurationSeconds('08:00:00', '16:00:00'));
    }

    public function test_duration_overnight(): void
    {
        // 22:00 → 06:00 = 8 jam = 28800 detik
        $this->assertEquals(28800, $this->service->calculateDurationSeconds('22:00:00', '06:00:00'));
    }

    public function test_duration_with_minutes(): void
    {
        // 08:30 → 12:45 = 4 jam 15 menit = 15300 detik
        $this->assertEquals(15300, $this->service->calculateDurationSeconds('08:30:00', '12:45:00'));
    }

    public function test_actual_cycle_time(): void
    {
        // 3600 detik / 120 qty = 30.00 detik/pcs
        $this->assertEquals(30.00, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 120));
    }

    public function test_actual_cycle_time_zero_qty_returns_zero(): void
    {
        $this->assertEquals(0.0, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 0));
    }

    public function test_target_qty(): void
    {
        // 2 jam × 120 SPH = 240 target
        $this->assertEquals(240.0, $this->service->calculateTargetQty('08:00:00', '10:00:00', 120.0));
    }

    public function test_duration_hours(): void
    {
        $this->assertEquals(7.5, $this->service->calculateDurationHours('08:00:00', '15:30:00'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL (class not found)**
```bash
php artisan test tests/Unit/Services/ProduksiServiceTest.php
```
Expected: FAIL — `ProduksiService not found`

- [ ] **Step 3: Implement ProduksiService**

`app/Services/ProduksiService.php`:
```php
<?php
namespace App\Services;

class ProduksiService
{
    /**
     * Calculate duration in seconds between two time strings.
     * Handles overnight shifts (e.g., 22:00 → 06:00).
     */
    public function calculateDurationSeconds(string $jamStart, string $jamFinish): int
    {
        $startSec  = $this->timeToSeconds($jamStart);
        $finishSec = $this->timeToSeconds($jamFinish);

        if ($finishSec >= $startSec) {
            return $finishSec - $startSec;
        }

        // Overnight: finish is on the next day
        return (86400 - $startSec) + $finishSec;
    }

    /**
     * Duration in decimal hours.
     */
    public function calculateDurationHours(string $jamStart, string $jamFinish): float
    {
        return round($this->calculateDurationSeconds($jamStart, $jamFinish) / 3600, 4);
    }

    /**
     * Actual cycle time per piece in seconds.
     */
    public function calculateActualCycleTime(string $jamStart, string $jamFinish, int $qtyAktual): float
    {
        if ($qtyAktual === 0) {
            return 0.0;
        }
        return round($this->calculateDurationSeconds($jamStart, $jamFinish) / $qtyAktual, 2);
    }

    /**
     * Target quantity based on duration and SPH standard.
     * Target QTY = duration_hours × sph_standar
     */
    public function calculateTargetQty(string $jamStart, string $jamFinish, float $sphStandar): float
    {
        return round($this->calculateDurationHours($jamStart, $jamFinish) * $sphStandar, 2);
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        $h = (int) ($parts[0] ?? 0);
        $m = (int) ($parts[1] ?? 0);
        $s = (int) ($parts[2] ?? 0);
        return $h * 3600 + $m * 60 + $s;
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**
```bash
php artisan test tests/Unit/Services/ProduksiServiceTest.php
```
Expected: 7 PASS

- [ ] **Step 5: Commit**
```bash
git add app/Services/ProduksiService.php tests/Unit/Services/ProduksiServiceTest.php
git commit -m "feat: ProduksiService with duration, cycle time, target qty calculations"
```

---

## Task 2: QcService

**Files:**
- Create: `app/Services/QcService.php`
- Create: `tests/Unit/Services/QcServiceTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/Services/QcServiceTest.php`:
```php
<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\QcService;

class QcServiceTest extends TestCase
{
    private QcService $service;
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QcService();
    }

    public function test_total_qty_check(): void
    {
        $this->assertEquals(150, $this->service->calculateTotalQtyCheck(140, 10));
    }

    public function test_actual_cycle_time(): void
    {
        // 3600 detik / 150 total = 24.00 dtk
        $this->assertEquals(24.00, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 140, 10));
    }

    public function test_actual_cycle_time_zero_qty_returns_zero(): void
    {
        $this->assertEquals(0.0, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 0, 0));
    }

    public function test_target_qc_qty(): void
    {
        // 2 jam × 80 SPH = 160 target
        $this->assertEquals(160.0, $this->service->calculateTargetQcQty('08:00:00', '10:00:00', 80.0));
    }

    public function test_duration_hours_overnight(): void
    {
        // 22:00 → 06:00 = 8 jam
        $this->assertEquals(8.0, $this->service->calculateDurationHours('22:00:00', '06:00:00'));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Unit/Services/QcServiceTest.php
```

- [ ] **Step 3: Implement QcService**

`app/Services/QcService.php`:
```php
<?php
namespace App\Services;

class QcService
{
    public function calculateTotalQtyCheck(int $qtyOk, int $qtyNg): int
    {
        return $qtyOk + $qtyNg;
    }

    public function calculateDurationSeconds(string $jamStart, string $jamFinish): int
    {
        $startSec  = $this->timeToSeconds($jamStart);
        $finishSec = $this->timeToSeconds($jamFinish);
        if ($finishSec >= $startSec) {
            return $finishSec - $startSec;
        }
        return (86400 - $startSec) + $finishSec;
    }

    public function calculateDurationHours(string $jamStart, string $jamFinish): float
    {
        return round($this->calculateDurationSeconds($jamStart, $jamFinish) / 3600, 4);
    }

    public function calculateActualCycleTime(string $jamStart, string $jamFinish, int $qtyOk, int $qtyNg): float
    {
        $total = $this->calculateTotalQtyCheck($qtyOk, $qtyNg);
        if ($total === 0) {
            return 0.0;
        }
        return round($this->calculateDurationSeconds($jamStart, $jamFinish) / $total, 2);
    }

    public function calculateTargetQcQty(string $jamStart, string $jamFinish, float $sphStandarQc): float
    {
        return round($this->calculateDurationHours($jamStart, $jamFinish) * $sphStandarQc, 2);
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        return ((int)($parts[0] ?? 0)) * 3600 + ((int)($parts[1] ?? 0)) * 60 + ((int)($parts[2] ?? 0));
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**
```bash
php artisan test tests/Unit/Services/QcServiceTest.php
```
Expected: 5 PASS

- [ ] **Step 5: Commit**
```bash
git add app/Services/QcService.php tests/Unit/Services/QcServiceTest.php
git commit -m "feat: QcService with cycle time and target qty calculations"
```

---

## Task 3: DandoriService

**Files:**
- Create: `app/Services/DandoriService.php`
- Create: `tests/Unit/Services/DandoriServiceTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Unit/Services/DandoriServiceTest.php`:
```php
<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\DandoriService;
use App\Models\MasterLosstime;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DandoriServiceTest extends TestCase
{
    use RefreshDatabase;

    private DandoriService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DandoriService();
    }

    public function test_menit_over_setting_dies_progressive(): void
    {
        // Setting_Dies, PROGRESSIVE, std=15 → max(20-15, 0) = 5
        $losstime = MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting_Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'PROGRESSIVE', 20);
        $this->assertEquals(5, $result);
    }

    public function test_menit_over_setting_dies_non_progressive(): void
    {
        // Setting_Dies, BENDING, std=10 → max(8-10, 0) = 0
        $losstime = MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting_Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'BENDING', 8);
        $this->assertEquals(0, $result);
    }

    public function test_menit_over_trial_qc(): void
    {
        // Trial_QC, std=5 → max(7-5, 0) = 2
        $losstime = MasterLosstime::create([
            'kode' => '7', 'jenis_losstime' => 'Trial_QC',
            'std_menit_default' => 5, 'std_menit_progressive' => 5,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'DRAW', 7);
        $this->assertEquals(2, $result);
    }

    public function test_menit_over_full_loss_type(): void
    {
        // Tunggu_Dies, std=NULL → menit_over = menit_loss (as-is)
        $losstime = MasterLosstime::create([
            'kode' => '4', 'jenis_losstime' => 'Tunggu_Dies',
            'std_menit_default' => null, 'std_menit_progressive' => null,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'DRAW', 45);
        $this->assertEquals(45, $result);
    }

    public function test_status_ok(): void
    {
        $this->assertEquals('OK', $this->service->hitungStatus(25, 30));
    }

    public function test_status_over(): void
    {
        $this->assertEquals('OVER', $this->service->hitungStatus(35, 30));
    }

    public function test_status_exact_standard_is_ok(): void
    {
        $this->assertEquals('OK', $this->service->hitungStatus(30, 30));
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Unit/Services/DandoriServiceTest.php
```

- [ ] **Step 3: Implement DandoriService**

`app/Services/DandoriService.php`:
```php
<?php
namespace App\Services;
use App\Models\MasterLosstime;

class DandoriService
{
    /**
     * Calculate menit_over for a single rincian item.
     * Logic mirrors Google Apps Script hitungMenitOver():
     * - If std_menit is NULL → full loss (menit_over = menit_loss)
     * - If std_menit is set → menit_over = max(menit_loss - std_menit, 0)
     * - PROGRESSIVE proses uses std_menit_progressive; others use std_menit_default
     */
    public function hitungMenitOver(MasterLosstime $losstime, string $namaProses, int $menitLoss): int
    {
        $isProgressive = strtoupper(trim($namaProses)) === 'PROGRESSIVE';
        $stdMenit = $isProgressive
            ? $losstime->std_menit_progressive
            : $losstime->std_menit_default;

        if ($stdMenit === null) {
            return $menitLoss; // Full loss — entire duration is over-standard
        }

        return max($menitLoss - $stdMenit, 0);
    }

    /**
     * Determine status: OVER if total_aktual > standard_time, else OK.
     */
    public function hitungStatus(int $totalAktualMenit, int $standardTimeMenit): string
    {
        return $totalAktualMenit > $standardTimeMenit ? 'OVER' : 'OK';
    }

    /**
     * Process an array of rincian and return enriched data with menit_over.
     * $rincianInput: array of ['id_losstime' => int, 'menit_loss' => int]
     * $namaProses: the proses name (determines PROGRESSIVE vs DEFAULT std time)
     * Returns: array of ['id_losstime', 'menit_loss', 'menit_over']
     */
    public function processRincian(array $rincianInput, string $namaProses): array
    {
        $losstimeMap = MasterLosstime::whereIn('id', collect($rincianInput)->pluck('id_losstime'))
            ->get()
            ->keyBy('id');

        return collect($rincianInput)->map(function ($item) use ($losstimeMap, $namaProses) {
            $losstime  = $losstimeMap->get($item['id_losstime']);
            $menitLoss = (int) $item['menit_loss'];
            $menitOver = $losstime ? $this->hitungMenitOver($losstime, $namaProses, $menitLoss) : $menitLoss;

            return [
                'id_losstime' => $item['id_losstime'],
                'menit_loss'  => $menitLoss,
                'menit_over'  => $menitOver,
            ];
        })->toArray();
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**
```bash
php artisan test tests/Unit/Services/DandoriServiceTest.php
```
Expected: 7 PASS

- [ ] **Step 5: Commit**
```bash
git add app/Services/DandoriService.php tests/Unit/Services/DandoriServiceTest.php
git commit -m "feat: DandoriService with menit_over and status calculations"
```

---

## Task 4: Input LHP Produksi

**Files:**
- Modify: `app/Http/Controllers/Input/ProduksiController.php`
- Create: `app/Http/Requests/StoreProduksiRequest.php`
- Create: `resources/views/input/produksi/index.blade.php`
- Create: `resources/views/input/produksi/create.blade.php`
- Test: `tests/Feature/Input/ProduksiTest.php`

- [ ] **Step 1: Write failing feature tests**

`tests/Feature/Input/ProduksiTest.php`:
```php
<?php
namespace Tests\Feature\Input;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProduksiTest extends TestCase
{
    use RefreshDatabase;

    private User $staffProduksi;
    private array $baseData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffProduksi = User::factory()->create(['role' => 'staff_produksi']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $mesin    = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'A1-AIDA', 'line' => 'A', 'tipe_mesin' => 'STAMPING']);
        $part     = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->baseData = [
            'tanggal_produksi' => '2026-05-23',
            'id_shift'         => $shift->id,
            'id_karyawan'      => $karyawan->id,
            'id_mesin'         => $mesin->id,
            'id_part_proses'   => $proses->id,
            'jam_start'        => '08:00',
            'jam_finish'       => '16:00',
            'qty_aktual'       => 960,
        ];
    }

    public function test_staff_produksi_can_view_produksi_list(): void
    {
        $this->actingAs($this->staffProduksi)
             ->get('/input/produksi')
             ->assertStatus(200);
    }

    public function test_staff_produksi_can_store_produksi(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', $this->baseData)
             ->assertRedirect('/input/produksi');

        $this->assertDatabaseHas('transaksi_produksi', [
            'tanggal_produksi' => '2026-05-23',
            'qty_aktual'       => 960,
        ]);
    }

    public function test_actual_cycle_time_is_calculated_on_store(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', $this->baseData);

        // 8 jam = 28800 detik / 960 qty = 30.00 detik
        $record = TransaksiProduksi::first();
        $this->assertEquals(30.00, $record->actual_cycle_time);
    }

    public function test_created_by_is_set_automatically(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', $this->baseData);
        $record = TransaksiProduksi::first();
        $this->assertEquals($this->staffProduksi->id, $record->created_by);
    }

    public function test_staff_qc_cannot_access_input_produksi(): void
    {
        $staffQc = User::factory()->create(['role' => 'staff_qc']);
        $this->actingAs($staffQc)->get('/input/produksi')->assertStatus(403);
    }

    public function test_qty_aktual_must_be_positive(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', array_merge($this->baseData, ['qty_aktual' => 0]))
             ->assertSessionHasErrors('qty_aktual');
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Feature/Input/ProduksiTest.php
```

- [ ] **Step 3: Create StoreProduksiRequest**

`app/Http/Requests/StoreProduksiRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreProduksiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_produksi']);
    }

    public function rules(): array
    {
        return [
            'tanggal_produksi' => 'required|date',
            'id_shift'         => 'required|exists:master_shift,id',
            'id_karyawan'      => 'required|exists:master_karyawan,id',
            'id_mesin'         => 'required|exists:master_mesin,id',
            'id_part_proses'   => 'required|exists:master_part_proses,id',
            'jam_start'        => 'required|date_format:H:i',
            'jam_finish'       => 'required|date_format:H:i',
            'qty_aktual'       => 'required|integer|min:1',
        ];
    }
}
```

- [ ] **Step 4: Implement ProduksiController**

`app/Http/Controllers/Input/ProduksiController.php`:
```php
<?php
namespace App\Http\Controllers\Input;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProduksiRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\ProduksiService;

class ProduksiController extends Controller
{
    public function __construct(private ProduksiService $produksiService) {}

    public function index()
    {
        $transaksi = TransaksiProduksi::with(['karyawan', 'mesin', 'partProses.part', 'shift'])
            ->orderByDesc('tanggal_produksi')
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('input.produksi.index', compact('transaksi'));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin    = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        return view('input.produksi.create', compact('shifts', 'karyawan', 'mesin', 'parts'));
    }

    public function store(StoreProduksiRequest $request)
    {
        $validated = $request->validated();

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $validated['jam_start'] . ':00',
            $validated['jam_finish'] . ':00',
            $validated['qty_aktual']
        );

        TransaksiProduksi::create([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
            'created_by'        => auth()->id(),
        ]);

        return redirect()->route('input.produksi.index')
                         ->with('success', 'LHP Produksi berhasil disimpan.');
    }

    public function destroy(TransaksiProduksi $produksi)
    {
        $this->authorize('delete', $produksi); // Only creator or admin can delete
        $produksi->delete();
        return redirect()->route('input.produksi.index')->with('success', 'Data berhasil dihapus.');
    }
}
```

Note: For simplicity, add a basic policy or inline check in destroy: `if (auth()->user()->role !== 'admin' && $produksi->created_by !== auth()->id()) abort(403);`

- [ ] **Step 5: Create index view**

`resources/views/input/produksi/index.blade.php`:
```html
@extends('layouts.app')
@section('title', 'LHP Produksi')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clipboard-plus me-2"></i>Input LHP Produksi</h4>
    <a href="{{ route('input.produksi.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Input Baru
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Tanggal</th><th>Shift</th><th>Operator</th><th>Mesin</th>
                    <th>Part</th><th>Jam</th><th>Qty Aktual</th><th>CT Aktual</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transaksi as $t)
                <tr>
                    <td>{{ $t->tanggal_produksi->format('d/m/Y') }}</td>
                    <td>{{ $t->shift->nama_shift }}</td>
                    <td>{{ $t->karyawan->nama_karyawan }}</td>
                    <td>{{ $t->mesin->id_mesin }}</td>
                    <td>{{ $t->partProses->part->part_name }} <small class="text-muted">({{ $t->partProses->nama_proses }})</small></td>
                    <td><small>{{ $t->jam_start }} – {{ $t->jam_finish }}</small></td>
                    <td>{{ number_format($t->qty_aktual) }}</td>
                    <td>{{ $t->actual_cycle_time }}s</td>
                    <td>
                        <form method="POST" action="{{ route('input.produksi.destroy', $t) }}" class="d-inline"
                              onsubmit="return confirm('Hapus data ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $transaksi->links() }}</div>
@endsection
```

- [ ] **Step 6: Create form view**

`resources/views/input/produksi/create.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Input LHP Produksi')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Input LHP Produksi</h4>
</div>
<div class="card shadow-sm" style="max-width:700px">
    <div class="card-body">
        <form method="POST" action="{{ route('input.produksi.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi" class="form-control @error('tanggal_produksi') is-invalid @enderror"
                           value="{{ old('tanggal_produksi', date('Y-m-d')) }}">
                    @error('tanggal_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih Shift --</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }} ({{ $shift->jam_start }} – {{ $shift->jam_finish }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Pilih Operator --</option>
                        @foreach($karyawan as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror">
                        <option value="">-- Pilih Mesin --</option>
                        @foreach($mesin as $m)
                            <option value="{{ $m->id }}" {{ old('id_mesin') == $m->id ? 'selected' : '' }}>
                                {{ $m->nama_mesin }} (Line {{ $m->line }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-12" x-data="partProsesSelector()">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <select name="part_id_temp" class="form-select mb-2" x-model="selectedPart" @change="loadProses()">
                        <option value="">-- Pilih Part --</option>
                        @foreach($parts as $p)
                            <option value="{{ $p->id }}">{{ $p->part_no }} — {{ $p->part_name }}</option>
                        @endforeach
                    </select>
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses} (SPH: ${p.sph_standar_produksi})`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Qty Aktual <span class="text-danger">*</span></label>
                    <input type="number" name="qty_aktual" class="form-control @error('qty_aktual') is-invalid @enderror"
                           value="{{ old('qty_aktual') }}" min="1">
                    @error('qty_aktual')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
@push('scripts')
<script>
// Pass parts data from PHP to JS
const partsData = @json($parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses]));

function partProsesSelector() {
    return {
        selectedPart: '',
        prosesList: [],
        loadProses() {
            const part = partsData.find(p => p.id == this.selectedPart);
            this.prosesList = part ? part.proses : [];
        }
    }
}
</script>
@endpush
```

- [ ] **Step 7: Run tests**
```bash
php artisan test tests/Feature/Input/ProduksiTest.php
```
Expected: 6 PASS

- [ ] **Step 8: Commit**
```bash
git add app/Http/Controllers/Input/ProduksiController.php \
        app/Http/Requests/StoreProduksiRequest.php \
        resources/views/input/produksi/ \
        tests/Feature/Input/ProduksiTest.php
git commit -m "feat: input LHP Produksi with auto cycle time calculation"
```

---

## Task 5: Input LHP Quality

**Files:**
- Modify: `app/Http/Controllers/Input/QcController.php`
- Create: `app/Http/Requests/StoreQcRequest.php`
- Create: `resources/views/input/qc/index.blade.php`
- Create: `resources/views/input/qc/create.blade.php`
- Test: `tests/Feature/Input/QcTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Input/QcTest.php`:
```php
<?php
namespace Tests\Feature\Input;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QcTest extends TestCase
{
    use RefreshDatabase;

    private User $staffQc;
    private array $baseData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffQc = User::factory()->create(['role' => 'staff_qc']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'Q001', 'nama_karyawan' => 'Siti', 'departemen' => 'QC']);
        $part     = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->baseData = [
            'tgl_pengecekan'   => '2026-05-23',
            'tgl_produksi_part' => '2026-05-22',
            'id_shift'         => $shift->id,
            'id_karyawan_qc'   => $karyawan->id,
            'id_part_proses'   => $proses->id,
            'jam_start'        => '08:00',
            'jam_finish'       => '14:00',
            'qty_ok'           => 140,
            'qty_ng'           => 10,
        ];
    }

    public function test_staff_qc_can_store_qc(): void
    {
        $this->actingAs($this->staffQc)
             ->post('/input/qc', $this->baseData)
             ->assertRedirect('/input/qc');
        $this->assertDatabaseHas('transaksi_qc', ['qty_ok' => 140, 'qty_ng' => 10]);
    }

    public function test_actual_cycle_time_is_calculated(): void
    {
        $this->actingAs($this->staffQc)->post('/input/qc', $this->baseData);
        // 6 jam = 21600 detik / (140+10) = 144 detik
        $record = TransaksiQc::first();
        $this->assertEquals(144.00, $record->actual_cycle_time);
    }

    public function test_staff_produksi_cannot_access_input_qc(): void
    {
        $staffProd = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staffProd)->get('/input/qc')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Feature/Input/QcTest.php
```

- [ ] **Step 3: Create StoreQcRequest**

`app/Http/Requests/StoreQcRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreQcRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_qc']);
    }

    public function rules(): array
    {
        return [
            'tgl_pengecekan'    => 'required|date',
            'tgl_produksi_part' => 'required|date',
            'id_shift'          => 'required|exists:master_shift,id',
            'id_karyawan_qc'    => 'required|exists:master_karyawan,id',
            'id_part_proses'    => 'required|exists:master_part_proses,id',
            'jam_start'         => 'required|date_format:H:i',
            'jam_finish'        => 'required|date_format:H:i',
            'qty_ok'            => 'required|integer|min:0',
            'qty_ng'            => 'required|integer|min:0',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $qtyOk = (int) $this->input('qty_ok', 0);
            $qtyNg = (int) $this->input('qty_ng', 0);
            if ($qtyOk + $qtyNg === 0) {
                $validator->errors()->add('qty_ok', 'Total qty check (OK + NG) harus lebih dari 0.');
            }
        });
    }
}
```

- [ ] **Step 4: Implement QcController**

`app/Http/Controllers/Input/QcController.php`:
```php
<?php
namespace App\Http\Controllers\Input;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQcRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Services\QcService;

class QcController extends Controller
{
    public function __construct(private QcService $qcService) {}

    public function index()
    {
        $transaksi = TransaksiQc::with(['karyawanQc', 'partProses.part', 'shift'])
            ->orderByDesc('tgl_pengecekan')
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('input.qc.index', compact('transaksi'));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->orderBy('nama_karyawan')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        return view('input.qc.create', compact('shifts', 'karyawan', 'parts'));
    }

    public function store(StoreQcRequest $request)
    {
        $validated = $request->validated();

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $validated['jam_start'] . ':00',
            $validated['jam_finish'] . ':00',
            (int) $validated['qty_ok'],
            (int) $validated['qty_ng']
        );

        TransaksiQc::create([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
            'created_by'        => auth()->id(),
        ]);

        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil disimpan.');
    }

    public function destroy(TransaksiQc $qc)
    {
        if (auth()->user()->role !== 'admin' && $qc->created_by !== auth()->id()) {
            abort(403);
        }
        $qc->delete();
        return redirect()->route('input.qc.index')->with('success', 'Data berhasil dihapus.');
    }
}
```

- [ ] **Step 5: Create views**

`resources/views/input/qc/index.blade.php` — same structure as produksi/index. Columns: Tgl Cek | Tgl Produksi | Shift | QC Inspector | Part | Jam | Qty OK | Qty NG | Achievement CT | Aksi.

`resources/views/input/qc/create.blade.php` — same structure as produksi/create. Key differences:
- Field `id_karyawan_qc` (dropdown karyawan departemen QC only)
- Extra field: `tgl_produksi_part` (date picker, "Tanggal produksi part yang diperiksa")
- Fields: `qty_ok` and `qty_ng` (both required, min 0, note: total must be > 0)

- [ ] **Step 6: Run tests**
```bash
php artisan test tests/Feature/Input/QcTest.php
```
Expected: 3 PASS

- [ ] **Step 7: Commit**
```bash
git add app/Http/Controllers/Input/QcController.php \
        app/Http/Requests/StoreQcRequest.php \
        resources/views/input/qc/
git commit -m "feat: input LHP Quality with auto cycle time calculation"
```

---

## Task 6: Input Dandori (Complex Dynamic Form)

**Files:**
- Modify: `app/Http/Controllers/Input/DandoriController.php`
- Create: `app/Http/Requests/StoreDandoriRequest.php`
- Create: `resources/views/input/dandori/index.blade.php`
- Create: `resources/views/input/dandori/create.blade.php`
- Test: `tests/Feature/Input/DandoriTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Input/DandoriTest.php`:
```php
<?php
namespace Tests\Feature\Input;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\MasterLosstime;
use App\Models\TransaksiDandori;
use App\Models\TransaksiDandoriRincian;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DandoriTest extends TestCase
{
    use RefreshDatabase;

    private User $staffProduksi;
    private array $baseData;
    private MasterLosstime $settingDies;
    private MasterLosstime $trialQc;
    private MasterPartProses $proses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffProduksi = User::factory()->create(['role' => 'staff_produksi']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $mesin    = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'A1-AIDA', 'line' => 'A', 'tipe_mesin' => 'STAMPING']);
        $part     = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->settingDies = MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting_Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $this->trialQc = MasterLosstime::create([
            'kode' => '7', 'jenis_losstime' => 'Trial_QC',
            'std_menit_default' => 5, 'std_menit_progressive' => 5,
        ]);
        $this->baseData = [
            'tanggal'        => '2026-05-23',
            'id_shift'       => $shift->id,
            'id_karyawan'    => $karyawan->id,
            'id_mesin'       => $mesin->id,
            'id_part_proses' => $this->proses->id,
            'rincian'        => [
                ['id_losstime' => $this->settingDies->id, 'menit_loss' => 20], // PROGRESSIVE: over = max(20-15,0)=5
                ['id_losstime' => $this->trialQc->id,    'menit_loss' => 7],  // over = max(7-5,0)=2
            ],
        ];
    }

    public function test_staff_produksi_can_store_dandori(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/dandori', $this->baseData)
             ->assertRedirect('/input/dandori');
        $this->assertDatabaseHas('transaksi_dandori', ['tanggal' => '2026-05-23']);
    }

    public function test_total_aktual_is_sum_of_rincian(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        // 20 + 7 = 27 menit
        $dandori = TransaksiDandori::first();
        $this->assertEquals(27, $dandori->total_aktual_menit);
    }

    public function test_standard_time_comes_from_part_proses(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        $dandori = TransaksiDandori::first();
        $this->assertEquals(15, $dandori->standard_time_menit); // from proses.std_dandori_menit
    }

    public function test_status_over_when_aktual_exceeds_standard(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        // 27 > 15 → OVER
        $dandori = TransaksiDandori::first();
        $this->assertEquals('OVER', $dandori->status);
    }

    public function test_rincian_menit_over_is_calculated_correctly(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        $rincian = TransaksiDandoriRincian::all();
        $this->assertCount(2, $rincian);
        // Setting_Dies PROGRESSIVE: max(20-15,0) = 5
        $this->assertEquals(5, $rincian->firstWhere('id_losstime', $this->settingDies->id)->menit_over);
        // Trial_QC: max(7-5,0) = 2
        $this->assertEquals(2, $rincian->firstWhere('id_losstime', $this->trialQc->id)->menit_over);
    }

    public function test_minimum_one_rincian_required(): void
    {
        $data = $this->baseData;
        $data['rincian'] = [];
        $this->actingAs($this->staffProduksi)
             ->post('/input/dandori', $data)
             ->assertSessionHasErrors('rincian');
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Feature/Input/DandoriTest.php
```

- [ ] **Step 3: Create StoreDandoriRequest**

`app/Http/Requests/StoreDandoriRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreDandoriRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasRole(['admin', 'staff_produksi']);
    }

    public function rules(): array
    {
        return [
            'tanggal'              => 'required|date',
            'id_shift'             => 'required|exists:master_shift,id',
            'id_karyawan'          => 'required|exists:master_karyawan,id',
            'id_mesin'             => 'required|exists:master_mesin,id',
            'id_part_proses'       => 'required|exists:master_part_proses,id',
            'rincian'              => 'required|array|min:1',
            'rincian.*.id_losstime' => 'required|exists:master_losstime,id',
            'rincian.*.menit_loss'  => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'rincian.required' => 'Minimal harus ada 1 aktivitas loss time.',
            'rincian.min'      => 'Minimal harus ada 1 aktivitas loss time.',
        ];
    }
}
```

- [ ] **Step 4: Implement DandoriController**

`app/Http/Controllers/Input/DandoriController.php`:
```php
<?php
namespace App\Http\Controllers\Input;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDandoriRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterLosstime;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandori;
use App\Services\DandoriService;

class DandoriController extends Controller
{
    public function __construct(private DandoriService $dandoriService) {}

    public function index()
    {
        $transaksi = TransaksiDandori::with(['karyawan', 'mesin', 'partProses.part', 'shift', 'rincian.losstime'])
            ->orderByDesc('tanggal')
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('input.dandori.index', compact('transaksi'));
    }

    public function create()
    {
        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin     = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts     = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();
        return view('input.dandori.create', compact('shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function store(StoreDandoriRequest $request)
    {
        $validated   = $request->validated();
        $partProses  = MasterPartProses::findOrFail($validated['id_part_proses']);
        $namaProses  = $partProses->nama_proses;
        $standardTime = $partProses->std_dandori_menit;

        // Calculate menit_over for each rincian
        $processedRincian = $this->dandoriService->processRincian($validated['rincian'], $namaProses);

        // Sum total aktual
        $totalAktual = collect($processedRincian)->sum('menit_loss');
        $status      = $this->dandoriService->hitungStatus($totalAktual, $standardTime);

        // Persist header
        $dandori = TransaksiDandori::create([
            'tanggal'              => $validated['tanggal'],
            'id_shift'             => $validated['id_shift'],
            'id_karyawan'          => $validated['id_karyawan'],
            'id_mesin'             => $validated['id_mesin'],
            'id_part_proses'       => $validated['id_part_proses'],
            'total_aktual_menit'   => $totalAktual,
            'standard_time_menit'  => $standardTime,
            'status'               => $status,
            'created_by'           => auth()->id(),
        ]);

        // Persist rincian
        $dandori->rincian()->createMany($processedRincian);

        return redirect()->route('input.dandori.index')
                         ->with('success', "Dandori berhasil disimpan. Total: {$totalAktual} menit ({$status}).");
    }

    public function destroy(TransaksiDandori $dandori)
    {
        if (auth()->user()->role !== 'admin' && $dandori->created_by !== auth()->id()) {
            abort(403);
        }
        $dandori->delete(); // cascadeOnDelete removes rincian too
        return redirect()->route('input.dandori.index')->with('success', 'Data berhasil dihapus.');
    }

    // AJAX: get proses list for a given part
    public function getPartProses(MasterPart $part)
    {
        return response()->json($part->proses()->select('id', 'id_proses', 'nama_proses', 'std_dandori_menit')->get());
    }

    // AJAX: get standard_time for a given id_part_proses
    public function getStandardTime(MasterPartProses $partProses)
    {
        return response()->json(['std_dandori_menit' => $partProses->std_dandori_menit]);
    }
}
```

- [ ] **Step 5: Create index view**

`resources/views/input/dandori/index.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Input Dandori')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-tools me-2"></i>Input Dandori</h4>
    <a href="{{ route('input.dandori.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Input Baru
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Tanggal</th><th>Shift</th><th>Operator</th><th>Mesin</th>
                    <th>Part / Proses</th><th>Total</th><th>Std</th><th>Status</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transaksi as $t)
                @php $statusColor = $t->status === 'OVER' ? 'danger' : 'success'; @endphp
                <tr>
                    <td>{{ $t->tanggal->format('d/m/Y') }}</td>
                    <td>{{ $t->shift->nama_shift }}</td>
                    <td>{{ $t->karyawan->nama_karyawan }}</td>
                    <td>{{ $t->mesin->id_mesin }}</td>
                    <td>{{ $t->partProses->part->part_name }} <small class="text-muted">({{ $t->partProses->nama_proses }})</small></td>
                    <td>{{ $t->total_aktual_menit }} mnt</td>
                    <td>{{ $t->standard_time_menit }} mnt</td>
                    <td><span class="badge bg-{{ $statusColor }}">{{ $t->status }}</span></td>
                    <td>
                        <form method="POST" action="{{ route('input.dandori.destroy', $t) }}" class="d-inline"
                              onsubmit="return confirm('Hapus data ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $transaksi->links() }}</div>
@endsection
```

- [ ] **Step 6: Create dandori form view (Alpine.js reactive)**

`resources/views/input/dandori/create.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Input Dandori')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Input Dandori</h4>
</div>

<div class="card shadow-sm" x-data="dandoriForm()" style="max-width:800px">
    <div class="card-body">
        <form method="POST" action="{{ route('input.dandori.store') }}">
            @csrf

            {{-- Header fields --}}
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal" class="form-control @error('tanggal') is-invalid @enderror"
                           value="{{ old('tanggal', date('Y-m-d')) }}">
                    @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
                        @foreach($karyawan as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror">
                        <option value="">-- Pilih Mesin --</option>
                        @foreach($mesin as $m)
                            <option value="{{ $m->id }}" {{ old('id_mesin') == $m->id ? 'selected' : '' }}>
                                {{ $m->nama_mesin }} (Line {{ $m->line }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <select class="form-select" @change="onPartChange($event.target.value)">
                        <option value="">-- Pilih Part --</option>
                        @foreach($parts as $p)
                            <option value="{{ $p->id }}">{{ $p->part_no }} — {{ $p->part_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0" @change="onProsesChange($event.target.value)">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Standard Time (menit)</label>
                    <input type="text" class="form-control" :value="standardTime > 0 ? standardTime + ' menit' : '-'" readonly>
                </div>
            </div>

            {{-- Rincian Loss Time --}}
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0 fw-semibold">Rincian Loss Time <span class="text-danger">*</span></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRow()">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Aktivitas
                    </button>
                </div>
                @error('rincian')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr><th>Jenis Loss</th><th style="width:140px">Menit</th><th style="width:60px"></th></tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in rows" :key="index">
                                <tr>
                                    <td>
                                        <select :name="`rincian[${index}][id_losstime]`" class="form-select form-select-sm" x-model="row.id_losstime" required>
                                            <option value="">-- Pilih --</option>
                                            @foreach($losstimes as $lt)
                                                <option value="{{ $lt->id }}">{{ $lt->kode }} — {{ $lt->jenis_losstime }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" :name="`rincian[${index}][menit_loss]`"
                                               class="form-control form-control-sm"
                                               x-model.number="row.menit_loss"
                                               @input="recalculate()"
                                               min="1" required>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                @click="removeRow(index)" :disabled="rows.length === 1">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Summary --}}
            <div class="p-3 rounded mb-4" :class="status === 'OVER' ? 'bg-danger bg-opacity-10 border border-danger' : 'bg-success bg-opacity-10 border border-success'">
                <div class="row text-center">
                    <div class="col">
                        <div class="small text-muted">Total Aktual</div>
                        <div class="fw-bold fs-5" x-text="totalAktual + ' menit'"></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Standard</div>
                        <div class="fw-bold fs-5" x-text="standardTime > 0 ? standardTime + ' menit' : '-'"></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Selisih</div>
                        <div class="fw-bold fs-5" :class="status === 'OVER' ? 'text-danger' : 'text-success'"
                             x-text="standardTime > 0 ? (totalAktual - standardTime > 0 ? '+' : '') + (totalAktual - standardTime) + ' menit' : '-'"></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Status</div>
                        <span class="badge fs-6" :class="status === 'OVER' ? 'bg-danger' : 'bg-success'" x-text="status"></span>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Simpan</button>
                <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const partsData = @json($parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses, 'std_dandori_menit' => $pr->std_dandori_menit])]));

function dandoriForm() {
    return {
        prosesList: [],
        standardTime: 0,
        rows: [{ id_losstime: '', menit_loss: 1 }],
        totalAktual: 0,
        status: 'OK',

        onPartChange(partId) {
            const part = partsData.find(p => p.id == partId);
            this.prosesList = part ? part.proses : [];
            this.standardTime = 0;
            this.status = 'OK';
        },

        onProsesChange(prosesId) {
            const proses = this.prosesList.find(p => p.id == prosesId);
            this.standardTime = proses ? proses.std_dandori_menit : 0;
            this.recalculate();
        },

        addRow() {
            this.rows.push({ id_losstime: '', menit_loss: 1 });
            this.recalculate();
        },

        removeRow(index) {
            if (this.rows.length > 1) {
                this.rows.splice(index, 1);
                this.recalculate();
            }
        },

        recalculate() {
            this.totalAktual = this.rows.reduce((sum, r) => sum + (parseInt(r.menit_loss) || 0), 0);
            this.status = (this.standardTime > 0 && this.totalAktual > this.standardTime) ? 'OVER' : 'OK';
        }
    }
}
</script>
@endpush
```

- [ ] **Step 7: Run tests**
```bash
php artisan test tests/Feature/Input/DandoriTest.php
```
Expected: 6 PASS

- [ ] **Step 8: Run full test suite**
```bash
php artisan test
```
Expected: All tests PASS.

- [ ] **Step 9: Commit**
```bash
git add app/Http/Controllers/Input/DandoriController.php \
        app/Http/Requests/StoreDandoriRequest.php \
        resources/views/input/dandori/
git commit -m "feat: input dandori with Alpine.js reactive form and menit_over calculation"
```

---

## Task 7: Final Plan 2 Verification

- [ ] **Step 1: Run complete test suite**
```bash
php artisan test
```
Expected: All tests PASS.

- [ ] **Step 2: Migrate fresh + seed**
```bash
php artisan migrate:fresh --seed
```
Expected: Clean database, seeded data intact.

- [ ] **Step 3: Manual smoke test — Dandori form**
1. Login as staff_produksi
2. Navigate to Input > Dandori > Input Baru
3. Select Part → Proses → observe Standard Time auto-fills
4. Add 2 rincian loss time rows, enter menit values
5. Observe Total Aktual and Status update live
6. Submit → verify record appears in index list with correct status badge

- [ ] **Step 4: Final commit**
```bash
git add .
git commit -m "feat: Plan 2 complete — service layer and all 3 input forms"
```

---

**Plan 2 done.** Proceed to Plan 3 (`2026-05-23-optima-plan-3-dashboards-export.md`) for VCB Dashboards + Export.
