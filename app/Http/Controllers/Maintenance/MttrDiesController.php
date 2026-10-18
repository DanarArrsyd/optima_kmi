<?php

namespace App\Http\Controllers\Maintenance;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Maintenance\Concerns\TrenBulananMaintenance;
use App\Models\MasterPart;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class MttrDiesController extends Controller
{
    use TrenBulananMaintenance;

    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.mttr-dies', ['partList' => MasterPart::orderBy('part_no')->get(), 'customers' => MasterPart::whereNotNull('customer')->where('customer', '!=', '')->distinct()->orderBy('customer')->pluck('customer')]);
    }

    /** Export Excel — baris diambil dari data() yang sama dengan tabel layar. */
    public function export(Request $request)
    {
        $baris = array_map(fn ($r) => [$r['kode_mesin'], $r['nama_mesin'], $r['nama_proses'], $r['count'], $r['sum_repair'], $r['mttr_jam']], $this->data($request)->getData(true)['rows']);

        return Excel::download(new TabelExport(['Part No', 'Nama Dies', 'Proses', 'Breakdown', 'Total Repair (jam)', 'MTTR (jam)'], $baris, 'MTTR Dies'), 'mttr-dies-'.now()->format('Ymd').'.xlsx');
    }

    public function data(Request $request)
    {
        [$from, $to] = $this->rentang($request);
        $rows = $this->penghitung($request)($from, $to);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'avg_mttr' => $this->rataMttr($rows) ?? 0.0,
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }

    /** Tren bulanan rata-rata metrik untuk grafik kiri; filter sama dengan data(). */
    public function tren(Request $request)
    {
        [$from, $to] = $this->rentang($request);

        return response()->json(['tren' => $this->trenBulanan($from, $to, $this->penghitung($request))]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rentang(Request $request): array
    {
        return [
            Carbon::parse($request->input('date_from', date('Y-01-01'))),
            Carbon::parse($request->input('date_to', date('Y-m-d'))),
        ];
    }

    /** compute() dengan filter part/customer dari request, untuk rentang mana pun. */
    private function penghitung(Request $request): \Closure
    {
        $partId = (int) $request->input('part_id', 0) ?: null;
        $customer = trim((string) $request->input('customer', '')) ?: null;

        return fn (Carbon $from, Carbon $to) => $this->svc->compute($from, $to, $partId, $customer);
    }
}
