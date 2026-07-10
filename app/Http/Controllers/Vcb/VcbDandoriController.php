<?php
namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Exports\LhpDandoriExport;
use App\Models\MasterMesin;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class VcbDandoriController extends Controller
{
    public function __construct(private DashboardService $svc) {}

    public function index()
    {
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        return view('vcb.dandori', compact('mesin'));
    }

    public function data(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        $data = $this->svc->getVcbDandori($dateFrom, $dateTo, array_values($mesinIds));

        return response()->json($data);
    }

    public function export(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));
        $filename = 'VCB_Dandori_' . $dateFrom . '_' . $dateTo . '.xlsx';

        return Excel::download(new LhpDandoriExport($dateFrom, $dateTo), $filename);
    }
}
