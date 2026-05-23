<?php
namespace App\Http\Controllers\Input;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQcRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Services\QcService;

class QcController extends Controller
{
    public function __construct(private QcService $qcService) {}

    public function index()
    {
        $transaksi = TransaksiQc::with(['karyawanQc', 'partProses.part', 'shift'])
            ->orderByDesc('tgl_pengecekan')
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('input.qc.index', compact('transaksi'));
    }

    public function create()
    {
        $shifts   = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->orderBy('nama_karyawan')->get();
        $parts    = MasterPart::with('proses')->orderBy('part_name')->get();
        return view('input.qc.create', compact('shifts', 'karyawan', 'parts'));
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

    public function destroy(TransaksiQc $qc)
    {
        if (auth()->user()->role !== 'admin' && $qc->created_by !== auth()->id()) {
            abort(403);
        }
        $qc->delete();
        return redirect()->route('input.qc.index')->with('success', 'Data berhasil dihapus.');
    }
}
