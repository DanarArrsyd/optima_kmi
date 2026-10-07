<?php

namespace App\Http\Controllers\Master;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartProsesRepairProduksiRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ProsesRepairProduksiController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $prosesRepairProduksi = $this->queryIndex($request)
            ->paginate(20)->withQueryString();

        return view('master.proses-repair-produksi.index', compact('prosesRepairProduksi', 'search'));
    }

    /** Query daftar BERSAMA index() dan export() — ekspor selalu memakai filter yang sama dengan tabel. */
    private function queryIndex(Request $request): Builder
    {
        $search = trim((string) $request->input('q', ''));

        return MasterPartProsesRepairProduksi::with('part')
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
        $baris = $this->queryIndex($request)->get()->map(fn ($p) => [$p->part?->part_no, $p->part?->part_name, $p->id_proses, $p->nama_proses, $p->sph_standar_repair_produksi])->all();

        return Excel::download(new TabelExport(['Part No', 'Part Name', 'ID Proses', 'Nama Proses', 'SPH Standar Repair Produksi'], $baris, 'Proses Repair Produksi'), 'master-proses-repair-produksi-'.now()->format('Ymd').'.xlsx');
    }

    public function create()
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair-produksi.create', compact('parts'));
    }

    public function store(StoreMasterPartProsesRepairProduksiRequest $request)
    {
        MasterPartProsesRepairProduksi::create($request->validated());

        return redirect()->route('master.proses-repair-produksi.index')->with('success', 'Proses Repair Produksi berhasil ditambahkan.');
    }

    public function edit(MasterPartProsesRepairProduksi $prosesRepairProduksi)
    {
        $parts = MasterPart::orderBy('part_name')->get();

        return view('master.proses-repair-produksi.edit', compact('prosesRepairProduksi', 'parts'));
    }

    public function update(StoreMasterPartProsesRepairProduksiRequest $request, MasterPartProsesRepairProduksi $prosesRepairProduksi)
    {
        $prosesRepairProduksi->update($request->validated());

        return redirect()->route('master.proses-repair-produksi.index')->with('success', 'Proses Repair Produksi berhasil diupdate.');
    }

    public function destroy(MasterPartProsesRepairProduksi $prosesRepairProduksi)
    {
        $prosesRepairProduksi->delete();

        return redirect()->route('master.proses-repair-produksi.index')->with('success', 'Proses Repair Produksi berhasil dihapus.');
    }
}
