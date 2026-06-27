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
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DandoriController extends Controller
{
    public function __construct(private NotificationService $notifSvc) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo   = $request->input('date_to',   date('Y-m-d'));
        $search   = trim((string) $request->input('q', ''));

        $q = TransaksiDandoriRincian::with([
                'shift', 'karyawan', 'mesin', 'partProses.part', 'losstime', 'noteResolvedBy',
            ])
            ->whereDate('tanggal', '>=', $dateFrom)
            ->whereDate('tanggal', '<=', $dateTo);

        if ($search !== '') {
            $like = '%' . $search . '%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawan',       fn($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('mesin',         fn($m) => $m->where('id_mesin', 'like', $like)->orWhere('nama_mesin', 'like', $like))
                    ->orWhereHas('shift',         fn($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('losstime',      fn($l) => $l->where('kode', 'like', $like)->orWhere('jenis_losstime', 'like', $like))
                    ->orWhereHas('partProses',    fn($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProses.part', fn($p) =>
                        $p->where('part_no',   'like', $like)
                          ->orWhere('part_name', 'like', $like)
                          ->orWhere('customer',  'like', $like)
                    );
            });
        }

        $rincian = $q->orderByDesc('tanggal')
            ->orderBy('id_mesin')
            ->orderBy('id_part_proses')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin     = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts     = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();

        $myLastRecord = TransaksiDandoriRincian::with(['karyawan', 'mesin', 'partProses.part'])
            ->where('created_by', auth()->id())
            ->latest()
            ->first();
        $myLastOnPage = $myLastRecord
            ? $rincian->getCollection()->contains('id', $myLastRecord->id)
            : false;

        return view('input.dandori.index', compact('rincian', 'dateFrom', 'dateTo', 'search', 'shifts', 'karyawan', 'mesin', 'parts', 'losstimes', 'myLastRecord', 'myLastOnPage'));
    }

    public function create()
    {
        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
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

    public function edit(TransaksiDandoriRincian $dandori)
    {
        $dandori->load(['shift', 'karyawan', 'mesin', 'partProses.part', 'losstime']);
        $shifts    = MasterShift::orderBy('jam_start')->get();
        $karyawan  = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin     = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts     = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();
        return view('input.dandori.edit', compact('dandori', 'shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function update(Request $request, TransaksiDandoriRincian $dandori)
    {
        $validated = $request->validate([
            'tanggal'        => 'required|date',
            'id_shift'       => 'required|exists:master_shifts,id',
            'id_karyawan'    => 'required|exists:master_karyawan,id',
            'id_mesin'       => 'required|exists:master_mesin,id',
            'id_part_proses' => 'required|exists:master_part_proses,id',
            'id_losstime'    => 'required|exists:master_losstime,id',
            'actual_menit'   => 'required|numeric|min:0',
        ]);

        $partProses    = MasterPartProses::findOrFail($validated['id_part_proses']);
        $losstime      = MasterLosstime::findOrFail($validated['id_losstime']);
        $isProgressive = strtoupper(trim($partProses->nama_proses)) === 'PROGRESSIVE';

        $stdMenit    = $isProgressive
            ? ($losstime->std_menit_progressive ?? $losstime->std_menit_default ?? 0)
            : ($losstime->std_menit_default ?? 0);
        $actualMenit = (float) $validated['actual_menit'];

        $dandori->update([
            ...$validated,
            'std_menit'  => $stdMenit,
            'over_menit' => max(0, $actualMenit - $stdMenit),
        ]);

        return redirect()->route('input.dandori.index')->with('success', 'Data Dandori berhasil diupdate.');
    }

    public function note(\Illuminate\Http\Request $request)
    {
        $request->validate(['staff_note' => 'nullable|string|max:1000', 'record_id' => 'required|integer']);
        $dandori = TransaksiDandoriRincian::find($request->input('record_id'));
        if (!$dandori) return back()->with('error', 'Data tidak ditemukan.');
        if ($dandori->staff_note_resolved_at) {
            return back()->with('error', 'Note sudah di-feedback admin, tidak dapat diubah.');
        }
        try {
            $dandori->update([
                'staff_note'    => $request->input('staff_note') ?: null,
                'staff_note_at' => $request->input('staff_note') ? now() : null,
                'staff_note_by' => $request->input('staff_note') ? auth()->id() : null,
            ]);
        } catch (\Throwable) { /* kolom belum ada di hosting */ }
        if ($request->input('staff_note')) {
            try {
                $dandori->refresh();
                $this->notifSvc->insertNoteSubmitted(
                    $dandori, 'dandori',
                    $dandori->tanggal->format('Y-m-d')
                );
            } catch (\Throwable) { /* notification failure must not break main flow */ }
        }
        $msg = $request->input('staff_note') ? 'Note berhasil dikirim ke admin.' : 'Note berhasil dihapus.';
        return redirect()->route('input.dandori.index')->with('success', $msg);
    }

    public function resolveNote(\Illuminate\Http\Request $request)
    {
        $request->validate(['record_id' => 'required|integer']);
        $dandori = TransaksiDandoriRincian::find($request->input('record_id'));
        if (!$dandori) return back()->with('error', 'Data tidak ditemukan.');
        try {
            $dandori->update([
                'staff_note_resolved_at' => now(),
                'staff_note_resolved_by' => auth()->id(),
            ]);
        } catch (\Throwable) {}
        $dandori->refresh();
        try {
            $this->notifSvc->insertNoteResolved(
                $dandori, 'dandori',
                $dandori->tanggal->format('Y-m-d')
            );
        } catch (\Throwable) { /* notification failure must not break main flow */ }
        return redirect()->route('input.dandori.index')->with('success', 'Note ditandai sudah ditangani.');
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
