<?php

namespace App\Http\Controllers\Vcb;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class VcbMesinController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();

        return view('vcb.mesin', compact('shifts', 'mesin'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        $rows = $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId, $mesinIds);

        $labels = array_column($rows, 'mesin');
        $targets = array_column($rows, 'total_target_qty');
        $actuals = array_column($rows, 'total_qty_aktual');

        /*
         * Warna dari achievement_pct langsung, SAMA PERSIS dengan badge tabel
         * di bawah grafik ini (>100 biru, >=100 hijau, sisanya merah) — BUKAN
         * dari traffic_light. Field itu cuma berguna dua nilai nyata (success/
         * info) karena 'warning' nol pernah dikembalikan getTrafficLight(),
         * dan map lama menjatuhkan 'info' (mesin di ATAS target) ke cabang
         * default merah — mesin yang justru melebihi target tampil semerah
         * yang di bawah target. Achievement_pct sendiri sudah cukup, satu
         * sumber kebenaran, sama seperti badge tabel.
         */
        $colors = array_map(fn ($r) => match (true) {
            $r['achievement_pct'] > 100 => '#1d4ed8',
            $r['achievement_pct'] >= 100 => '#15803d',
            default => '#b91c1c',
        }, $rows);

        return response()->json([
            'labels' => $labels,
            'datasets' => [
                [
                    'type' => 'bar',
                    'label' => 'Actual QTY',
                    'data' => $actuals,
                    'backgroundColor' => $colors,
                    'order' => 2,
                ],
                [
                    'type' => 'line',
                    'label' => 'Target QTY',
                    'data' => $targets,
                    'borderColor' => '#374151',
                    'backgroundColor' => '#374151',
                    'pointRadius' => 3,
                    'pointHoverRadius' => 5,
                    'borderWidth' => 2,
                    'fill' => false,
                    'tension' => 0,
                    /* order LEBIH KECIL dari bar → Chart.js gambar dataset ini
                     * TERAKHIR (di atas) — garis target harus keliatan nembus
                     * batang, bukan ketutupan. */
                    'order' => 1,
                ],
            ],
            'table' => $rows,
            'achievements' => array_column($rows, 'achievement_pct'),
        ]);
    }

    /** Export Excel — satu baris per mesin, sumber sama dengan data(). */
    public function export(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));
        $svc = $this->dashboardService;

        $baris = array_map(fn ($r) => [$r['id_mesin'], $r['mesin'], $r['line'], $r['tipe_mesin'], $r['total_qty_aktual'],
            $r['total_target_qty'], $r['total_duration_hours'], $r['avg_actual_sph'], $r['achievement_pct'], $svc->labelStatus($r['traffic_light'])],
            $svc->getVcbMesin($dateFrom, $dateTo, $shiftId, $mesinIds));

        return Excel::download(new TabelExport(
            ['ID Mesin', 'Nama Mesin', 'Line', 'Tipe', 'Qty Aktual', 'Target Qty', 'Jam Kerja', 'SPH Aktual', 'Achievement %', 'Status'],
            $baris, 'VCB Mesin',
        ), 'vcb-mesin-'.$dateFrom->format('Ymd').'-'.$dateTo->format('Ymd').'.xlsx');
    }
}
