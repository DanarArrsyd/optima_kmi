<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterKaryawanRequest;
use App\Models\MasterKaryawan;

class KaryawanController extends Controller
{
    public function index()
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->paginate(20);
        return view('master.karyawan.index', compact('karyawan'));
    }

    public function create()
    {
        return view('master.karyawan.create');
    }

    public function store(StoreMasterKaryawanRequest $request)
    {
        MasterKaryawan::create($request->validated());
        return redirect()->route('master.karyawan.index')->with('success', 'Karyawan berhasil ditambahkan.');
    }

    public function edit(MasterKaryawan $karyawan)
    {
        return view('master.karyawan.edit', compact('karyawan'));
    }

    public function update(StoreMasterKaryawanRequest $request, MasterKaryawan $karyawan)
    {
        $karyawan->update($request->validated());
        return redirect()->route('master.karyawan.index')->with('success', 'Karyawan berhasil diupdate.');
    }

    public function destroy(MasterKaryawan $karyawan)
    {
        $karyawan->delete();
        return redirect()->route('master.karyawan.index')->with('success', 'Karyawan berhasil dihapus.');
    }
}
