<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakdownMesinRequest;
use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Services\BreakdownMesinService;
use Illuminate\Http\Request;

class BreakdownMesinController extends Controller
{
    public function __construct(private BreakdownMesinService $svc) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));
        $mesinId  = (int) $request->input('mesin_id', 0);

        $breakdowns = TransaksiBreakdownMesin::with('mesin')
            ->whereBetween('tanggal_breakdown', [$dateFrom, $dateTo])
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('maintenance.breakdown.index', [
            'breakdowns' => $breakdowns,
            'mesinList'  => MasterMesin::orderBy('id_mesin')->get(),
            'dateFrom'   => $dateFrom,
            'dateTo'     => $dateTo,
            'mesinId'    => $mesinId,
        ]);
    }

    public function create()
    {
        return view('maintenance.breakdown.create', [
            'mesinList'  => MasterMesin::orderBy('id_mesin')->get(),
            'nextNomor'  => $this->svc->nextNomorUrut(),
        ]);
    }

    public function lastNoLkm()
    {
        return response()->json(['next_nomor' => $this->svc->nextNomorUrut()]);
    }

    public function store(StoreBreakdownMesinRequest $request)
    {
        $data = $request->validated();
        $noLkm = $this->svc->generateNoLkm((int) $data['nomor_urut'], $data['tanggal_breakdown']);

        if (TransaksiBreakdownMesin::where('no_lkm', $noLkm)->exists()) {
            return back()->withInput()->withErrors(['nomor_urut' => "No LKM {$noLkm} sudah ada, gunakan nomor lain."]);
        }

        $derived = $this->svc->computeDerived($data);
        TransaksiBreakdownMesin::create([
            'no_lkm'                    => $noLkm,
            'id_mesin'                  => $data['id_mesin'],
            'tanggal_breakdown'         => $data['tanggal_breakdown'],
            'jam_breakdown'             => $data['jam_breakdown'],
            'tanggal_mulai_perbaikan'   => $data['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan'       => $data['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $data['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan'     => $data['jam_selesai_perbaikan'],
            'problem_mesin'             => $data['problem_mesin'],
            'penyebab_breakdown'        => $data['penyebab_breakdown'],
            'tindakan_perbaikan'        => $data['tindakan_perbaikan'],
            'pic_maintenance'           => $data['pic_maintenance'],
            ...$derived,
        ]);

        return redirect()->route('maintenance.breakdown.index')->with('success', "Breakdown {$noLkm} tersimpan.");
    }

    public function edit($breakdown)
    {
        return redirect()->route('maintenance.breakdown.index');
    }

    public function update(Request $request, $breakdown)
    {
        return redirect()->route('maintenance.breakdown.index');
    }

    public function destroy($breakdown)
    {
        return redirect()->route('maintenance.breakdown.index');
    }
}
