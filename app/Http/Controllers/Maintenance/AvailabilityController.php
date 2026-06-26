<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class AvailabilityController extends Controller
{
    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.availability', ['mesinList' => MasterMesin::orderBy('id_mesin')->get()]);
    }

    public function data(Request $request)
    {
        $from    = Carbon::parse($request->input('date_from', date('Y-01-01')));
        $to      = Carbon::parse($request->input('date_to', date('Y-m-d')));
        $mesinId = (int) $request->input('mesin_id', 0) ?: null;
        $rows    = $this->svc->compute($from, $to, $mesinId);

        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'avg_availability' => round((float) collect($rows)->whereNotNull('availability_pct')->avg('availability_pct'), 2),
            ],
        ]);
    }
}
