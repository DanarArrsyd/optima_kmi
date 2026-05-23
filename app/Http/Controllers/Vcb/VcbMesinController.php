<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
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
        return view('vcb.mesin', compact('shifts'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows = $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId);

        $labels  = array_column($rows, 'mesin');
        $targets = array_column($rows, 'total_target_qty');
        $actuals = array_column($rows, 'total_qty_aktual');
        $colors  = array_map(fn($r) => match($r['traffic_light']) {
            'success' => 'rgba(34,197,94,0.8)',
            'warning' => 'rgba(234,179,8,0.8)',
            default   => 'rgba(239,68,68,0.8)',
        }, $rows);

        return response()->json([
            'labels'       => $labels,
            'datasets'     => [
                ['label' => 'Target QTY', 'data' => $targets, 'backgroundColor' => 'rgba(99,102,241,0.6)'],
                ['label' => 'Actual QTY', 'data' => $actuals, 'backgroundColor' => $colors],
            ],
            'table'        => $rows,
            'achievements' => array_column($rows, 'achievement_pct'),
        ]);
    }
}
