<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;

class VcbDandoriController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $mesin  = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        $weeks  = ['WEEK 1','WEEK 2','WEEK 3','WEEK 4','WEEK 5'];
        return view('vcb.dandori', compact('shifts', 'mesin', 'months', 'weeks'));
    }

    public function data(Request $request)
    {
        $bulan   = $request->input('bulan') ?: null;
        $week    = $request->input('week') ?: null;
        $mesinId = $request->filled('mesin_id') ? (int) $request->input('mesin_id') : null;
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $result    = $this->dashboardService->getVcbDandori($bulan, $week, $mesinId, $shiftId);
        $rows      = $result['rows'];
        $jenisList = $result['jenis_list'];

        $labels  = array_column($rows, 'mesin');
        $colors  = $this->generateColors(count($jenisList));
        $datasets = $datasetsOver = [];

        foreach ($jenisList as $i => $jenis) {
            $datasets[]     = ['label' => $jenis, 'data' => array_map(fn($r) => $r[$jenis . '_loss'] ?? 0, $rows), 'backgroundColor' => $colors[$i], 'stack' => 'loss'];
            $datasetsOver[] = ['label' => $jenis, 'data' => array_map(fn($r) => $r[$jenis . '_over'] ?? 0, $rows), 'backgroundColor' => $colors[$i], 'stack' => 'over'];
        }

        return response()->json([
            'labels'        => $labels,
            'datasets_loss' => $datasets,
            'datasets_over' => $datasetsOver,
            'jenis_list'    => $jenisList,
            'table'         => $rows,
        ]);
    }

    private function generateColors(int $count): array
    {
        $palette = [
            'rgba(239,68,68,0.75)', 'rgba(249,115,22,0.75)', 'rgba(234,179,8,0.75)',
            'rgba(34,197,94,0.75)', 'rgba(6,182,212,0.75)',  'rgba(99,102,241,0.75)',
            'rgba(168,85,247,0.75)','rgba(236,72,153,0.75)', 'rgba(156,163,175,0.75)',
        ];
        return array_map(fn($i) => $palette[$i % count($palette)], range(0, $count - 1));
    }
}
