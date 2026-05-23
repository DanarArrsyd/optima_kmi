<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterLosstimeRequest;
use App\Models\MasterLosstime;

class LosstimeController extends Controller
{
    public function index()
    {
        $losstimes = MasterLosstime::orderBy('kode')->paginate(20);
        return view('master.losstime.index', compact('losstimes'));
    }
    public function create() { return view('master.losstime.create'); }
    public function store(StoreMasterLosstimeRequest $request)
    {
        MasterLosstime::create($request->validated());
        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil ditambahkan.');
    }
    public function edit(MasterLosstime $losstime) { return view('master.losstime.edit', compact('losstime')); }
    public function update(StoreMasterLosstimeRequest $request, MasterLosstime $losstime)
    {
        $losstime->update($request->validated());
        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil diupdate.');
    }
    public function destroy(MasterLosstime $losstime)
    {
        $losstime->delete();
        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil dihapus.');
    }
}
