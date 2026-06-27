<?php
namespace App\Http\Controllers\Input;
use App\Exports\ProduksiImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProduksiRequest;
use App\Http\Requests\UpdateProduksiRequest;
use App\Exports\LhpProduksiExport;
use App\Imports\ProduksiImport;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterJenisNg;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\NotificationService;
use App\Services\ProduksiService;
use Maatwebsite\Excel\Facades\Excel;

class ProduksiController extends Controller
{
    public function __construct(
        private ProduksiService $produksiService,
        private NotificationService $notifSvc,
    ) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $dateFrom       = $request->input('date_from', date('Y-m-01'));
        $dateTo         = $request->input('date_to',   date('Y-m-d'));
        $search         = trim((string) $request->input('q', ''));
        $mesinIds       = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterPartId   = (int) $request->input('filter_part_id', 0);
        $filterKarId    = (int) $request->input('filter_karyawan_id', 0);
        $filterProsesId = (int) $request->input('filter_proses_id', 0);

        $q = TransaksiProduksi::with(['karyawan', 'mesin', 'partProses.part', 'shift', 'jenisNg', 'noteResolvedBy'])
            ->whereDate('tanggal_produksi', '>=', $dateFrom)
            ->whereDate('tanggal_produksi', '<=', $dateTo);

        if (!empty($mesinIds)) {
            $q->whereIn('id_mesin', $mesinIds);
        }
        if ($filterCustomer !== '') {
            $q->whereHas('partProses.part', fn($p) => $p->where('customer', $filterCustomer));
        }
        if ($filterPartId > 0) {
            $q->whereHas('partProses', fn($pp) => $pp->where('id_part', $filterPartId));
        }
        if ($filterKarId > 0) {
            $q->where('id_karyawan', $filterKarId);
        }
        if ($filterProsesId > 0) {
            $q->where('id_part_proses', $filterProsesId);
        }

        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawan',       fn($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('mesin',         fn($m) => $m->where('id_mesin', 'like', $like)->orWhere('nama_mesin', 'like', $like))
                    ->orWhereHas('shift',         fn($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('partProses',    fn($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProses.part', fn($p) =>
                        $p->where('part_no',   'like', $like)
                          ->orWhere('part_name', 'like', $like)
                          ->orWhere('customer',  'like', $like)
                    );
            });
        }

        $transaksi = $q->orderByDesc('tanggal_produksi')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Master data untuk drawer form
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin    = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        $jenisNg  = MasterJenisNg::orderBy('kode')->get();

        // Data untuk filter lanjutan
        $customers = MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values();

        $myLastRecord = TransaksiProduksi::with(['karyawan', 'partProses.part'])
            ->where('created_by', auth()->id())
            ->latest()
            ->first();
        $myLastOnPage = $myLastRecord
            ? $transaksi->getCollection()->contains('id', $myLastRecord->id)
            : false;

        $filterActive = !empty($mesinIds) || $search !== '' || $filterCustomer !== '' || $filterPartId > 0 || $filterKarId > 0 || $filterProsesId > 0;

