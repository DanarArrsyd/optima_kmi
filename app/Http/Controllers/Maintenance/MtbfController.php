<?php

namespace App\Http\Controllers\Maintenance;

use App\Exports\TabelExport;
use App\Http\Controllers\Concerns\BacaFilterMesin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Maintenance\Concerns\ResolvesMaintenanceRange;
use App\Http\Controllers\Maintenance\Concerns\TrenBulananMaintenance;
use App\Models\MasterMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class MtbfController extends Controller
{
    use BacaFilterMesin, ResolvesMaintenanceRange, TrenBulananMaintenance;

    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.mtbf', [
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'tipeList' => MasterMesin::TIPE_MESIN,
        ]);
    }

    /** Export Excel — baris diambil dari data() yang sama dengan tabel layar. */
    public function export(Request $request)
    {
        $baris = array_map(fn ($r) => [$r['kode_mesin'], $r['nama_mesin'], $r['count'], $r['mtbf_jam'], $r['mtbf_hari']], $this->data($request)->getData(true)['rows']);

        return Excel::download(new TabelExport(['Mesin', 'Nama Mesin', 'Breakdown', 'MTBF (jam kerja)', 'MTBF (hari kerja)'], $baris, 'MTBF Mesin'), 'mtbf-mesin-'.now()->format('Ymd').'.xlsx');
    }

    public function data(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $rows = $this->penghitung($request)($from, $to);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'avg_mtbf' => $this->rataMtbf($rows) ?? 0.0,
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }

    /** Tren bulanan rata-rata metrik untuk grafik kiri; filter sama dengan data(). */
    public function tren(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);

        return response()->json(['tren' => $this->trenBulanan($from, $to, $this->penghitung($request))]);
    }

    /** compute() dengan filter mesin/tipe dari request, untuk rentang mana pun. */
    private function penghitung(Request $request): \Closure
    {
        $mesinId = $this->mesinIds($request, 'mesin_id');
        $tipeMesin = $request->input('tipe_mesin') ?: null;

        return fn (Carbon $from, Carbon $to) => $this->svc->compute($from, $to, $mesinId, $tipeMesin);
    }
}
