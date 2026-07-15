# Design: Control Stroke Mesin & Dies

**Tanggal:** 2026-07-15
**Status:** Approved (brainstorming)
**Project:** OPTIMA MES — Laravel 13

## Konteks

Butuh monitoring kumulatif **stroke** (siklus tekan press) per Mesin dan per Dies, dihitung
dari LHP Produksi (`TransaksiProduksi`) yang sudah diinput. Contoh dari user:

```
Mesin A : 300 Stroke, 150 Stroke, 200 Stroke = 650 Stroke
Dies B  : 300 Stroke, 80 Stroke, 70 Stroke   = 450 Stroke
```

**1 stroke = 1 `qty_aktual`** (1 pcs part yang diproduksi). Tidak ada pengali lain — dikonfirmasi
user, tidak ada field stroke di schema saat ini yang perlu ditambah.

## Scope

2 halaman baru, bukan section nempel di Availability existing:

- `/maintenance/availability/control-stroke-mesin` — Total Stroke per Mesin
- `/maintenance/availability/control-stroke-dies` — Total Stroke per Dies (part+proses)

Diakses via tombol/link dari halaman Availability Mesin & Availability Dies existing.
Role sama: `admin, staff_maintenance, manager`.

**Rentang stroke**: filter tanggal (`date_from`/`date_to`/`bulan`), sama pola VCB/Maintenance
existing. Bukan running-total all-time, bukan fitur reset counter.

**Tampilan**: tabel + bar chart. **Tanpa threshold/traffic-light warna** — polos, karena belum
ada field target stroke di schema (`kapasitas_max_stroke` itu throughput/jam, konsep beda, jangan
dipakai sebagai threshold).

Out of scope (bisa nyusul fase 2 kalau dibutuhkan): kolom target stroke, warna
hijau/kuning/merah, reset counter after service.

## Data & Perhitungan

Sumber: `TransaksiProduksi` (fillable sudah punya `id_mesin`, `id_part_proses`, `qty_aktual`,
`tanggal_produksi`).

**Stroke per Mesin**: `SUM(qty_aktual)` dari `TransaksiProduksi` dalam rentang tanggal,
`GROUP BY id_mesin`.

**Stroke per Dies**: `SUM(qty_aktual)` dari `TransaksiProduksi` dalam rentang tanggal,
`GROUP BY id_part_proses` (unit "dies" di project ini = part+proses, sama seperti
`MaintenanceDiesDashboardService` — 1 part bisa punya banyak proses/dies).

Tidak butuh tabel/kolom baru — murni query agregat on-the-fly, pola sama seperti
`MaintenanceDashboardService::compute()` dan `MaintenanceDiesDashboardService::compute()`.

## Service

Tambah method baru ke service existing (bukan service baru — reuse constructor/DI yang
sudah ada di controller Availability):

`app/Services/MaintenanceDashboardService.php`:
```php
public function computeStroke(Carbon $from, Carbon $to, ?int $mesinId = null, ?string $tipeMesin = null): array
{
    $hasTipe = Schema::hasColumn('master_mesin', 'tipe_mesin');

    $mesins = MasterMesin::when($mesinId, fn ($q) => $q->where('id', $mesinId))
        ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
        ->orderBy('id_mesin')->get();

    $stats = TransaksiProduksi::query()
        ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
        ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
        ->selectRaw('id_mesin, SUM(qty_aktual) as total_stroke')
        ->groupBy('id_mesin')
        ->pluck('total_stroke', 'id_mesin');

    return $mesins->map(fn ($m) => [
        'id_mesin'     => $m->id,
        'kode_mesin'   => $m->id_mesin,
        'nama_mesin'   => $m->nama_mesin,
        'total_stroke' => (int) ($stats->get($m->id) ?? 0),
    ])->values()->all();
}
```

`app/Services/MaintenanceDiesDashboardService.php`:
```php
public function computeStroke(Carbon $from, Carbon $to, ?int $partId = null, ?string $customer = null): array
{
    $partProsesList = MasterPartProses::with('part')
        ->when($partId, fn ($q) => $q->where('id_part', $partId))
        ->when($customer, fn ($q) => $q->whereHas('part', fn ($p) => $p->where('customer', $customer)))
        ->get()
        ->sortBy(fn ($pr) => $pr->part->part_no ?? '');

    $stats = TransaksiProduksi::query()
        ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
        ->when($partId, fn ($q) => $q->whereHas('partProses', fn ($p) => $p->where('id_part', $partId)))
        ->selectRaw('id_part_proses, SUM(qty_aktual) as total_stroke')
        ->groupBy('id_part_proses')
        ->pluck('total_stroke', 'id_part_proses');

    $rows = [];
    foreach ($partProsesList as $pr) {
        $total = (int) ($stats->get($pr->id) ?? 0);
        if ($total === 0) {
            continue; // hindari list ribuan baris kosong, sama pola compute() existing
        }
        $rows[] = [
            'id_mesin'     => $pr->id,
            'kode_mesin'   => $pr->part?->part_no,
            'nama_mesin'   => $pr->part?->part_name,
            'nama_proses'  => $pr->nama_proses,
            'total_stroke' => $total,
        ];
    }

    return $rows;
}
```