        return view('input.produksi.index', compact(
            'transaksi', 'dateFrom', 'dateTo', 'search', 'mesinIds',
            'filterCustomer', 'filterPartId', 'filterKarId', 'filterProsesId',
            'shifts', 'karyawan', 'mesin', 'parts', 'jenisNg', 'customers',
            'myLastRecord', 'myLastOnPage', 'filterActive'
        ));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin    = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        $jenisNg  = MasterJenisNg::orderBy('kode')->get();
        return view('input.produksi.create', compact('shifts', 'karyawan', 'mesin', 'parts', 'jenisNg'));
    }

    public function store(StoreProduksiRequest $request)
    {
        $validated = $request->validated();

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $validated['jam_start'] . ':00',
            $validated['jam_finish'] . ':00',
            $validated['qty_aktual']
        );

        TransaksiProduksi::create([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
            'created_by'        => auth()->id(),
        ]);

        return redirect()->route('input.produksi.index')
                         ->with('success', 'LHP Produksi berhasil disimpan.');
    }

    public function edit(TransaksiProduksi $produksi)
    {
        $produksi->load(['karyawan', 'mesin', 'partProses.part.proses', 'jenisNg']);
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin    = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        $jenisNg  = MasterJenisNg::orderBy('kode')->get();
        return view('input.produksi.edit', compact('produksi', 'shifts', 'karyawan', 'mesin', 'parts', 'jenisNg'));
    }

    public function update(UpdateProduksiRequest $request, TransaksiProduksi $produksi)
    {
        $validated = $request->validated();
        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $validated['jam_start'] . ':00',
            $validated['jam_finish'] . ':00',
            $validated['qty_aktual']
        );
        try {
            $produksi->update([
                ...$validated,
                'actual_cycle_time'   => $cycleTime,
                'anomaly_reviewed_at' => null,
                'anomaly_reviewed_by' => null,
            ]);
        } catch (\Throwable) {
            $produksi->update([...$validated, 'actual_cycle_time' => $cycleTime]);
        }
        return redirect()->route('input.produksi.index')->with('success', 'LHP Produksi berhasil diupdate.');
    }

    public function import(\Illuminate\Http\Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls,csv|max:10240']);

        $import = new ProduksiImport();
        Excel::import($import, $request->file('file'));

        $msg = "{$import->importedCount} data LHP Produksi berhasil diimport.";
        return redirect()->route('input.produksi.index')
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

        $filename = 'LHP_Produksi_' . now()->format('Ymd_His') . '.xlsx';
        return Excel::download(
            new LhpProduksiExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function importTemplate()
    {
        return Excel::download(new ProduksiImportTemplate(), 'template_lhp_produksi.xlsx');
    }

    public function note(\Illuminate\Http\Request $request)
    {
        $request->validate(['staff_note' => 'nullable|string|max:1000', 'record_id' => 'required|integer']);
        $produksi = TransaksiProduksi::find($request->input('record_id'));
        if (!$produksi) return back()->with('error', 'Data tidak ditemukan.');
        try {
            $produksi->update([
                'staff_note'    => $request->input('staff_note') ?: null,
                'staff_note_at' => $request->input('staff_note') ? now() : null,
                'staff_note_by' => $request->input('staff_note') ? auth()->id() : null,
            ]);
        } catch (\Throwable) { /* kolom belum ada di hosting */ }
        if ($request->input('staff_note')) {
            $produksi->refresh();
            $this->notifSvc->insertNoteSubmitted(
                $produksi, 'produksi',
                $produksi->tanggal_produksi->format('Y-m-d')
            );
        }
        $msg = $request->input('staff_note') ? 'Note berhasil dikirim ke admin.' : 'Note berhasil dihapus.';
        return redirect()->route('input.produksi.index')->with('success', $msg);
    }

    public function resolveNote(\Illuminate\Http\Request $request)
    {
        $request->validate(['record_id' => 'required|integer']);
        $produksi = TransaksiProduksi::find($request->input('record_id'));
        if (!$produksi) return back()->with('error', 'Data tidak ditemukan.');
        try {
            $produksi->update([
                'staff_note_resolved_at' => now(),
                'staff_note_resolved_by' => auth()->id(),
            ]);
        } catch (\Throwable) {}
        $produksi->refresh();
        $this->notifSvc->insertNoteResolved(
            $produksi, 'produksi',
            $produksi->tanggal_produksi->format('Y-m-d')
        );
        return redirect()->route('input.produksi.index')->with('success', 'Note ditandai sudah ditangani.');
    }

    public function destroy(TransaksiProduksi $produksi)
    {
        if (auth()->user()->role !== 'admin' && $produksi->created_by !== auth()->id()) {
            abort(403);
        }
        $produksi->delete();
        return redirect()->route('input.produksi.index')->with('success', 'Data berhasil dihapus.');
    }
}
