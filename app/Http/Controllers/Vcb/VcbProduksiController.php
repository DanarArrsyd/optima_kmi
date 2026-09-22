<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
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
        $karyawan = MasterKaryawan::whereRaw('LOWER(departemen) = ?', ['produksi'])
            ->orderBy('nama_karyawan')
            ->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();

        return view('vcb.produksi', compact('shifts', 'karyawan', 'mesin'));
    }

    /**
     * Endpoint lama: summary per-operator (masih digunakan jika dibutuhkan).
     */
    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        $rows = $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId, $mesinIds);

        $labels = array_column($rows, 'karyawan');
        $achievements = array_column($rows, 'achievement_pct');
        $colors = array_map(fn ($r) => $this->trafficColor($r['traffic_light']), $rows);

        return response()->json([
            'labels' => $labels,
            'achievements' => $achievements,
            'colors' => $colors,
            'table' => $rows,
        ]);
    }

    /**
     * Endpoint baru: per-tanggal untuk satu operator spesifik.
     */
    public function operatorData(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $karyawanId = (int) $request->input('karyawan_id');
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        if (! $karyawanId) {
            return response()->json(['error' => 'Pilih operator terlebih dahulu.'], 422);
        }

        $rows = $this->dashboardService->getVcbProduksiByDate($dateFrom, $dateTo, $karyawanId, $shiftId, $mesinIds);

        $operator = MasterKaryawan::find($karyawanId)?->nama_karyawan ?? '—';

        return response()->json([
            'operator' => $operator,
            'labels' => array_column($rows, 'tanggal'),
            'achievements' => array_column($rows, 'achievement_pct'),
            'colors' => array_map(fn ($r) => $this->trafficHex($r['traffic_light']), $rows),
            'table' => $rows,
        ]);
    }

    private function trafficColor(string $light): string
    {
        return match ($light) {
            'info' => 'rgba(29,78,216,0.8)',   // >100% = melebihi target (biru)
            'success' => 'rgba(34,197,94,0.8)',
            'warning' => 'rgba(234,179,8,0.8)',
            default => 'rgba(239,68,68,0.8)',
        };
    }

    private function trafficHex(string $light): string
    {
        return match ($light) {
            'info' => '#1d4ed8',   // >100% = melebihi target (biru), bukan merah
            'success' => '#15803d',
            'warning' => '#a16207',
            default => '#b91c1c',
        };
    }
}
