<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterShiftRequest;
use App\Models\MasterShift;

class ShiftController extends Controller
{
    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->paginate(20);
        return view('master.shift.index', compact('shifts'));
    }
    public function create() { return view('master.shift.create'); }
    public function store(StoreMasterShiftRequest $request)
    {
        MasterShift::create($request->validated());
        return redirect()->route('master.shift.index')->with('success', 'Shift berhasil ditambahkan.');
    }
    public function edit(MasterShift $shift) { return view('master.shift.edit', compact('shift')); }
    public function update(StoreMasterShiftRequest $request, MasterShift $shift)
    {
        $shift->update($request->validated());
        return redirect()->route('master.shift.index')->with('success', 'Shift berhasil diupdate.');
    }
    public function destroy(MasterShift $shift)
    {
        $shift->delete();
        return redirect()->route('master.shift.index')->with('success', 'Shift berhasil dihapus.');
    }
}
