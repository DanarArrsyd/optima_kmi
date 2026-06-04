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
use App\Services\QcService;
use Maatwebsite\Excel\Facades\Excel;

class QcController extends Controller
{
    public function __construct(private QcService $qcService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));   // awal bulan ini
        $dateTo   = $request->input('date_to',   date('Y-m-d'));    // hari ini

        $transaksi = TransaksiQc::with(['karyawanQc', 'partProses.part', 'shift', 'jenisNg'])
            ->whereDate('tgl_pengecekan', '>=', $dateFrom)
            ->whereDate('tgl_pengecekan', '<=', $dateTo)
            ->orderByDesc('tgl_pengecekan')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with(['proses' => fn($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                $part->setRelation('proses', $part->proses->take(1));
                return $part;
            });
        $jenisNg = MasterJenisNg::orderBy('kode')->get();

        return view('input.qc.index', compact('transaksi', 'dateFrom', 'dateTo', 'shifts', 'karyawan', 'parts', 'jenisNg'));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->orderBy('nama_karyawan')->get();

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
        $karyawan = MasterKaryawan::where('departemen', 'QC')->orderBy('nama_karyawan')->get();
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

    public function destroy(TransaksiQc $qc)
    {
        if (auth()->user()->role !== 'admin' && $qc->created_by !== auth()->id()) {
            abort(403);
        }
        $qc->delete();
        return redirect()->route('input.qc.index')->with('success', 'Data berhasil dihapus.');
    }
}
