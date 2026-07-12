<?php
namespace App\Http\Controllers\Vcb;
use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbQualityController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::whereRaw('LOWER(departemen) = ?', ['qc'])
            ->orderBy('nama_karyawan')
            ->get();

        return view('vcb.quality', compact('shifts', 'karyawan'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows         = $this->dashboardService->getVcbQuality($dateFrom, $dateTo, $shiftId);
        $labels       = array_column($rows, 'karyawan');
        $achievements = array_column($rows, 'achievement_pct');
        $colors       = array_map(fn($r) => $this->trafficHex($r['traffic_light']), $rows);

        return response()->json([
            'labels'       => $labels,
            'achievements' => $achievements,
            'colors'       => $colors,
            'table'        => $rows,
        ]);
    }

    public function operatorData(Request $request)
    {
        $dateFrom      = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo        = Carbon::parse($request->input('date_to', today()->toDateString()));
        $karyawanQcId  = (int) $request->input('karyawan_id');
        $shiftId       = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        if (!$karyawanQcId) {
            return response()->json(['error' => 'Pilih inspector terlebih dahulu.'], 422);
        }

        $rows      = $this->dashboardService->getVcbQualityByDate($dateFrom, $dateTo, $karyawanQcId, $shiftId);
        $inspector = MasterKaryawan::find($karyawanQcId)?->nama_karyawan ?? '—';

        return response()->json([
            'inspector'    => $inspector,
            'labels'       => array_column($rows, 'tanggal'),
            'achievements' => array_column($rows, 'achievement_pct'),
            'colors'       => array_map(fn($r) => $this->trafficHex($r['traffic_light']), $rows),
            'table'        => $rows,
        ]);
    }

    private function trafficHex(string $light): string
    {
        return match($light) {
            'info'    => '#1d4ed8',   // >100% = melebihi target (biru), bukan merah
            'success' => '#15803d',
            'warning' => '#a16207',
            default   => '#b91c1c',
        };
    }
}
