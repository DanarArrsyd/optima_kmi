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
    public function index()
    {
        $jenisNg = MasterJenisNg::orderBy('kode')->paginate(30);

        return view('master.jenis-ng.index', compact('jenisNg'));
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
                return [
                    ['DIM', 'Dimensi Over/Under', 'Ukuran tidak sesuai spesifikasi'],
                    ['BURR', 'Burr/Flash', 'Sisa material pada tepi produk'],
                    ['SCRATCH', 'Goresan', 'Permukaan tergores'],
                    ['DENT', 'Penyok', 'Permukaan penyok/deformasi'],
                    ['CRACK', 'Retak', 'Material retak/pecah'],
                ];
            }
        };

        return Excel::download($export, 'template_jenis_ng.xlsx');
    }
}
