<?php
namespace App\Http\Controllers\Input;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDandoriRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterLosstime;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandori;
use App\Services\DandoriService;

class DandoriController extends Controller
{
    public function __construct(private DandoriService $dandoriService) {}

    public function index()
    {
        $transaksi = TransaksiDandori::with(['karyawan', 'mesin', 'partProses.part', 'shift', 'rincian.losstime'])
            ->orderByDesc('tanggal')
            ->orderByDesc('created_at')
            ->paginate(30);
        return view('input.dandori.index', compact('transaksi'));
    }

    public function create()
    {
        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin     = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts     = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();
        return view('input.dandori.create', compact('shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function store(StoreDandoriRequest $request)
    {
        $validated   = $request->validated();
        $partProses  = MasterPartProses::findOrFail($validated['id_part_proses']);
        $namaProses  = $partProses->nama_proses;
        $standardTime = $partProses->std_dandori_menit;

        $processedRincian = $this->dandoriService->processRincian($validated['rincian'], $namaProses);

        $totalAktual = collect($processedRincian)->sum('menit_loss');
        $status      = $this->dandoriService->hitungStatus($totalAktual, $standardTime);

        $dandori = TransaksiDandori::create([
            'tanggal'             => $validated['tanggal'],
            'id_shift'            => $validated['id_shift'],
            'id_karyawan'         => $validated['id_karyawan'],
            'id_mesin'            => $validated['id_mesin'],
            'id_part_proses'      => $validated['id_part_proses'],
            'total_aktual_menit'  => $totalAktual,
            'standard_time_menit' => $standardTime,
            'status'              => $status,
            'created_by'          => auth()->id(),
        ]);

        $dandori->rincian()->createMany($processedRincian);

        return redirect()->route('input.dandori.index')
                         ->with('success', "Dandori berhasil disimpan. Total: {$totalAktual} menit ({$status}).");
    }

    public function destroy(TransaksiDandori $dandori)
    {
        if (auth()->user()->role !== 'admin' && $dandori->created_by !== auth()->id()) {
            abort(403);
        }
        $dandori->delete();
        return redirect()->route('input.dandori.index')->with('success', 'Data berhasil dihapus.');
    }

    public function getPartProses(MasterPart $part)
    {
        return response()->json($part->proses()->select('id', 'id_proses', 'nama_proses', 'std_dandori_menit')->get());
    }

    public function getStandardTime(MasterPartProses $partProses)
    {
        return response()->json(['std_dandori_menit' => $partProses->std_dandori_menit]);
    }
}
