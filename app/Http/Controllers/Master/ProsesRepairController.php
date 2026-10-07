<?php

namespace App\Http\Controllers\Master;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartProsesRepairRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProsesRepairController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $prosesRepair = $this->queryIndex($request)
            ->paginate(20)->withQueryString();

        return view('master.proses-repair.index', compact('prosesRepair', 'search'));
    }

    /** Query daftar BERSAMA index() dan export() — ekspor selalu memakai filter yang sama dengan tabel. */
    private function queryIndex(Request $request): Builder
    {
        $search = trim((string) $request->input('q', ''));

        return MasterPartProsesRepair::with('part')
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $like = '%'.$search.'%';
                $sub->where('nama_proses', 'like', $like)
                    ->orWhere('id_proses', 'like', $like)
                    ->orWhereHas('part', fn ($p) => $p->where('part_name', 'like', $like)
                        ->orWhere('part_no', 'like', $like));
            }))
            ->orderBy('id_proses');
    }

    /** Export Excel isi tabel, termasuk filter yang sedang aktif. */
    public function export(Request $request)
    {
        $baris = $this->queryIndex($request)->get()->map(fn ($p) => [$p->part?->part_no, $p->part?->part_name, $p->id_proses, $p->nama_proses, $p->sph_standar_repair])->all();

        return Excel::download(new TabelExport(['Part No', 'Part Name', 'ID Proses', 'Nama Proses', 'SPH Standar Repair'], $baris, 'Proses Repair'), 'master-proses-repair-'.now()->format('Ymd').'.xlsx');
    }

    public function create()
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair.create', compact('parts'));
    }

    public function store(StoreMasterPartProsesRepairRequest $request)
    {
        MasterPartProsesRepair::create($request->validated());

        return redirect()->route('master.proses-repair.index')->with('success', 'Proses Repair berhasil ditambahkan.');
    }

    public function edit(MasterPartProsesRepair $prosesRepair)
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair.edit', compact('prosesRepair', 'parts'));
    }

    public function update(StoreMasterPartProsesRepairRequest $request, MasterPartProsesRepair $prosesRepair)
    {
        $prosesRepair->update($request->validated());

        return redirect()->route('master.proses-repair.index')->with('success', 'Proses Repair berhasil diupdate.');
    }

    public function destroy(MasterPartProsesRepair $prosesRepair)
    {
        $prosesRepair->delete();

        return redirect()->route('master.proses-repair.index')->with('success', 'Proses Repair berhasil dihapus.');
    }
}
