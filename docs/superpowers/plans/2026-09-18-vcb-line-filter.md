# Filter LINE VCB Produksi & Mesin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah filter LINE (multi-select checkbox) ke VCB Produksi (Mode 1 & Mode 2) dan VCB Mesin, dari kolom `master_mesin.line` yang sudah ada.

**Architecture:** Query filter tambahan (`whereHas('mesin', ...)`) disisipkan di 3 method `DashboardService` yang sudah ada, diteruskan dari request `line[]` lewat 2 controller VCB, dirender sebagai grup checkbox di dock filter Blade yang sudah ada.

**Tech Stack:** Laravel 13 (PHP 8.4 di CI/prod), Eloquent, Blade + vanilla JS (`URLSearchParams`), PHPUnit (SQLite in-memory).

## Global Constraints

- CI & prod jalan PHP 8.4 — jangan pakai sintaks yang cuma ada di PHP 8.5.
- Pint scope cuma `app/` + `tests/` — jalankan `vendor/bin/pint app tests` sebelum commit kalau ubah file di situ.
- **Tidak ada migrasi baru** di plan ini — kolom `master_mesin.line` sudah live di prod. Jangan sentuh `deploy.yml`.
- `php artisan test` jalan di SQLite in-memory, tidak butuh MySQL lokal.
- Semua method yang disentuh sudah eager-load relasi `mesin` — filter baru harus lewat relasi itu (`whereHas`), bukan query baru/join manual.

---

### Task 1: DashboardService — parameter filter `$lines`

**Files:**
- Modify: `app/Services/DashboardService.php:15-20` (`getVcbProduksi`), `:72-80` (`getVcbProduksiByDate`), `:133-138` (`getVcbMesin`)
- Test: `tests/Unit/DashboardServiceTest.php`

**Interfaces:**
- Produces: `getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = []): array`, `getVcbProduksiByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanId, ?int $shiftId = null, array $lines = []): array`, `getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = []): array` — dipakai Task 2.

- [ ] **Step 1: Write the failing tests**

Tambahkan di akhir `tests/Unit/DashboardServiceTest.php`, sebelum `}` penutup class (setelah `test_shift_filter_narrows_produksi`):

```php
    public function test_line_filter_narrows_produksi(): void
    {
        $b = $this->base(); // mesin line '1'
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        // Karyawan sama di kedua sesi → tanpa filter jadi 1 baris gabungan (4999).
        // Dengan filter line '1', sesi mesin line '2' harus tersaring.
        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, ['1']);
        $this->assertCount(1, $rows);
        $this->assertSame(4000, $rows[0]['total_qty_aktual']);
    }

    public function test_line_filter_narrows_mesin(): void
    {
        $b = $this->base(); // mesin line '1'
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, ['2']);
        $this->assertCount(1, $rows);
        $this->assertSame('2', $rows[0]['line']);
        $this->assertSame(999, $rows[0]['total_qty_aktual']);
    }

    public function test_line_filter_accepts_multiple_values(): void
    {
        $b = $this->base(); // mesin line '1'
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, ['1', '2']);
        $this->assertCount(2, $rows);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardServiceTest`
Expected: `test_line_filter_narrows_produksi` FAIL (asserts count 1 / qty 4000, gets count 1 / qty 4999 karena filter diabaikan — PHP tidak error untuk argumen keempat ekstra, cuma diabaikan). `test_line_filter_narrows_mesin` FAIL (assertCount(1, $rows) gagal, dapat 2 baris). `test_line_filter_accepts_multiple_values` PASS kebetulan (tanpa filter efektif pun hasilnya 2 baris) — abaikan, akan tervalidasi ulang di Step 4 karena filter beneran aktif.

- [ ] **Step 3: Implement — tambah parameter `$lines` di 3 method**

Di `app/Services/DashboardService.php`, ganti signature dan query di `getVcbProduksi`:

