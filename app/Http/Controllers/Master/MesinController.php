<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterMesinRequest;
use App\Imports\MesinImport;
use App\Models\MasterMesin;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class MesinController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $filterLine = trim((string) $request->input('filter_line', ''));
        $filterTipe = trim((string) $request->input('filter_tipe', ''));

        $mesin = MasterMesin::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('id_mesin', 'like', '%'.$search.'%')
                ->orWhere('nama_mesin', 'like', '%'.$search.'%')))
            ->when($filterLine !== '', fn ($q) => $q->where('line', $filterLine))
            ->when($filterTipe !== '', fn ($q) => $q->where('tipe_mesin', $filterTipe))
            ->orderBy('line')->orderBy('id_mesin')
            ->paginate(20)->withQueryString();

        /* Pilihan diambil dari isi tabel, bukan daftar tertulis: line dan tipe
         * mesin bertambah lewat import, dan daftar tertulis akan ketinggalan. */
        $lineList = MasterMesin::distinct()->orderBy('line')->pluck('line')->filter()->values();
        $tipeList = MasterMesin::distinct()->orderBy('tipe_mesin')->pluck('tipe_mesin')->filter()->values();

        return view('master.mesin.index', compact('mesin', 'search', 'filterLine', 'filterTipe', 'lineList', 'tipeList'));
    }

    public function create()
    {
        return view('master.mesin.create');
    }

    public function store(StoreMasterMesinRequest $request)
    {
        MasterMesin::create($request->validated());

        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil ditambahkan.');
    }

    public function edit(MasterMesin $mesin)
    {
        return view('master.mesin.edit', compact('mesin'));
    }

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
        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['ID Mesin', 'Nama Mesin', 'Line', 'Tipe Mesin', 'Kapasitas Max Stroke', 'Tanggal Instalasi'];
            }

            public function array(): array
            {
                return [
                    ['M001', 'Press 80T #1', 'Line A', 'Progressive', 800000, '2024-01-15'],
                    ['M002', 'Press 110T #1', 'Line B', 'Progressive', 1100000, '2023-06-01'],
                ];
            }
        };

        return Excel::download($export, 'template_mesin.xlsx');
    }
}
