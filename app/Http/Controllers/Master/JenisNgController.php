<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Imports\JenisNgImport;
use App\Models\MasterJenisNg;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class JenisNgController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $filterKategori = trim((string) $request->input('filter_kategori', ''));

        $jenisNg = MasterJenisNg::query()
            ->when($search !== '', fn ($q) => $q->where(fn ($s) => $s
                ->where('kode', 'like', '%'.$search.'%')
                ->orWhere('nama_ng', 'like', '%'.$search.'%')))
            ->when($filterKategori !== '', fn ($q) => $q->where('kategori', $filterKategori))
            ->orderBy('kode')
            ->paginate(30)->withQueryString();

        $kategoriList = MasterJenisNg::distinct()->orderBy('kategori')->pluck('kategori')->filter()->values();

        return view('master.jenis-ng.index', compact('jenisNg', 'search', 'filterKategori', 'kategoriList'));
    }

    public function create()
    {
        return view('master.jenis-ng.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'kode' => 'required|string|max:20|unique:master_jenis_ng,kode',
            'nama_ng' => 'required|string|max:100',
            'deskripsi' => 'nullable|string|max:255',
        ]);
        MasterJenisNg::create($data);

        return redirect()->route('master.jenis-ng.index')->with('success', 'Jenis NG berhasil ditambahkan.');
    }

    public function edit(MasterJenisNg $jenisNg)
    {
        return view('master.jenis-ng.edit', compact('jenisNg'));
    }

    public function update(Request $request, MasterJenisNg $jenisNg)
    {
        $data = $request->validate([
            'kode' => 'required|string|max:20|unique:master_jenis_ng,kode,'.$jenisNg->id,
            'nama_ng' => 'required|string|max:100',
            'deskripsi' => 'nullable|string|max:255',
        ]);
        $jenisNg->update($data);

        return redirect()->route('master.jenis-ng.index')->with('success', 'Jenis NG berhasil diupdate.');
    }

    public function destroy(MasterJenisNg $jenisNg)
    {
        $jenisNg->delete();

        return redirect()->route('master.jenis-ng.index')->with('success', 'Jenis NG berhasil dihapus.');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new JenisNgImport;
        Excel::import($import, $request->file('file'));

        return redirect()->route('master.jenis-ng.index')
            ->with('success', "Import berhasil: {$import->imported} data diproses, {$import->skipped} baris dilewati.");
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['Kode', 'Nama NG', 'Deskripsi'];
            }

            public function array(): array
            {
                return JenisNgImport::CONTOH;
            }
        };

        return Excel::download($export, 'template_jenis_ng.xlsx');
    }
}
