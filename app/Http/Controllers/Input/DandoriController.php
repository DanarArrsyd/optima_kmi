<?php

namespace App\Http\Controllers\Input;

use App\Exports\DandoriImportTemplate;
use App\Exports\LhpDandoriExport;
use App\Http\Controllers\Concerns\BacaFilterMesin;
use App\Http\Controllers\Concerns\MenjagaKembar;
use App\Http\Controllers\Concerns\ResolvesStaffNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDandoriBatchRequest;
use App\Imports\DandoriImport;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Services\DandoriRincianService;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class DandoriController extends Controller
{
    use BacaFilterMesin;
    use MenjagaKembar;
    use ResolvesStaffNote;

    public function __construct(
        private NotificationService $notifSvc,
        private DandoriRincianService $dandoriRincianService
    ) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $search = trim((string) $request->input('q', ''));

        /* Filter per kolom, sejajar dengan halaman LHP Produksi. Dandori punya
         * dua yang khas: mesin (satu pilihan, bukan jamak seperti produksi)
         * dan jenis loss time — pertanyaan paling sering di halaman ini adalah
         * "loss time jenis apa yang paling sering di mesin mana". */
        $filterKarId = (int) $request->input('filter_karyawan_id', 0);
        $mesinIds = $this->mesinIds($request, 'filter_mesin_id');
        $filterLosstimeId = (int) $request->input('filter_losstime_id', 0);
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterPartId = (int) $request->input('filter_part_id', 0);
        $filterProsesId = (int) $request->input('filter_proses_id', 0);

        /* Rentang tanggal bisa dimatikan lewat centang di dock. Saat mati,
         * seluruh riwayat ikut tersaring — tetap dipaginasi 50, jadi tidak
         * ada satu kueri raksasa. Bawaannya tetap NYALA supaya halaman tidak
         * membuka seluruh riwayat tanpa diminta. */
        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));

        $q = TransaksiDandoriRincian::with([
            'shift', 'karyawan', 'mesin', 'partProses.part', 'losstime', 'noteResolvedBy',
        ]);

        if ($pakaiTanggal) {
            $q->whereDate('tanggal', '>=', $dateFrom)
                ->whereDate('tanggal', '<=', $dateTo);
        }

        if ($filterKarId > 0) {
            $q->where('id_karyawan', $filterKarId);
        }
        if ($mesinIds) {
            $q->whereIn('id_mesin', $mesinIds);
        }
        if ($filterLosstimeId > 0) {
            $q->where('id_losstime', $filterLosstimeId);
        }
        if ($filterCustomer !== '') {
            $q->whereHas('partProses.part', fn ($p) => $p->where('customer', $filterCustomer));
        }
        if ($filterPartId > 0) {
            $q->whereHas('partProses', fn ($pp) => $pp->where('id_part', $filterPartId));
        }
        if ($filterProsesId > 0) {
            $q->where('id_part_proses', $filterProsesId);
        }

        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawan', fn ($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('mesin', fn ($m) => $m->where('id_mesin', 'like', $like)->orWhere('nama_mesin', 'like', $like))
                    ->orWhereHas('shift', fn ($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('losstime', fn ($l) => $l->where('kode', 'like', $like)->orWhere('jenis_losstime', 'like', $like))
                    ->orWhereHas('partProses', fn ($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProses.part', fn ($p) => $p->where('part_no', 'like', $like)
                        ->orWhere('part_name', 'like', $like)
                        ->orWhere('customer', 'like', $like)
                    );
            });
        }

        /* Note staff→admin gampang kelewat kalau tenggelam di tengah baris
         * per halaman lintas beberapa hari — badge di toolbar menghitung SEMUA
         * yang belum di-resolve dalam cakupan filter saat ini (bukan cuma
         * halaman ini), dan togglenya menyaring tabel supaya cuma baris itu
         * yang tampil. */
        $onlyOpenNotes = $request->boolean('only_open_notes');
        $openNotesCount = (clone $q)->whereNotNull('staff_note')->whereNull('staff_note_resolved_at')->count();
        if ($onlyOpenNotes) {
            $q->whereNotNull('staff_note')->whereNull('staff_note_resolved_at');
        }

        $highlightId = (int) $request->input('highlight_id', 0);
        if ($highlightId && ! $request->has('page')) {
            $target = TransaksiDandoriRincian::find($highlightId);
            if ($target) {
                $tgl = $target->tanggal->format('Y-m-d');
                $position = (clone $q)
                    ->where(function ($sub) use ($target, $tgl) {
                        $sub->whereDate('tanggal', '>', $tgl)
                            ->orWhere(function ($s) use ($target, $tgl) {
                                $s->whereDate('tanggal', $tgl)
                                    ->where(function ($s2) use ($target) {
                                        $s2->where('id_mesin', '<', $target->id_mesin)
                                            ->orWhere(function ($s3) use ($target) {
                                                $s3->where('id_mesin', $target->id_mesin)
                                                    ->where(function ($s4) use ($target) {
                                                        $s4->where('id_part_proses', '<', $target->id_part_proses)
                                                            ->orWhere(function ($s5) use ($target) {
                                                                $s5->where('id_part_proses', $target->id_part_proses)
                                                                    ->where('id', '<', $target->id);
                                                            });
                                                    });
                                            });
                                    });
                            });
                    })
                    ->count();
                $page = (int) ceil(($position + 1) / 50);
                if ($page > 1) {
                    return redirect()->to($request->fullUrlWithQuery(['page' => $page]));
                }
            }
        }

        $rincian = $q->orderByDesc('tanggal')
            ->orderBy('id_mesin')
            ->orderBy('id_part_proses')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        /* $shifts sengaja TIDAK diambil di sini: satu-satunya pemakainya
           adalah laci penulis yang sudah dicabut. Empat query master di bawah
           TETAP dibutuhkan — dock filter membangun $_operatorTA, $_mesinTA,
           $_losstimeTA, dan $_partsTA dari keempatnya. */
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();

        $myLastRecord = TransaksiDandoriRincian::with(['karyawan', 'mesin', 'partProses.part'])
            ->where('created_by', auth()->id())
            ->latest()
            ->first();
        $myLastOnPage = $myLastRecord
            ? $rincian->getCollection()->contains('id', $myLastRecord->id)
            : false;

        $customers = MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values();

        return view('input.dandori.index', compact(
            'rincian', 'dateFrom', 'dateTo', 'search',
            'karyawan', 'mesin', 'parts', 'losstimes', 'customers',
            'filterKarId', 'mesinIds', 'filterLosstimeId',
            'filterCustomer', 'filterPartId', 'filterProsesId', 'pakaiTanggal',
            'myLastRecord', 'myLastOnPage',
            'onlyOpenNotes', 'openNotesCount'
        ));
    }

    public function create()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();

        return view('input.dandori.create', compact('shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function batch(StoreDandoriBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        /* Satu sesi = N rincian = N baris tabel; tiap rincian diperiksa. */
        $rincian = fn (array $r) => array_map(fn (array $d) => [
            'tanggal' => $r['tanggal'], 'id_shift' => $r['id_shift'], 'id_karyawan' => $r['id_karyawan'],
            'id_mesin' => $r['id_mesin'], 'id_part_proses' => $r['id_part_proses'], ...$d,
        ], $r['rincian'] ?? []);

        if ($tolak = $this->tolakKalauKembar($request, $rows, [['dandori', $rincian]])) {
            return $tolak;
        }

        /* simpan() mengembalikan jumlah BARIS TABEL, bukan jumlah sesi: satu
         * sesi berisi N rincian menulis N baris. Yang dilaporkan ke operator
         * dan dipakai halaman daftar adalah angka baris tabel itu, supaya
         * cocok dengan apa yang nanti dia lihat di tabel. */
        $jumlah = DB::transaction(function () use ($rows) {
            $total = 0;
            foreach ($rows as $row) {
                $total += $this->dandoriRincianService->simpan([
                    'tanggal' => $row['tanggal'],
                    'id_shift' => $row['id_shift'],
                    'id_karyawan' => $row['id_karyawan'],
                    'id_mesin' => $row['id_mesin'],
                    'id_part_proses' => $row['id_part_proses'],
                ], $row['rincian']);
            }

            return $total;
        });

        /* batch_tersimpan adalah bukti sukses yang dibaca index.blade.php untuk
         * membuang draft di localStorage. Draft hanya boleh dibuang setelah
         * server MEMBUKTIKAN barisnya tersimpan — bukan cuma karena halaman
         * batch tidak melihat galat validasi, yang juga terjadi saat request
         * tidak pernah sampai sini (419 sesi habis, 500, jaringan putus). */
        return redirect()->route('input.dandori.index')
            ->with('success', "{$jumlah} baris data Dandori berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }

    public function edit(TransaksiDandoriRincian $dandori)
    {
        $dandori->load(['shift', 'karyawan', 'mesin', 'partProses.part', 'losstime']);
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
        $parts = MasterPart::with('proses')->orderBy('part_name')->get();
        $losstimes = MasterLosstime::orderBy('kode')->get();

        return view('input.dandori.edit', compact('dandori', 'shifts', 'karyawan', 'mesin', 'parts', 'losstimes'));
    }

    public function update(Request $request, TransaksiDandoriRincian $dandori)
    {
        $validated = $request->validate([
            // Batas atas `tomorrow`, bukan `today`: app timezone UTC, pabrik WIB (UTC+7).
            'tanggal' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'id_shift' => 'required|exists:master_shift,id',
            'id_karyawan' => 'required|exists:master_karyawan,id',
            'id_mesin' => 'required|exists:master_mesin,id',
            'id_part_proses' => 'required|exists:master_part_proses,id',
            'id_losstime' => 'required|exists:master_losstime,id',
            'actual_menit' => 'required|numeric|min:0',
        ], [
            'tanggal.after_or_equal' => 'Tanggal tidak masuk akal — periksa tahunnya (contoh salah ketik: 0026 untuk 2026).',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
        ]);

        $partProses = MasterPartProses::findOrFail($validated['id_part_proses']);
        $losstime = MasterLosstime::findOrFail($validated['id_losstime']);
        $isProgressive = strtoupper(trim($partProses->nama_proses)) === 'PROGRESSIVE';

        $stdMenit = $isProgressive
            ? ($losstime->std_menit_progressive ?? $losstime->std_menit_default ?? 0)
            : ($losstime->std_menit_default ?? 0);
        $actualMenit = (float) $validated['actual_menit'];

        $dandori->update([
            ...$validated,
            'std_menit' => $stdMenit,
            'over_menit' => max(0, $actualMenit - $stdMenit),
        ]);

        $this->resolveStaffNoteIfRequested(
            $request, $dandori, 'dandori',
            $dandori->tanggal->format('Y-m-d')
        );

        return redirect()->route('input.dandori.index', $this->backQueryParams($request))
            ->with('success', 'Data Dandori berhasil diupdate.');
    }

    /**
     * Halaman Edit adalah navigasi terpisah dari index (beda dari note/destroy
     * yang formnya nempel di index sendiri, jadi back() saja cukup) — begitu
     * submit selesai di sini, index tidak tahu lagi rentang tanggal/filter apa
     * yang sedang dilihat user sebelum klik Edit. `back_query` dibawa sebagai
     * hidden field dari query string index saat link Edit di-klik, supaya user
     * yang lagi meriksa data bulan Juli tidak dilempar balik ke default bulan
     * berjalan (September) begitu submit — dia balik ke Juli lagi.
     */
    private function backQueryParams(Request $request): array
    {
        parse_str((string) $request->input('back_query', ''), $params);

        return collect($params)->only([
            'date_from', 'date_to', 'q',
            'filter_karyawan_id', 'filter_mesin_id', 'mesin_ids', 'filter_losstime_id',
            'filter_customer', 'filter_part_id', 'filter_proses_id',
            'pakai_tanggal', 'filter_apply', 'only_open_notes', 'page',
        ])->toArray();
    }

    public function note(Request $request)
    {
        $request->validate(['staff_note' => 'nullable|string|max:1000', 'record_id' => 'required|integer']);
        $dandori = TransaksiDandoriRincian::find($request->input('record_id'));
        if (! $dandori) {
            return back()->with('error', 'Data tidak ditemukan.');
        }
        if ($dandori->staff_note_resolved_at) {
            return back()->with('error', 'Note sudah di-feedback admin, tidak dapat diubah.');
        }
        try {
            $dandori->update([
                'staff_note' => $request->input('staff_note') ?: null,
                'staff_note_at' => $request->input('staff_note') ? now() : null,
                'staff_note_by' => $request->input('staff_note') ? auth()->id() : null,
            ]);
        } catch (\Throwable) { /* kolom belum ada di hosting */
        }
        if ($request->input('staff_note')) {
            try {
                $dandori->refresh();
                $this->notifSvc->insertNoteSubmitted(
                    $dandori, 'dandori',
                    $dandori->tanggal->format('Y-m-d')
                );
            } catch (\Throwable) { /* notification failure must not break main flow */
            }
        }
        $msg = $request->input('staff_note') ? 'Note berhasil dikirim ke admin.' : 'Note berhasil dihapus.';

        // Form note dikirim langsung dari halaman index (drawer) — back() balik
        // ke URL persis itu (rentang tanggal/filter ikut), bukan reset ke default.
        return back(fallback: route('input.dandori.index'))->with('success', $msg);
    }

    public function resolveNote(Request $request)
    {
        $request->validate(['record_id' => 'required|integer']);
        $dandori = TransaksiDandoriRincian::find($request->input('record_id'));
        if (! $dandori) {
            return back()->with('error', 'Data tidak ditemukan.');
        }
        try {
            $dandori->update([
                'staff_note_resolved_at' => now(),
                'staff_note_resolved_by' => auth()->id(),
            ]);
        } catch (\Throwable) {
        }
        $dandori->refresh();
        try {
            $this->notifSvc->insertNoteResolved(
                $dandori, 'dandori',
                $dandori->tanggal->format('Y-m-d')
            );
        } catch (\Throwable) { /* notification failure must not break main flow */
        }

        return back(fallback: route('input.dandori.index'))->with('success', 'Note ditandai sudah ditangani.');
    }

    public function destroy(TransaksiDandoriRincian $dandori)
    {
        if (! auth()->user()->hasRole('admin') && $dandori->created_by !== auth()->id()) {
            abort(403);
        }
        $dandori->delete();

        // Form hapus juga dikirim langsung dari halaman index (baris tabel).
        return back(fallback: route('input.dandori.index'))->with('success', 'Baris data berhasil dihapus.');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ], [
            'file.required' => 'Pilih file Excel terlebih dahulu.',
            'file.mimes' => 'File harus berformat .xlsx, .xls, atau .csv.',
            'file.max' => 'Ukuran file maksimal 10 MB.',
        ]);

        $import = new DandoriImport;
        Excel::import($import, $request->file('file'));

        $msg = "{$import->importedCount} baris data Dandori berhasil diimport.";

        return redirect()->route('input.dandori.index')
            ->with('import_success', $msg)
            ->with('import_errors', $import->errors);
    }

    public function export(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $customer = $request->input('customer') ?: null;
        $partName = $request->input('part_name') ?: null;
        $namaProses = $request->input('nama_proses') ?: null;

        $filename = 'Dandori_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(
            new LhpDandoriExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function importTemplate()
    {
        return Excel::download(new DandoriImportTemplate, 'template-import-dandori.xlsx');
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
