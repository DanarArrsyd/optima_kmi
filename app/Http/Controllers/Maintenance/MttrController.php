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
                'avg_mttr' => round((float) $withData->avg('mttr_jam'), 2),
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }
}
