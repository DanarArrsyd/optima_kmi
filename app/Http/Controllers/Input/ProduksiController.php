<?php
namespace App\Http\Controllers\Input;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProduksiRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\ProduksiService;

class ProduksiController extends Controller
{
    public function __construct(private ProduksiService $produksiService) {}

    public function index()
    {
        $transaksi = TransaksiProduksi::with(['karyawan', 'mesin', 'partProses.part', 'shift'])
            ->orderByDesc('tanggal_produksi')
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('input.produksi.index', compact('transaksi'));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin    = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        return view('input.produksi.create', compact('shifts', 'karyawan', 'mesin', 'parts'));
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

    public function destroy(TransaksiProduksi $produksi)
    {
        if (auth()->user()->role !== 'admin' && $produksi->created_by !== auth()->id()) {
            abort(403);
        }
        $produksi->delete();
        return redirect()->route('input.produksi.index')->with('success', 'Data berhasil dihapus.');
    }
}
