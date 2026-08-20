<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterKaryawanRequest;
use App\Imports\KaryawanImport;
use App\Models\MasterKaryawan;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class KaryawanController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->input('filter', 'semua');
        $search = trim((string) $request->input('q', ''));
        $filterDept = trim((string) $request->input('filter_dept', ''));

        $q = MasterKaryawan::orderBy('nama_karyawan')
            ->when($search !== '', fn ($x) => $x->where(fn ($s) => $s
                ->where('nik', 'like', '%'.$search.'%')
                ->orWhere('nama_karyawan', 'like', '%'.$search.'%')))
            ->when($filterDept !== '', fn ($x) => $x->where('departemen', $filterDept));

        if ($filter === 'aktif') {
            $q->where('is_aktif', true);
        } elseif ($filter === 'nonaktif') {
            $q->where('is_aktif', false);
        }

        try {
            $karyawan = $q->paginate(20)->withQueryString();
        } catch (QueryException) {
            // Kolom is_aktif belum ada — fallback tanpa filter
            $filter = 'semua';
            $karyawan = MasterKaryawan::orderBy('nama_karyawan')->paginate(20)->withQueryString();
        }

        $deptList = MasterKaryawan::distinct()->orderBy('departemen')->pluck('departemen')->filter()->values();

        return view('master.karyawan.index', compact('karyawan', 'filter', 'search', 'filterDept', 'deptList'));
    }

    public function toggleAktif(MasterKaryawan $karyawan)
    {
        try {
            $karyawan->update(['is_aktif' => ! $karyawan->is_aktif]);
            $status = $karyawan->is_aktif ? 'diaktifkan kembali' : 'dinonaktifkan';

            return redirect()->back()->with('success', "Karyawan {$karyawan->nama_karyawan} berhasil {$status}.");
        } catch (QueryException) {
            return redirect()->back()->with('error', 'Fitur ini memerlukan update database. Jalankan SQL ALTER TABLE di phpMyAdmin terlebih dahulu.');
        }
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
        $export = new class implements FromArray, WithHeadings
        {
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
