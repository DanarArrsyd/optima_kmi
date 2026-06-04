<?php
namespace App\Http\Controllers\Input;

use App\Exports\DandoriImportTemplate;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDandoriRequest;
use App\Exports\LhpDandoriExport;
use App\Imports\DandoriImport;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DandoriController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));

        $rincian = TransaksiDandoriRincian::with([
                'shift', 'karyawan', 'mesin', 'partProses.part', 'losstime',
            ])
            ->whereDate('tanggal', '>=', $dateFrom)
            ->whereDate('tanggal', '<=', $dateTo)
            ->orderByDesc('tanggal')
            ->orderBy('id_mesin')
            ->orderBy('id_part_proses')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin     = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts     = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();

        return view('input.dandori.index', compact('rincian', 'dateFrom', 'dateTo', 'shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function create()
    {
        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get();
        $mesin     = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts     = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();

        return view('input.dandori.create', compact('shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function store(StoreDandoriRequest $request)
    {
        $v = $request->validated();

        $partProses    = MasterPartProses::findOrFail($v['id_part_proses']);
        $isProgressive = strtoupper(trim($partProses->nama_proses)) === 'PROGRESSIVE';

        $losstimeIds = collect($v['rincian'])->pluck('id_losstime')->unique();
        $losstimeMap = MasterLosstime::whereIn('id', $losstimeIds)->get()->keyBy('id');

        foreach ($v['rincian'] as $item) {
            $losstime   = $losstimeMap->get($item['id_losstime']);
            $stdMenit   = $isProgressive
                ? ($losstime->std_menit_progressive ?? $losstime->std_menit_default ?? 0)
                : ($losstime->std_menit_default ?? 0);
            $actualMenit = (float) $item['actual_menit'];

            TransaksiDandoriRincian::create([
                'tanggal'        => $v['tanggal'],
                'id_shift'       => $v['id_shift'],
                'id_karyawan'    => $v['id_karyawan'],
                'id_mesin'       => $v['id_mesin'],
                'id_part_proses' => $v['id_part_proses'],
                'id_losstime'    => $item['id_losstime'],
                'actual_menit'   => $actualMenit,
                'std_menit'      => $stdMenit,
                'over_menit'     => max(0, $actualMenit - $stdMenit),
                'created_by'     => auth()->id(),
            ]);
        }

        $count = count($v['rincian']);
        return redirect()->route('input.dandori.index')
            ->with('success', "{$count} baris data Dandori berhasil disimpan.");
    }

    public function destroy(TransaksiDandoriRincian $dandori)
    {
        if (auth()->user()->role !== 'admin' && $dandori->created_by !== auth()->id()) {
            abort(403);
        }
        $dandori->delete();
        return redirect()->route('input.dandori.index')->with('success', 'Baris data berhasil dihapus.');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ], [
            'file.required' => 'Pilih file Excel terlebih dahulu.',
            'file.mimes'    => 'File harus berformat .xlsx, .xls, atau .csv.',
            'file.max'      => 'Ukuran file maksimal 10 MB.',
        ]);

        $import = new DandoriImport();
        Excel::import($import, $request->file('file'));

        $msg = "{$import->importedCount} baris data Dandori berhasil diimport.";

        return redirect()->route('input.dandori.index')
            ->with('import_success', $msg)
            ->with('import_errors', $import->errors);
    }

    public function export(Request $request)
    {
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $customer   = $request->input('customer')    ?: null;
        $partName   = $request->input('part_name')   ?: null;
        $namaProses = $request->input('nama_proses') ?: null;

        $filename = 'Dandori_' . now()->format('Ymd_His') . '.xlsx';
        return \Maatwebsite\Excel\Facades\Excel::download(
            new LhpDandoriExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function importTemplate()
    {
        return Excel::download(new DandoriImportTemplate(), 'template-import-dandori.xlsx');
    }

    /** AJAX: proses list for a part (for form JS) */
    public function getPartProses(MasterPart $part)
    {
        return response()->json(
            $part->proses()
                ->select('id', 'id_proses', 'nama_proses', 'std_setting_dies_menit')
                ->get()
        );
    }
}
