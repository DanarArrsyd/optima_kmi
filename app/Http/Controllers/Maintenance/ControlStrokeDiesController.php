<?php

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterPartProses;
use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use App\Services\KalenderKerjaService;
use App\Services\MaintenanceDiesDashboardService;
use App\Services\PreventiveScheduleService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ControlStrokeDiesController extends Controller
{
    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        $bulan = now()->timezone('Asia/Jakarta')->startOfMonth();
        $schedule = PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'dies')
            ->with('items.partProses.part')
            ->first();

        // Opsi filter (part/customer/proses) dibangun di klien dari data yang dimuat — lihat
        // initFilters() di view. Controller cukup mengirim threshold.
        return view('maintenance.control-stroke-dies', [
            'threshold' => MaintenanceDiesDashboardService::STROKE_THRESHOLD,
            'jadwalSchedule' => $schedule,
            'jadwalBulan' => $bulan,
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

    public function schedulePdf()
    {
        $bulan = now()->timezone('Asia/Jakarta')->startOfMonth();
        $schedule = PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'dies')
            ->with(['items' => fn ($q) => $q->whereNotNull('tanggal_rencana')->with('partProses.part', 'partProses.preventiveLogs')])
            ->first();

        $pdf = Pdf::loadView('maintenance.preventive-schedule-pdf', [
            'jenis' => 'dies',
            'schedule' => $schedule,
            'bulan' => $bulan,
            'svc' => $this->svc,
        ])->setPaper('a4', 'landscape');

        $namaFile = str_replace('/', '-', $schedule->nomor_dokumen ?? 'schedule-dies').'.pdf';

        return $pdf->stream($namaFile);
    }

    public function scheduleGenerate(PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        $preventiveSvc->generate('dies', now()->timezone('Asia/Jakarta')->startOfMonth());

        return redirect()->route('maintenance.control-stroke-dies')->with(
            'success', 'Jadwal preventive dies bulan ini berhasil digenerate ulang.'
        );
    }

    public function updateScheduleItem(PreventiveScheduleItem $item, Request $request, PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);
        abort_unless(Schema::hasColumn('preventive_schedule_items', 'is_manual_override'), 503, 'Fitur belum tersedia, migrasi belum jalan.');

        $item->loadMissing('schedule');
        abort_if($item->schedule->jenis !== 'dies', 422, 'Item ini bukan jadwal dies.');

        $validated = $request->validate(['tanggal_rencana' => 'required|date']);
        $tanggal = Carbon::parse($validated['tanggal_rencana']);
        $bulanSchedule = Carbon::parse($item->schedule->bulan);

        if (! $tanggal->isSameMonth($bulanSchedule)) {
            return response()->json(['message' => 'Tanggal harus dalam bulan yang sama dengan jadwal ini.'], 422);
        }
        if (! KalenderKerjaService::hariKerja($tanggal)) {
            return response()->json(['message' => 'Tanggal itu hari libur / bukan hari kerja.'], 422);
        }
        if (! in_array($tanggal->isoWeekday(), $preventiveSvc->hariDiizinkan('dies'), true)) {
            return response()->json(['message' => 'Tanggal itu di luar hari yang diizinkan untuk dies (Senin-Jumat).'], 422);
        }

        $mingguKe = $preventiveSvc->mingguKeUntuk('dies', $bulanSchedule, $tanggal);
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
        abort_if($item->schedule->jenis !== 'dies', 422, 'Item ini bukan jadwal dies.');

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
