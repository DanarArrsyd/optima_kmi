<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterKaryawanRequest;
use App\Models\MasterKaryawan;
use App\Imports\KaryawanImport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Http\Request;

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

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new KaryawanImport;
        Excel::import($import, $request->file('file'));
        return redirect()->route('master.karyawan.index')
            ->with('success', "Import berhasil: {$import->imported} data diproses, {$import->skipped} baris dilewati.");
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings {
            public function headings(): array
            {
                return ['NIK', 'Nama Karyawan', 'Departemen'];
            }
            public function array(): array
            {
                return [
                    ['K001', 'Ahmad Fauzi', 'Produksi'],
                    ['K002', 'Siti Rahayu', 'QC'],
                ];
            }
        };
        return Excel::download($export, 'template_karyawan.xlsx');
    }
}
