<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterMesinRequest;
use App\Models\MasterMesin;
use App\Imports\MesinImport;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Http\Request;

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

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new MesinImport;
        Excel::import($import, $request->file('file'));
        return redirect()->route('master.mesin.index')
            ->with('success', "Import berhasil: {$import->imported} data diproses, {$import->skipped} baris dilewati.");
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings {
            public function headings(): array
            {
                return ['ID Mesin', 'Nama Mesin', 'Line', 'Tipe Mesin', 'Kapasitas Max Stroke'];
            }
            public function array(): array
            {
                return [
                    ['M001', 'Press 80T #1', 'Line A', 'Progressive', 800000],
                    ['M002', 'Press 110T #1', 'Line B', 'Progressive', 1100000],
                ];
            }
        };
        return Excel::download($export, 'template_mesin.xlsx');
    }
}
