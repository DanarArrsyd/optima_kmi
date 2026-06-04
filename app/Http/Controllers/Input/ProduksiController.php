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
use App\Models\MasterJenisNg;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\ProduksiService;
use Maatwebsite\Excel\Facades\Excel;

class ProduksiController extends Controller
{
    public function __construct(private ProduksiService $produksiService) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));

        $transaksi = TransaksiProduksi::with(['karyawan', 'mesin', 'partProses.part', 'shift', 'jenisNg'])
            ->whereDate('tanggal_produksi', '>=', $dateFrom)
            ->whereDate('tanggal_produksi', '<=', $dateTo)
            ->orderByDesc('tanggal_produksi')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        // Master data untuk drawer form
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin    = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        $jenisNg  = MasterJenisNg::orderBy('kode')->get();

        return view('input.produksi.index', compact(
            'transaksi', 'dateFrom', 'dateTo',
            'shifts', 'karyawan', 'mesin', 'parts', 'jenisNg'
        ));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
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
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
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
        $produksi->update([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
        ]);
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

    public function destroy(TransaksiProduksi $produksi)
    {
        if (auth()->user()->role !== 'admin' && $produksi->created_by !== auth()->id()) {
            abort(403);
        }
        $produksi->delete();
        return redirect()->route('input.produksi.index')->with('success', 'Data berhasil dihapus.');
    }
}
