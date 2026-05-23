<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbProduksiController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        return view('vcb.produksi', compact('shifts'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows = $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId);

        $labels       = array_column($rows, 'karyawan');
        $targets      = array_column($rows, 'total_target_qty');
        $actuals      = array_column($rows, 'total_qty_aktual');
        $achievements = array_column($rows, 'achievement_pct');
        $colors       = array_map(fn($r) => $this->trafficColor($r['traffic_light']), $rows);

        return response()->json([
            'labels'       => $labels,
            'datasets'     => [
                ['label' => 'Target QTY',  'data' => $targets, 'backgroundColor' => 'rgba(99,102,241,0.6)'],
                ['label' => 'Actual QTY',  'data' => $actuals, 'backgroundColor' => $colors],
            ],
            'table'        => $rows,
            'achievements' => $achievements,
        ]);
    }

    private function trafficColor(string $light): string
    {
        return match($light) {
            'success' => 'rgba(34,197,94,0.8)',
            'warning' => 'rgba(234,179,8,0.8)',
            default   => 'rgba(239,68,68,0.8)',
        };
    }
}
