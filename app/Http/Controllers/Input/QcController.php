<?php
namespace App\Http\Controllers\Input;
use App\Exports\QcImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQcRequest;
use App\Http\Requests\UpdateQcRequest;
use App\Exports\LhpQcExport;
use App\Imports\QcImport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Services\NotificationService;
use App\Services\QcService;
use Maatwebsite\Excel\Facades\Excel;

class QcController extends Controller
{
    public function __construct(
        private QcService $qcService,
        private NotificationService $notifSvc,
    ) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));
        $search   = trim((string) $request->input('q', ''));

        $q = TransaksiQc::with(['karyawanQc', 'partProses.part', 'shift', 'jenisNg', 'noteResolvedBy'])
            ->whereDate('tgl_pengecekan', '>=', $dateFrom)
            ->whereDate('tgl_pengecekan', '<=', $dateTo);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawanQc',     fn($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('shift',         fn($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('partProses',    fn($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProses.part', fn($p) =>
                        $p->where('part_no',   'like', $like)
                          ->orWhere('part_name', 'like', $like)
                          ->orWhere('customer',  'like', $like)
                    );
            });
        }

        $transaksi = $q->orderByDesc('tgl_pengecekan')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with(['proses' => fn($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                $part->setRelation('proses', $part->proses->take(1));
                return $part;
            });
        $jenisNg = MasterJenisNg::orderBy('kode')->get();

        $myLastRecord = TransaksiQc::with(['karyawanQc', 'partProses.part'])
            ->where('created_by', auth()->id())
            ->latest()
            ->first();
        $myLastOnPage = $myLastRecord
            ? $transaksi->getCollection()->contains('id', $myLastRecord->id)
            : false;

        return view('input.qc.index', compact('transaksi', 'dateFrom', 'dateTo', 'search', 'shifts', 'karyawan', 'parts', 'jenisNg', 'myLastRecord', 'myLastOnPage'));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get();

        // Finish-good logic: QC hanya inspect produk jadi (proses terakhir = id_proses tertinggi)
        $parts = MasterPart::with(['proses' => fn($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                // Sisakan hanya proses terakhir (id_proses max) per part
                $part->setRelation('proses', $part->proses->take(1));
                return $part;
            });

        $jenisNg = MasterJenisNg::orderBy('kode')->get();
        return view('input.qc.create', compact('shifts', 'karyawan', 'parts', 'jenisNg'));
    }

    public function store(StoreQcRequest $request)
    {
        $validated = $request->validated();

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $validated['jam_start'] . ':00',
            $validated['jam_finish'] . ':00',
            (int) $validated['qty_ok'],
            (int) $validated['qty_ng']
        );

        TransaksiQc::create([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
            'created_by'        => auth()->id(),
        ]);

        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil disimpan.');
    }

    public function edit(TransaksiQc $qc)
    {
        $qc->load(['karyawanQc', 'partProses.part.proses', 'jenisNg']);
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get();
        $parts    = MasterPart::with(['proses' => fn($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                $part->setRelation('proses', $part->proses->take(1));
                return $part;
            });
        $jenisNg = MasterJenisNg::orderBy('kode')->get();
        return view('input.qc.edit', compact('qc', 'shifts', 'karyawan', 'parts', 'jenisNg'));
    }

    public function update(UpdateQcRequest $request, TransaksiQc $qc)
    {
        $validated = $request->validated();
        $cycleTime = $this->qcService->calculateActualCycleTime(
            $validated['jam_start'] . ':00',
            $validated['jam_finish'] . ':00',
            (int) $validated['qty_ok'],
            (int) $validated['qty_ng']
        );
        $qc->update([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
        ]);
        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil diupdate.');
    }

    public function import(\Illuminate\Http\Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls,csv|max:10240']);

        $import = new QcImport();
        Excel::import($import, $request->file('file'));

        $msg = "{$import->importedCount} data LHP Quality berhasil diimport.";
        return redirect()->route('input.qc.index')
            ->with('import_success', $msg)
            ->with('import_errors', $import->errors);
    }

    public function export(\Illuminate\Http\Request $request)
    {
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $customer   = $request->input('customer')    ?: null;
        $partName   = $request->input('part_name')   ?: null;
        $namaProses = $request->input('nama_proses') ?: null;

        $filename = 'LHP_Quality_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(
            new LhpQcExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function importTemplate()
    {
        return Excel::download(new QcImportTemplate(), 'template_lhp_quality.xlsx');
    }

    public function note(\Illuminate\Http\Request $request)
    {
        $request->validate(['staff_note' => 'nullable|string|max:1000', 'record_id' => 'required|integer']);
        $qc = TransaksiQc::find($request->input('record_id'));
        if (!$qc) return back()->with('error', 'Data tidak ditemukan.');
        try {
            $qc->update([
                'staff_note'    => $request->input('staff_note') ?: null,
                'staff_note_at' => $request->input('staff_note') ? now() : null,
                'staff_note_by' => $request->input('staff_note') ? auth()->id() : null,
            ]);
        } catch (\Throwable) { /* kolom belum ada di hosting */ }
        if ($request->input('staff_note')) {
            try {
                $qc->refresh();
                $this->notifSvc->insertNoteSubmitted(
                    $qc, 'qc',
                    $qc->tgl_pengecekan->format('Y-m-d')
                );
            } catch (\Throwable) { /* notification failure must not break main flow */ }
        }
        $msg = $request->input('staff_note') ? 'Note berhasil dikirim ke admin.' : 'Note berhasil dihapus.';
        return redirect()->route('input.qc.index')->with('success', $msg);
    }

    public function resolveNote(\Illuminate\Http\Request $request)
    {
        $request->validate(['record_id' => 'required|integer']);
        $qc = TransaksiQc::find($request->input('record_id'));
        if (!$qc) return back()->with('error', 'Data tidak ditemukan.');
        try {
            $qc->update([
                'staff_note_resolved_at' => now(),
                'staff_note_resolved_by' => auth()->id(),
            ]);
        } catch (\Throwable) {}
        $qc->refresh();
        try {
            $this->notifSvc->insertNoteResolved(
                $qc, 'qc',
                $qc->tgl_pengecekan->format('Y-m-d')
            );
        } catch (\Throwable) { /* notification failure must not break main flow */ }
        return redirect()->route('input.qc.index')->with('success', 'Note ditandai sudah ditangani.');
    }

    public function destroy(TransaksiQc $qc)
    {
        if (auth()->user()->role !== 'admin' && $qc->created_by !== auth()->id()) {
            abort(403);
        }
        $qc->delete();
        return redirect()->route('input.qc.index')->with('success', 'Data berhasil dihapus.');
    }
}