```php
    public function getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = []): array
    {
        $records = TransaksiProduksi::with(['karyawan', 'partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
            ->get();
```

`getVcbProduksiByDate`:

```php
    public function getVcbProduksiByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanId, ?int $shiftId = null, array $lines = []): array
    {
        $records = TransaksiProduksi::with(['partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('id_karyawan', $karyawanId)
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
            ->orderBy('tanggal_produksi')
            ->orderBy('jam_start')
            ->get();
```

`getVcbMesin`:

```php
    public function getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = []): array
    {
        $records = TransaksiProduksi::with(['mesin', 'partProses'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
            ->get();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardServiceTest`
Expected: PASS (semua test di file, termasuk 3 baru dan `test_shift_filter_narrows_produksi` yang lama — regression check).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app/Services/DashboardService.php tests/Unit/DashboardServiceTest.php
git add app/Services/DashboardService.php tests/Unit/DashboardServiceTest.php
git commit -m "feat(vcb): tambah parameter filter line di DashboardService

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Controllers — teruskan `line[]` dari request

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbProduksiController.php`
- Modify: `app/Http/Controllers/Vcb/VcbMesinController.php`
- Test: Create `tests/Feature/Vcb/VcbLineFilterTest.php`

**Interfaces:**
- Consumes: `DashboardService::getVcbProduksi/getVcbProduksiByDate/getVcbMesin(..., array $lines = [])` dari Task 1.
- Produces: `VcbProduksiController::index()` dan `VcbMesinController::index()` masing-masing kirim variabel Blade `$lineList` (Collection of string, distinct `master_mesin.line`, terurut) — dipakai Task 3 & 4. Endpoint `data()`/`operatorData()` kedua controller menerima query param `line[]` (array of string).

- [ ] **Step 1: Write the failing tests**

Buat file baru `tests/Feature/Vcb/VcbLineFilterTest.php`:

```php
<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter LINE (multi-select) di VCB Produksi (Mode 1 & 2) dan VCB Mesin.
 * `master_mesin.line` sudah ada sejak awal — filter ini query tambahan saja,
 * nol migrasi.
 */
class VcbLineFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    /**
     * Dua operator, masing-masing di mesin/line beda, tanggal sama —
     * dipakai lintas endpoint (Mode 1, Mode 2, VCB Mesin).
     */
    private function duaLine(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500]);

        $mesinA = MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mesinB = MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $opA = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $opB = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR B', 'departemen' => 'Produksi', 'is_aktif' => true]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $opA->id,
            'id_mesin' => $mesinA->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'created_by' => $this->admin->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $opB->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 2000, 'created_by' => $this->admin->id,
        ]);

        return compact('shift', 'proses', 'opA', 'opB', 'mesinA', 'mesinB');
    }

    public function test_vcb_produksi_data_disaring_oleh_line(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'line' => ['A'],
        ]))->json();

        $this->assertSame(['OPERATOR A'], $json['labels']);
    }

    public function test_vcb_produksi_data_tanpa_filter_line_menampilkan_semua(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
        ]))->json();

        $this->assertCount(2, $json['labels']);
    }

    public function test_vcb_produksi_operator_data_disaring_oleh_line(): void
    {
        $b = $this->duaLine();

        // Operator A dapat sesi tambahan di mesin B, tanggal beda.
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-21', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['opA']->id,
            'id_mesin' => $b['mesinB']->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 1000, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.produksi.operator-data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-21',
            'karyawan_id' => $b['opA']->id, 'line' => ['A'],
        ]))->json();

        $this->assertCount(1, $json['table']);
        $this->assertSame('20/08/26', $json['table'][0]['tanggal']);
    }

    public function test_vcb_mesin_data_disaring_oleh_line(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'line' => ['B'],
        ]))->json();

        $this->assertSame(['MESIN B'], $json['labels']);
    }

    public function test_vcb_mesin_data_menerima_multi_line(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'line' => ['A', 'B'],
        ]))->json();

        $this->assertCount(2, $json['labels']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VcbLineFilterTest`
Expected: `test_vcb_produksi_data_disaring_oleh_line` FAIL (dapat 2 label, bukan 1 — controller belum baca `line[]`). `test_vcb_produksi_operator_data_disaring_oleh_line` FAIL (dapat 2 baris table). `test_vcb_mesin_data_disaring_oleh_line` FAIL (dapat 2 label). `test_vcb_mesin_data_menerima_multi_line` PASS kebetulan (tanpa filter pun 2 baris) — abaikan, tervalidasi ulang di Step 4. `test_vcb_produksi_data_tanpa_filter_line_menampilkan_semua` PASS (behavior lama tidak berubah).

- [ ] **Step 3: Implement — controllers baca & teruskan `line[]`**

`app/Http/Controllers/Vcb/VcbProduksiController.php` — tambah import dan ubah `index()`, `data()`, `operatorData()`:

```php
use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbProduksiController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::whereRaw('LOWER(departemen) = ?', ['produksi'])
            ->orderBy('nama_karyawan')
            ->get();
        $lineList = MasterMesin::query()->distinct()->orderBy('line')->pluck('line')->filter()->values();

        return view('vcb.produksi', compact('shifts', 'karyawan', 'lineList'));
    }

    /**
     * Endpoint lama: summary per-operator (masih digunakan jika dibutuhkan).
     */
    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $lines = (array) $request->input('line', []);

        $rows = $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId, $lines);

        $labels = array_column($rows, 'karyawan');
        $achievements = array_column($rows, 'achievement_pct');
        $colors = array_map(fn ($r) => $this->trafficColor($r['traffic_light']), $rows);

        return response()->json([
            'labels' => $labels,
            'achievements' => $achievements,
            'colors' => $colors,
            'table' => $rows,
        ]);
    }

    /**
     * Endpoint baru: per-tanggal untuk satu operator spesifik.
     */
    public function operatorData(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $karyawanId = (int) $request->input('karyawan_id');
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $lines = (array) $request->input('line', []);

        if (! $karyawanId) {
            return response()->json(['error' => 'Pilih operator terlebih dahulu.'], 422);
        }

        $rows = $this->dashboardService->getVcbProduksiByDate($dateFrom, $dateTo, $karyawanId, $shiftId, $lines);

        $operator = MasterKaryawan::find($karyawanId)?->nama_karyawan ?? '—';

        return response()->json([
            'operator' => $operator,
            'labels' => array_column($rows, 'tanggal'),
            'achievements' => array_column($rows, 'achievement_pct'),
            'colors' => array_map(fn ($r) => $this->trafficHex($r['traffic_light']), $rows),
            'table' => $rows,
        ]);
    }
