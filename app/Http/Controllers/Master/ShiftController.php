<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterShiftRequest;
use App\Imports\ShiftImport;
use App\Models\MasterShift;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->input('q', ''));
        $filterAvail = trim((string) $request->input('filter_avail', ''));

        $shifts = MasterShift::with('istirahat')
            ->when($search !== '', fn ($q) => $q->where('nama_shift', 'like', '%'.$search.'%'))
            ->when($filterAvail !== '', fn ($q) => $q->where('dihitung_availability', $filterAvail === 'ya'))
            ->orderBy('jam_start')
            ->paginate(20)->withQueryString();

        return view('master.shift.index', compact('shifts', 'search', 'filterAvail'));
    }

    public function create()
    {
        return view('master.shift.create');
    }

    public function store(StoreMasterShiftRequest $request)
    {
        // 'dihitung_availability' sempat ketinggalan dari whitelist ini — checkbox-nya
        // ada di form (drawer lama nol punya sama sekali, create.blade.php punya tapi
        // percuma) dan StoreMasterShiftRequest sudah menormalkannya jadi boolean,
        // tapi nol pernah ikut ke MasterShift::create() sama sekali. Kolom selalu
        // jatuh ke default DB (true), berapa pun checkbox-nya dicentang.
        $shift = MasterShift::create($request->only([
            'nama_shift',
            'jam_start', 'jam_finish',
            'jam_start_jumat', 'jam_finish_jumat',
            'jam_start_sabtu', 'jam_finish_sabtu',
            'dihitung_availability',
        ]));

        $this->syncIstirahat($shift, $request);

        return redirect()->route('master.shift.index')
            ->with('success', 'Shift berhasil ditambahkan.');
    }

    public function edit(MasterShift $shift)
    {
        $shift->load('istirahat');

        return view('master.shift.edit', compact('shift'));
    }

    public function update(StoreMasterShiftRequest $request, MasterShift $shift)
    {
        // Sama seperti store() — 'dihitung_availability' ketinggalan dari whitelist,
        // jadi mencentang/melepas centang di halaman edit nol pernah tersimpan.
        $shift->update($request->only([
            'nama_shift',
            'jam_start', 'jam_finish',
            'jam_start_jumat', 'jam_finish_jumat',
            'jam_start_sabtu', 'jam_finish_sabtu',
            'dihitung_availability',
        ]));

        $this->syncIstirahat($shift, $request);

        return redirect()->route('master.shift.index')
            ->with('success', 'Shift berhasil diupdate.');
    }

    public function destroy(MasterShift $shift)
    {
        $shift->delete();

        return redirect()->route('master.shift.index')
            ->with('success', 'Shift berhasil dihapus.');
    }

    /** Hapus semua istirahat lama lalu buat ulang dari request. */
    private function syncIstirahat(MasterShift $shift, Request $request): void
    {
        $shift->istirahat()->delete();

        foreach (['senin_kamis', 'jumat', 'sabtu'] as $kel) {
            foreach ($request->input("istirahat.{$kel}", []) as $row) {
                $start = $row['jam_start'] ?? '';
                $finish = $row['jam_finish'] ?? '';
                if ($start && $finish) {
                    $shift->istirahat()->create([
                        'kelompok_hari' => $kel,
                        'jam_start' => $start,
                        'jam_finish' => $finish,
                    ]);
                }
            }
        }
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new ShiftImport;
        Excel::import($import, $request->file('file'));

        return redirect()->route('master.shift.index')
            ->with('success', "Import berhasil: {$import->imported} data diproses, {$import->skipped} baris dilewati.");
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return ['Nama Shift', 'Jam Start (Senin-Kamis)', 'Jam Finish (Senin-Kamis)'];
            }

            public function array(): array
            {
                return [
                    ['Shift Pagi', '07:00', '15:00'],
                    ['Shift Siang', '15:00', '22:00'],
                    ['Shift Malam', '22:00', '06:00'],
                ];
            }
        };

        return Excel::download($export, 'template_shift.xlsx');
    }
}
