<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterKaryawanRequest;
use App\Imports\KaryawanImport;
use App\Models\MasterKaryawan;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
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

        // Kode bisa live sebelum migrasi menyusul — halaman tetap harus hidup.
        $adaKolomTanggal = Schema::hasColumn('master_karyawan', 'tanggal_nonaktif');

        return view('master.karyawan.index', compact('karyawan', 'filter', 'search', 'filterDept', 'deptList', 'adaKolomTanggal'));
    }

    public function toggleAktif(Request $request, MasterKaryawan $karyawan)
    {
        // App jalan UTC, pabrik WIB — 'today' bawaan Laravel memakai tanggal UTC,
        // yang tujuh jam di belakang WIB. Pakai tanggal WIB biar "hari ini" versi
        // pabrik tidak ditolak sebagai tanggal masa depan.
        $data = $request->validate([
            'tanggal_nonaktif' => ['nullable', 'date', 'before_or_equal:'.now('Asia/Jakarta')->toDateString()],
        ]);

        try {
            if ($karyawan->is_aktif) {
                // Tanggal boleh dimundurkan: laporan karyawan keluar sering telat
                // berhari-hari, dan tanggal klik akan membuat dropdown VCB tetap
                // memunculkannya sepanjang selisih itu.
                // Fallback juga pakai tanggal WIB, bukan today() yang mengikuti UTC —
                // supaya konsisten dengan validasi di atas dan prefill di form.
                $karyawan->update([
                    'is_aktif' => false,
                    'tanggal_nonaktif' => $data['tanggal_nonaktif'] ?? now('Asia/Jakarta')->toDateString(),
                ]);
                $status = 'dinonaktifkan';
            } else {
                // Aktif kembali WAJIB mengosongkan tanggal — tanggal basi pada orang
                // aktif akan menyaringnya keluar dari dropdown VCB tanpa ada yang sadar.
                $karyawan->update(['is_aktif' => true, 'tanggal_nonaktif' => null]);
                $status = 'diaktifkan kembali';
            }

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
