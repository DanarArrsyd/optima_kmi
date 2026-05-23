<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartRequest;
use App\Http\Requests\StoreMasterPartProsesRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProses;

class PartController extends Controller
{
    public function index()
    {
        $parts = MasterPart::withCount('proses')->orderBy('part_name')->paginate(20);
        return view('master.part.index', compact('parts'));
    }

    public function create()
    {
        return view('master.part.create');
    }

    public function store(StoreMasterPartRequest $request)
    {
        MasterPart::create($request->validated());
        return redirect()->route('master.part.index')->with('success', 'Part berhasil ditambahkan. Tambahkan proses di halaman edit.');
    }

    public function edit(MasterPart $part)
    {
        $part->load('proses');
        return view('master.part.edit', compact('part'));
    }

    public function update(StoreMasterPartRequest $request, MasterPart $part)
    {
        $part->update($request->validated());
        return redirect()->route('master.part.edit', $part)->with('success', 'Part berhasil diupdate.');
    }

    public function destroy(MasterPart $part)
    {
        $part->delete();
        return redirect()->route('master.part.index')->with('success', 'Part berhasil dihapus.');
    }

    public function storeProses(StoreMasterPartProsesRequest $request, MasterPart $part)
    {
        $part->proses()->create($request->validated());
        return redirect()->route('master.part.edit', $part)->with('success', 'Proses berhasil ditambahkan.');
    }

    public function destroyProses(MasterPart $part, MasterPartProses $proses)
    {
        $proses->delete();
        return redirect()->route('master.part.edit', $part)->with('success', 'Proses berhasil dihapus.');
    }
}
