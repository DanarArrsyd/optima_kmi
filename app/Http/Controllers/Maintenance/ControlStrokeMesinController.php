<?php

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MesinPreventiveLog;
use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use App\Services\KalenderKerjaService;
use App\Services\MaintenanceDashboardService;
use App\Services\PreventiveScheduleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class ControlStrokeMesinController extends Controller
{
    public function __construct(private MaintenanceDashboardService $svc) {}

    public function index()
    {
        $bulan = now()->timezone('Asia/Jakarta')->startOfMonth();
        $schedule = PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'mesin')
            ->with('items.mesin')
            ->first();

        // Opsi filter (mesin/tipe/line) dibangun di klien dari data yang dimuat — lihat
        // initFilters() di view. Controller cukup mengirim threshold.
        return view('maintenance.control-stroke-mesin', [
            'threshold' => MaintenanceDashboardService::STROKE_THRESHOLD,
            'jadwalSchedule' => $schedule,
            'jadwalBulan' => $bulan,
        ]);
    }

    public function data(Request $request)
    {
        $mesinId = (int) $request->input('mesin_id', 0) ?: null;
        $tipeMesin = $request->input('tipe_mesin') ?: null;
        $rows = $this->svc->computeCurrentCycle($mesinId, $tipeMesin);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'total_mesin' => count($rows),
                'overdue' => collect($rows)->where('status', 'overdue')->count(),
                'warning' => collect($rows)->where('status', 'warning')->count(),
            ],
        ]);
    }

    public function createPreventive(MasterMesin $mesin)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        return view('maintenance.control-stroke-mesin-preventive', [
            'mesin' => $mesin,
            'currentStroke' => $this->svc->currentStrokeFor($mesin),
            'threshold' => MaintenanceDashboardService::STROKE_THRESHOLD,
            'karyawanList' => MasterKaryawan::aktif()->where('departemen', 'Maintenance')->orderBy('nama_karyawan')->get(),
        ]);
    }

    public function storePreventive(MasterMesin $mesin, Request $request)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        $validated = $request->validate([
            'tanggal_preventive' => 'required|date|before_or_equal:today',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'catatan' => 'nullable|string|max:1000',
        ]);

        $currentStroke = $this->svc->currentStrokeFor($mesin);

        MesinPreventiveLog::create([
            'id_mesin' => $mesin->id,
            'tanggal_preventive' => $validated['tanggal_preventive'],
            'id_karyawan' => $validated['id_karyawan'],
            'stroke_at_preventive' => $currentStroke,
            'catatan' => $validated['catatan'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('maintenance.control-stroke-mesin')->with(
            'success',
            "Preventive {$mesin->id_mesin} — {$mesin->nama_mesin} tersimpan. Stroke direset dari "
            .number_format($currentStroke, 0, ',', '.').' ke 0.'
        );
    }

    public function history(MasterMesin $mesin)
    {
        return view('maintenance.control-stroke-mesin-history', [
            'mesin' => $mesin,
            'logs' => $mesin->preventiveLogs()
                ->with(['karyawan:id,nama_karyawan', 'createdBy:id,name'])
                ->orderByDesc('tanggal_preventive')
                ->get(),
            'currentStroke' => $this->svc->currentStrokeFor($mesin),
            'threshold' => MaintenanceDashboardService::STROKE_THRESHOLD,
        ]);
    }

    public function schedulePdf()
    {
        $bulan = now()->timezone('Asia/Jakarta')->startOfMonth();
        $schedule = PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'mesin')
            ->with(['items' => fn ($q) => $q->whereNotNull('tanggal_rencana')->with('mesin.preventiveLogs')])
            ->first();

        $pdf = Pdf::loadView('maintenance.preventive-schedule-pdf', [
            'jenis' => 'mesin',
            'schedule' => $schedule,
            'bulan' => $bulan,
            'svc' => $this->svc,
        ])->setPaper('a4', 'landscape');

        $namaFile = str_replace('/', '-', $schedule->nomor_dokumen ?? 'schedule-mesin').'.pdf';

        return $pdf->stream($namaFile);
    }

    public function scheduleGenerate(PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        $preventiveSvc->generate('mesin', now()->timezone('Asia/Jakarta')->startOfMonth());

        return redirect()->route('maintenance.control-stroke-mesin')->with(
            'success', 'Jadwal preventive mesin bulan ini berhasil digenerate ulang.'
        );
    }

    public function updateScheduleItem(PreventiveScheduleItem $item, Request $request, PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'mesin', 422, 'Item ini bukan jadwal mesin.');

        $validated = $request->validate(['tanggal_rencana' => 'required|date']);
        $tanggal = Carbon::parse($validated['tanggal_rencana']);
        $bulanSchedule = Carbon::parse($item->schedule->bulan);

        if (! $tanggal->isSameMonth($bulanSchedule)) {
            return response()->json(['message' => 'Tanggal harus dalam bulan yang sama dengan jadwal ini.'], 422);
        }
        if (! KalenderKerjaService::hariKerja($tanggal)) {
            return response()->json(['message' => 'Tanggal itu hari libur / bukan hari kerja.'], 422);
        }
        if (! in_array($tanggal->isoWeekday(), $preventiveSvc->hariDiizinkan('mesin'), true)) {
            return response()->json(['message' => 'Tanggal itu di luar hari yang diizinkan untuk mesin (Senin-Kamis).'], 422);
        }

        $mingguKe = $preventiveSvc->mingguKeUntuk('mesin', $bulanSchedule, $tanggal);
        if ($mingguKe === null) {
            return response()->json(['message' => 'Gagal menentukan minggu untuk tanggal ini.'], 422);
        }

        $item->update([
            'tanggal_rencana' => $tanggal->toDateString(),
            'minggu_ke' => $mingguKe,
            'backlog_since' => null,
            'is_manual_override' => true,
            'overridden_by' => auth()->id(),
            'overridden_at' => now(),
        ]);

        return response()->json(['message' => 'Tanggal berhasil diubah.', 'tanggal_rencana' => $item->tanggal_rencana->toDateString()]);
    }

    public function resetScheduleItem(PreventiveScheduleItem $item)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'mesin', 422, 'Item ini bukan jadwal mesin.');

        $item->update([
            'is_manual_override' => false,
            'tanggal_rencana' => null,
            'minggu_ke' => null,
            'overridden_by' => null,
            'overridden_at' => null,
            'backlog_since' => now()->timezone('Asia/Jakarta')->toDateString(),
        ]);

        return response()->json(['message' => 'Item direset ke otomatis, masuk backlog sampai regenerate berikutnya.']);
    }
}
