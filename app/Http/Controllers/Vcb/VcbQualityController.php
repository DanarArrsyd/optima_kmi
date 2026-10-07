<?php

namespace App\Http\Controllers\Vcb;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;

class VcbQualityController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::whereRaw('LOWER(departemen) = ?', ['qc'])
            ->orderBy('nama_karyawan')
            ->get();

        return view('vcb.quality', compact('shifts', 'karyawan'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        $rows = $this->dashboardService->getVcbQuality($dateFrom, $dateTo, $shiftId);
        $labels = array_column($rows, 'karyawan');
        $achievements = array_column($rows, 'achievement_pct');
        $colors = array_map(fn ($r) => $this->trafficHex($r['traffic_light']), $rows);

        return response()->json([
            'labels' => $labels,
            'achievements' => $achievements,
            'colors' => $colors,
            'table' => $rows,
        ]);
    }

    public function operatorData(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $karyawanQcId = (int) $request->input('karyawan_id');
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;

        if (! $karyawanQcId) {
            return response()->json(['error' => 'Pilih inspector terlebih dahulu.'], 422);
        }

        $rows = $this->dashboardService->getVcbQualityByDate($dateFrom, $dateTo, $karyawanQcId, $shiftId);
        $inspector = MasterKaryawan::find($karyawanQcId)?->nama_karyawan ?? '—';

        return response()->json([
            'inspector' => $inspector,
            'labels' => array_column($rows, 'tanggal'),
            'achievements' => array_column($rows, 'achievement_pct'),
            'colors' => array_map(fn ($r) => $this->trafficHex($r['traffic_light']), $rows),
            'table' => $rows,
        ]);
    }

    /** Export Excel — pola sama dengan VcbProduksiController::export(). */
    public function export(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $karyawanId = (int) $request->input('karyawan_id');
        $svc = $this->dashboardService;

        if ($karyawanId) {
            $baris = [];
            foreach ($svc->getVcbQualityByDate($dateFrom, $dateTo, $karyawanId, $shiftId) as $hari) {
                foreach ($hari['sessions'] as $s) {
                    $baris[] = [$hari['tanggal_raw'], $s['customer'], $s['part_no'], $s['part_name'], $s['nama_proses'],
                        $s['waktu_kerja'], $s['qty_ok'], $s['qty_ng'], $s['ng_rate'], $s['sph_std'], $s['sph_act'], $s['pct']];
                }
            }
            $nama = MasterKaryawan::find($karyawanId)?->nama_karyawan ?? 'inspector';

            return Excel::download(new TabelExport(
                ['Tanggal', 'Customer', 'Part No', 'Part Name', 'Proses', 'Waktu Kerja (jam)', 'Qty OK', 'Qty NG', 'NG Rate %', 'SPH Std', 'SPH Aktual', 'Achievement %'],
                $baris, 'VCB '.$nama,
            ), 'vcb-quality-'.str($nama)->slug().'-'.$dateFrom->format('Ymd').'-'.$dateTo->format('Ymd').'.xlsx');
        }

        $baris = array_map(fn ($r) => [$r['karyawan'], $r['total_qty_ok'], $r['total_qty_ng'], $r['total_qty_check'], $r['ng_rate'],
            $r['total_target_qty'], $r['total_duration_hours'], $r['avg_actual_sph'], $r['achievement_pct'], $svc->labelStatus($r['traffic_light'])],
            $svc->getVcbQuality($dateFrom, $dateTo, $shiftId));

        return Excel::download(new TabelExport(
            ['Inspector', 'Qty OK', 'Qty NG', 'Qty Cek', 'NG Rate %', 'Target Qty', 'Jam Kerja', 'SPH Aktual', 'Achievement %', 'Status'],
            $baris, 'VCB Quality',
        ), 'vcb-quality-'.$dateFrom->format('Ymd').'-'.$dateTo->format('Ymd').'.xlsx');
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
