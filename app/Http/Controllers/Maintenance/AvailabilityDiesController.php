<?php

namespace App\Http\Controllers\Maintenance;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Models\MasterPart;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class AvailabilityDiesController extends Controller
{
    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.availability-dies', ['partList' => MasterPart::orderBy('part_no')->get(), 'customers' => MasterPart::whereNotNull('customer')->where('customer', '!=', '')->distinct()->orderBy('customer')->pluck('customer')]);
    }

    /** Export Excel — baris diambil dari data() yang sama dengan tabel layar. */
    public function export(Request $request)
    {
        $baris = array_map(fn ($r) => [$r['kode_mesin'], $r['nama_mesin'], $r['nama_proses'], $r['count'], $r['sum_downtime'], $r['availability_pct']], $this->data($request)->getData(true)['rows']);

        return Excel::download(new TabelExport(['Part No', 'Nama Dies', 'Proses', 'Breakdown', 'Total Downtime (jam)', 'Availability %'], $baris, 'Availability Dies'), 'availability-dies-'.now()->format('Ymd').'.xlsx');
    }

    public function data(Request $request)
    {
        $from = Carbon::parse($request->input('date_from', date('Y-01-01')));
        $to = Carbon::parse($request->input('date_to', date('Y-m-d')));
        $partId = (int) $request->input('part_id', 0) ?: null;
        $customer = trim((string) $request->input('customer', '')) ?: null;
        $rows = $this->svc->compute($from, $to, $partId, $customer);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'avg_availability' => round((float) collect($rows)->whereNotNull('availability_pct')->avg('availability_pct'), 2),
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }
}
