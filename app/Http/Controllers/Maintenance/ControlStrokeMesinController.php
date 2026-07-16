<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MesinPreventiveLog;
use App\Services\MaintenanceDashboardService;
use Illuminate\Http\Request;

class ControlStrokeMesinController extends Controller
{
    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.control-stroke-mesin', [
            'tipeList'  => MasterMesin::TIPE_MESIN,
            'lineList'  => MasterMesin::whereNotNull('line')->where('line', '!=', '')->distinct()->orderBy('line')->pluck('line'),
            'threshold' => MaintenanceDashboardService::STROKE_THRESHOLD,
        ]);
    }

    public function data(Request $request)
    {
        $mesinId   = (int) $request->input('mesin_id', 0) ?: null;
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows      = $this->svc->computeCurrentCycle($mesinId, $tipeMesin);

        return response()->json([
            'rows'    => $rows,
            'summary' => [
                'total_mesin' => count($rows),
                'overdue'     => collect($rows)->where('status', 'overdue')->count(),
                'warning'     => collect($rows)->where('status', 'warning')->count(),
            ],
        ]);
    }

    public function createPreventive(MasterMesin $mesin)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        return view('maintenance.control-stroke-mesin-preventive', [
            'mesin'         => $mesin,
            'currentStroke' => $this->svc->currentStrokeFor($mesin),
            'threshold'     => MaintenanceDashboardService::STROKE_THRESHOLD,
            'karyawanList'  => MasterKaryawan::aktif()->where('departemen', 'Maintenance')->orderBy('nama_karyawan')->get(),
        ]);
    }

    public function storePreventive(MasterMesin $mesin, Request $request)
    {
        abort_if(!in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

        $validated = $request->validate([
            'tanggal_preventive' => 'required|date|before_or_equal:today',
            'id_karyawan'        => 'required|exists:master_karyawan,id',
            'catatan'            => 'nullable|string|max:1000',
        ]);

        $currentStroke = $this->svc->currentStrokeFor($mesin);

        MesinPreventiveLog::create([
            'id_mesin'             => $mesin->id,
            'tanggal_preventive'   => $validated['tanggal_preventive'],
            'id_karyawan'          => $validated['id_karyawan'],
            'stroke_at_preventive' => $currentStroke,
            'catatan'              => $validated['catatan'] ?? null,
            'created_by'           => auth()->id(),
        ]);

        return redirect()->route('maintenance.control-stroke-mesin')->with(
            'success',
            "Preventive {$mesin->id_mesin} — {$mesin->nama_mesin} tersimpan. Stroke direset dari "
            . number_format($currentStroke, 0, ',', '.') . ' ke 0.'
        );
    }

    public function history(MasterMesin $mesin)
    {
        return view('maintenance.control-stroke-mesin-history', [
            'mesin'         => $mesin,
            'logs'          => $mesin->preventiveLogs()
                ->with(['karyawan:id,nama_karyawan', 'createdBy:id,name'])
                ->orderByDesc('tanggal_preventive')
                ->get(),
            'currentStroke' => $this->svc->currentStrokeFor($mesin),
            'threshold'     => MaintenanceDashboardService::STROKE_THRESHOLD,
        ]);
    }
}
