<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterMesinRequest;
use App\Models\MasterMesin;

class MesinController extends Controller
{
    public function index()
    {
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->paginate(20);
        return view('master.mesin.index', compact('mesin'));
    }
    public function create() { return view('master.mesin.create'); }
    public function store(StoreMasterMesinRequest $request)
    {
        MasterMesin::create($request->validated());
        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil ditambahkan.');
    }
    public function edit(MasterMesin $mesin) { return view('master.mesin.edit', compact('mesin')); }
    public function update(StoreMasterMesinRequest $request, MasterMesin $mesin)
    {
        $mesin->update($request->validated());
        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil diupdate.');
    }
    public function destroy(MasterMesin $mesin)
    {
        $mesin->delete();
        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil dihapus.');
    }
}
