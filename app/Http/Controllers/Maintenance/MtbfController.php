<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Maintenance\Concerns\ResolvesMaintenanceRange;
use App\Models\MasterMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;

class MtbfController extends Controller
{
    use ResolvesMaintenanceRange;

    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.mtbf', [
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'tipeList'  => MasterMesin::TIPE_MESIN,
        ]);
    }

    public function data(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $mesinId   = (int) $request->input('mesin_id', 0) ?: null;
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows      = $this->svc->compute($from, $to, $mesinId, $tipeMesin);

        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'avg_mtbf' => round((float) collect($rows)->whereNotNull('mtbf_jam')->avg('mtbf_jam'), 2),
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }
}