```

(sisa method `trafficColor`/`trafficHex` tidak berubah.)

`app/Http/Controllers/Vcb/VcbMesinController.php` — tambah import dan ubah `index()`, `data()`:

```php
use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbMesinController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $lineList = MasterMesin::query()->distinct()->orderBy('line')->pluck('line')->filter()->values();

        return view('vcb.mesin', compact('shifts', 'lineList'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $lines = (array) $request->input('line', []);

        $rows = $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId, $lines);
```

(sisa `data()` — pembangunan `labels`/`targets`/`actuals`/`colors`/response JSON — tidak berubah.)

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=VcbLineFilterTest`
Expected: PASS (semua 5 test).

Run juga: `php artisan test --filter=Vcb` (regression seluruh test VCB lama)
Expected: PASS.

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app/Http/Controllers/Vcb tests/Feature/Vcb/VcbLineFilterTest.php
git add app/Http/Controllers/Vcb/VcbProduksiController.php app/Http/Controllers/Vcb/VcbMesinController.php tests/Feature/Vcb/VcbLineFilterTest.php
git commit -m "feat(vcb): controller VCB Produksi & Mesin terima filter line[]

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: View VCB Mesin — checkbox Line

**Files:**
- Modify: `resources/views/vcb/mesin.blade.php:38-50` (dock, tambah grup setelah Shift), `:113-118` (JS `loadData()`)
- Test: Modify `tests/Feature/Vcb/VcbLineFilterTest.php`

**Interfaces:**
- Consumes: `$lineList` (Collection of string) dari `VcbMesinController::index()` (Task 2).

- [ ] **Step 1: Write the failing test**

Tambahkan di `tests/Feature/Vcb/VcbLineFilterTest.php`, sebelum `}` penutup class:

```php
    public function test_vcb_mesin_menampilkan_checkbox_line_dari_master_data(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString('class="line-cb" value="A"', $html);
        $this->assertStringContainsString('class="line-cb" value="B"', $html);
        $this->assertStringContainsString("params.append('line[]'", $html);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_vcb_mesin_menampilkan_checkbox_line_dari_master_data`
Expected: FAIL — `class="line-cb"` tidak ditemukan di HTML.

- [ ] **Step 3: Implement — tambah checkbox group & JS**

Di `resources/views/vcb/mesin.blade.php`, sisipkan grup baru setelah grup Shift (baris 42-50), sebelum grup tombol submit:

```html
        <div class="erp-grp">
            <b>Shift</b>
            <select id="shift_id" name="shift_id" class="erp-in">
                <option value="">Semua Shift</option>
                @foreach($shifts as $shift)
                    <option value="{{ $shift->id }}">{{ $shift->nama_shift }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-grp">
            <b>Line</b>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach($lineList as $l)
                <label class="erp-chk">
                    <input type="checkbox" class="line-cb" value="{{ $l }}">
                    {{ $l }}
                </label>
                @endforeach
            </div>
        </div>
        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-search"></i>Tampilkan</button>
        </div>
```

Di JS `loadData()` (baris 113-118), tambah baris pengumpul checkbox setelah `URLSearchParams` dibangun:

```js
async function loadData() {
    const params = new URLSearchParams({
        date_from: document.getElementById('date_from').value,
        date_to:   document.getElementById('date_to').value,
        shift_id:  document.getElementById('shift_id').value,
    });
    document.querySelectorAll('.line-cb:checked').forEach(cb => params.append('line[]', cb.value));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=VcbLineFilterTest`
Expected: PASS (6 test, termasuk yang baru).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/Vcb/VcbLineFilterTest.php
git add resources/views/vcb/mesin.blade.php tests/Feature/Vcb/VcbLineFilterTest.php
git commit -m "feat(vcb-mesin): tambah checkbox filter line di dock

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: View VCB Produksi — checkbox Line di kedua form

**Files:**
- Modify: `resources/views/vcb/produksi.blade.php:52-60` (formAll, tambah grup setelah Shift), `:93-101` (formOne, tambah grup setelah Shift), `:505-510` (JS `loadAllOperators()`), `:710-719` (JS `loadOneOperator()`)
- Test: Modify `tests/Feature/Vcb/VcbLineFilterTest.php`

**Interfaces:**
- Consumes: `$lineList` (Collection of string) dari `VcbProduksiController::index()` (Task 2).

- [ ] **Step 1: Write the failing test**

Tambahkan di `tests/Feature/Vcb/VcbLineFilterTest.php`, sebelum `}` penutup class:

```php
    public function test_vcb_produksi_menampilkan_checkbox_line_di_kedua_form(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.produksi'))->assertOk()->getContent();

        $this->assertStringContainsString('class="all-line-cb" value="A"', $html);
        $this->assertStringContainsString('class="one-line-cb" value="A"', $html);
        $this->assertStringContainsString("querySelectorAll('.all-line-cb:checked')", $html);
        $this->assertStringContainsString("querySelectorAll('.one-line-cb:checked')", $html);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_vcb_produksi_menampilkan_checkbox_line_di_kedua_form`
Expected: FAIL — `class="all-line-cb"` tidak ditemukan.

- [ ] **Step 3: Implement — tambah checkbox group di kedua form & JS**

Di `resources/views/vcb/produksi.blade.php`, `formAll` — sisipkan grup baru setelah grup Shift (baris 52-60), sebelum grup tombol submit:

```html
            <div class="erp-grp">
                <b>Shift</b>
                <select id="all_shift_id" class="erp-in">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Line</b>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    @foreach($lineList as $l)
                    <label class="erp-chk">
                        <input type="checkbox" class="all-line-cb" value="{{ $l }}">
                        {{ $l }}
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri">
                    <i class="bi bi-funnel me-1"></i>Tampilkan
                </button>
            </div>
```

`formOne` — sisipkan grup baru setelah grup Shift (baris 93-101), sebelum grup tombol submit/export:

```html
            <div class="erp-grp">
                <b>Shift</b>
                <select id="one_shift_id" class="erp-in">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Line</b>
                <div style="display:flex;flex-wrap:wrap;gap:8px">
                    @foreach($lineList as $l)
                    <label class="erp-chk">
                        <input type="checkbox" class="one-line-cb" value="{{ $l }}">
                        {{ $l }}
                    </label>
                    @endforeach
                </div>
            </div>
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                @if(! auth()->user()->isGuest())
                <a id="oneExportBtn" href="#" class="erp-btn">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export
                </a>
                @endif
            </div>
```

Di JS `loadAllOperators()` (baris 505-510), tambah pengumpul checkbox:

```js
async function loadAllOperators() {
    const params = new URLSearchParams({
        date_from: document.getElementById('all_date_from').value,
        date_to:   document.getElementById('all_date_to').value,
        shift_id:  document.getElementById('all_shift_id').value,
    });
    document.querySelectorAll('.all-line-cb:checked').forEach(cb => params.append('line[]', cb.value));
```

Di JS `loadOneOperator()` (baris 710-719), tambah pengumpul checkbox:

```js
async function loadOneOperator() {
    const karyawanId = document.getElementById('one_karyawan_id').value;
    if (!karyawanId) { alert('Pilih operator terlebih dahulu.'); return; }

    const params = new URLSearchParams({
        date_from:   document.getElementById('one_date_from').value,
        date_to:     document.getElementById('one_date_to').value,
        karyawan_id: karyawanId,
        shift_id:    document.getElementById('one_shift_id').value,
    });
    document.querySelectorAll('.one-line-cb:checked').forEach(cb => params.append('line[]', cb.value));
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=VcbLineFilterTest`
Expected: PASS (7 test, semua).

Run juga full regression: `php artisan test`
Expected: PASS (seluruh suite, termasuk `VcbTanggalDanDrilldownTest` dan `VcbAchievedCounterTest` yang membaca HTML halaman ini).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/Vcb/VcbLineFilterTest.php
git add resources/views/vcb/produksi.blade.php tests/Feature/Vcb/VcbLineFilterTest.php
git commit -m "feat(vcb-produksi): tambah checkbox filter line di Mode 1 & Mode 2

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Verifikasi Manual (Browser)

Setelah 4 task selesai, sebelum PR:

1. Login sebagai admin, buka **VCB Mesin** — buka dock filter, pastikan checkbox Line muncul sesuai isi Master > Mesin. Centang satu line, klik Tampilkan, pastikan tabel & grafik cuma nampilin mesin di line itu.
2. Buka **VCB Produksi** — cek dock di Mode 1 (Semua Operator) dan Mode 2 (Per Operator), checkbox Line muncul di dua-duanya, filter jalan di masing-masing mode.
3. Centang 2+ line sekaligus, pastikan data gabungan (bukan cuma line pertama).
4. Kosongkan semua centang, pastikan kembali nampilin semua data (no filter).
