<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterLosstimeRequest;
use App\Imports\LosstimeImport;
use App\Models\MasterLosstime;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class LosstimeController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));

        $losstimes = MasterLosstime::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('kode', 'like', '%'.$search.'%')
                ->orWhere('jenis_losstime', 'like', '%'.$search.'%')))
            ->orderBy('kode')
            ->paginate(20)->withQueryString();

        return view('master.losstime.index', compact('losstimes', 'search'));
    }

    public function create()
    {
        return view('master.losstime.create');
    }

    public function store(StoreMasterLosstimeRequest $request)
    {
        MasterLosstime::create($request->validated());

        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil ditambahkan.');
    }

    public function edit(MasterLosstime $losstime)
    {
        return view('master.losstime.edit', compact('losstime'));
    }

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

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new LosstimeImport;
        Excel::import($import, $request->file('file'));

        return redirect()->route('master.losstime.index')
            ->with('success', "Import berhasil: {$import->imported} data diproses, {$import->skipped} baris dilewati.");
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['Kode', 'Jenis Losstime', 'Std Menit Default', 'Std Menit Progressive'];
            }

            public function array(): array
            {
                return LosstimeImport::CONTOH;
            }
        };

        return Excel::download($export, 'template_losstime.xlsx');
    }
}
