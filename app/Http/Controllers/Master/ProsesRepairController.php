<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartProsesRepairRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use Illuminate\Http\Request;

class ProsesRepairController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $prosesRepair = MasterPartProsesRepair::with('part')
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $like = '%'.$search.'%';
                $sub->where('nama_proses', 'like', $like)
                    ->orWhere('id_proses', 'like', $like)
                    ->orWhereHas('part', fn ($p) => $p->where('part_name', 'like', $like)
                        ->orWhere('part_no', 'like', $like));
            }))
            ->orderBy('id_proses')
            ->paginate(20)->withQueryString();

        return view('master.proses-repair.index', compact('prosesRepair', 'search'));
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
