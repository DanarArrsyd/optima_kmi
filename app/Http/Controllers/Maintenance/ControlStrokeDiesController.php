<?php

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterPartProses;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ControlStrokeDiesController extends Controller
{
    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        // Opsi filter (part/customer/proses) dibangun di klien dari data yang dimuat — lihat
        // initFilters() di view. Controller cukup mengirim threshold.
        return view('maintenance.control-stroke-dies', [
            'threshold' => MaintenanceDiesDashboardService::STROKE_THRESHOLD,
        ]);
    }

    public function createPreventive(MasterPartProses $partProses)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        return view('maintenance.control-stroke-dies-preventive', [
            'partProses' => $partProses->load('part'),
            'currentStroke' => $this->svc->currentStrokeFor($partProses),
            'threshold' => MaintenanceDiesDashboardService::STROKE_THRESHOLD,
            'karyawanList' => MasterKaryawan::aktif()->where('departemen', 'Maintenance')->orderBy('nama_karyawan')->get(),
        ]);
    }

    public function data(Request $request)
    {
        $partId = (int) $request->input('part_id', 0) ?: null;
        $customer = trim((string) $request->input('customer', '')) ?: null;
        $rows = $this->svc->computeCurrentCycle($partId, $customer);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'total_dies' => count($rows),
                'overdue' => collect($rows)->where('status', 'overdue')->count(),
                'warning' => collect($rows)->where('status', 'warning')->count(),
            ],
        ]);
    }

    public function history(MasterPartProses $partProses)
    {
        return view('maintenance.control-stroke-dies-history', [
            'partProses' => $partProses->load('part'),
            'logs' => $partProses->preventiveLogs()
                ->with(['karyawan:id,nama_karyawan', 'createdBy:id,name'])
                ->orderByDesc('tanggal_preventive')
                ->get(),
            'currentStroke' => $this->svc->currentStrokeFor($partProses),
            'threshold' => MaintenanceDiesDashboardService::STROKE_THRESHOLD,
        ]);
    }

    public function storePreventive(MasterPartProses $partProses, Request $request)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        $validated = $request->validate([
            'tanggal_preventive' => 'required|date|before_or_equal:today',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'catatan' => 'nullable|string|max:1000',
            'checksheet' => 'required|file|mimes:pdf|max:2048',
        ]);

        $currentStroke = $this->svc->currentStrokeFor($partProses);

        $checksheetPath = $request->file('checksheet')->storeAs(
            'checksheet/dies-preventive',
            $partProses->id.'_'.now()->format('YmdHis').'.pdf',
            'local'
        );

        DiesPreventiveLog::create([
            'id_part_proses' => $partProses->id,
            'tanggal_preventive' => $validated['tanggal_preventive'],
            'id_karyawan' => $validated['id_karyawan'],
            'stroke_at_preventive' => $currentStroke,
            'catatan' => $validated['catatan'] ?? null,
            'checksheet_path' => $checksheetPath,
            'created_by' => auth()->id(),
        ]);

        $partNo = $partProses->part?->part_no;

        return redirect()->route('maintenance.control-stroke-dies')->with(
            'success',
            "Preventive {$partNo} — {$partProses->nama_proses} tersimpan. Stroke direset dari "
            .number_format($currentStroke, 0, ',', '.').' ke 0.'
        );
    }

    public function showChecksheet(DiesPreventiveLog $diesPreventiveLog)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        if (! $diesPreventiveLog->checksheet_path || ! Storage::disk('local')->exists($diesPreventiveLog->checksheet_path)) {
            abort(404, 'Checksheet tidak ditemukan.');
        }

        return response()->file(Storage::disk('local')->path($diesPreventiveLog->checksheet_path));
    }
}