`TransaksiProduksi` butuh `belongsTo(MasterPartProses::class, 'id_part_proses')` relasi
`partProses()` — sudah ada (dipakai `getSphAktualAttribute()`), tinggal reuse.

## Routes

```
Route::middleware('role:admin,staff_maintenance,manager')->prefix('maintenance')->name('maintenance.')->group:
  GET  availability/control-stroke-mesin        ControlStrokeMesinController@index
  GET  availability/control-stroke-mesin/data   ControlStrokeMesinController@data   (AJAX)
  GET  availability/control-stroke-dies         ControlStrokeDiesController@index
  GET  availability/control-stroke-dies/data    ControlStrokeDiesController@data    (AJAX)
```

Route name: `maintenance.control-stroke-mesin`, `maintenance.control-stroke-mesin.data`,
`maintenance.control-stroke-dies`, `maintenance.control-stroke-dies.data`.

## Controllers

`app/Http/Controllers/Maintenance/ControlStrokeMesinController.php` — pola identik
`AvailabilityController`, ganti `$svc->compute()` → `$svc->computeStroke()`:

```php
class ControlStrokeMesinController extends Controller
{
    use ResolvesMaintenanceRange;

    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.control-stroke-mesin', [
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'tipeList'  => MasterMesin::TIPE_MESIN,
        ]);
    }

    public function data(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $mesinId   = (int) $request->input('mesin_id', 0) ?: null;
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows      = $this->svc->computeStroke($from, $to, $mesinId, $tipeMesin);

        return response()->json([
            'rows'    => $rows,
            'summary' => ['total_stroke' => (int) collect($rows)->sum('total_stroke')],
        ]);
    }
}
```

`app/Http/Controllers/Maintenance/ControlStrokeDiesController.php` — pola identik
`AvailabilityDiesController`, ganti `$svc->compute()` → `$svc->computeStroke()`, `date_from`/
`date_to` langsung `Carbon::parse` (dies controller existing tidak pakai `ResolvesMaintenanceRange`
trait, ikuti pola aslinya biar konsisten).

## Views

`resources/views/maintenance/control-stroke-mesin.blade.php` — clone struktur
`maintenance/availability.blade.php`:
- Judul: "Control Stroke Mesin" (icon `bi-arrow-repeat` atau serupa)
- Filter form identik (tanggal dari/sampai, bulan, tipe mesin, autocomplete mesin)
- KPI card tunggal: "Total Stroke" (bukan 2 card avail/breakdown)
- 1 chart bar: stroke per mesin, warna monokrom `#1f2937` (bukan `availColor` — no threshold)
- Tabel: `Mesin | Nama Mesin | Total Stroke`

`resources/views/maintenance/control-stroke-dies.blade.php` — clone struktur
`maintenance/availability-dies.blade.php`, sama treatment:
- Filter: tanggal, customer, part
- KPI: Total Stroke
- Chart bar per dies, tabel: `Part No | Nama Dies | Proses | Total Stroke`

Reuse `taField()` Alpine component (copy-paste sama seperti pola existing per-file, project
belum extract komponen JS bersama antar view maintenance).

## Nav

`resources/views/layouts/app.blade.php` — di grup MTC Mesin (sekitar baris 1041-1049) tambah:
```html
<a href="{{ route('maintenance.control-stroke-mesin') }}" class="nav-item-link {{ $vcbActive==='mtcmesin' && str_starts_with($uri,'maintenance/availability/control-stroke-mesin')?'active':'' }}">
    <i class="bi bi-arrow-repeat"></i> Control Stroke
</a>
```
Di grup MTC Dies (sekitar baris 1061-1069) tambah padanan untuk `control-stroke-dies`.

Juga tambah tombol link pintas di header halaman `availability.blade.php` /
`availability-dies.blade.php` masing-masing menuju halaman stroke barunya (opsional, kalau
nav sidebar sudah cukup boleh skip — keputusan implementer, bukan blocking).

## Testing

phpunit (pola `MaintenanceDashboardServiceTest` kalau ada, atau feature test baru):
- `computeStroke()` Mesin: jumlah `qty_aktual` benar per mesin, filter tanggal mempersempit
  hasil, filter `mesin_id`/`tipe_mesin` benar, mesin tanpa transaksi → `total_stroke = 0`.
- `computeStroke()` Dies: jumlah `qty_aktual` benar per part+proses, filter `part_id`/
  `customer` benar, part+proses tanpa transaksi di-skip dari hasil (bukan `total_stroke: 0`
  row, sama pola `compute()` existing yang skip baris kosong).
- Role gate: `staff_produksi`/`staff_qc` tidak bisa akses 4 route baru ini.
- Route `data()` mengembalikan JSON `rows` + `summary.total_stroke` sesuai SUM semua rows.

## Catatan implementasi

- **Tidak** ada migration baru — murni query agregat dari tabel `transaksi_produksi` yang
  sudah ada.
- **Tidak** menyentuh `MasterMesin.kapasitas_max_stroke` — field itu throughput/jam, biarkan
  sebagaimana adanya, jangan dicampur dengan `total_stroke` kumulatif.
- Controller baru butuh `use App\Models\TransaksiProduksi;` import di kedua service file.
