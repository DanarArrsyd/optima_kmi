<?php

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Concerns\BacaFilterMesin;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Maintenance\Concerns\ResolvesMaintenanceRange;
use App\Models\MasterMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    use BacaFilterMesin, ResolvesMaintenanceRange;

    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.availability', [
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'tipeList' => MasterMesin::TIPE_MESIN,
        ]);
    }

    public function data(Request $request)
    {
        [$from, $to] = $this->resolveRange($request);
        $mesinId = $this->mesinIds($request, 'mesin_id');
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows = $this->svc->compute($from, $to, $mesinId, $tipeMesin);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'avg_availability' => round((float) collect($rows)->whereNotNull('availability_pct')->avg('availability_pct'), 2),
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }
}
