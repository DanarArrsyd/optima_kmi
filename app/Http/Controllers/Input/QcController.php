<?php

namespace App\Http\Controllers\Input;

use App\Exports\LhpQcExport;
use App\Exports\QcImportTemplate;
use App\Http\Controllers\Concerns\ResolvesStaffNote;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreQcBatchRequest;
use App\Http\Requests\UpdateQcRequest;
use App\Imports\QcImport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Services\NgDetailService;
use App\Services\NotificationService;
use App\Services\QcService;
use App\Services\QcWriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class QcController extends Controller
{
    use ResolvesStaffNote;

    public function __construct(
        private QcService $qcService,
        private NotificationService $notifSvc,
        private NgDetailService $ngDetailService,
        private QcWriteService $qcWriteService,
    ) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $search = trim((string) $request->input('q', ''));

        /* Filter per kolom di dock. Rentang tanggal bisa dimatikan lewat
         * centang; bawaannya nyala supaya halaman tidak membuka seluruh
         * riwayat tanpa diminta. Yang disaring tgl_pengecekan, bukan
         * tgl_produksi_part — pertanyaan di halaman ini "diperiksa kapan". */
        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));
        $filterKarId = (int) $request->input('filter_karyawan_id', 0);
        $filterJenisNgId = (int) $request->input('filter_jenis_ng_id', 0);
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterPartId = (int) $request->input('filter_part_id', 0);
        $filterProsesId = (int) $request->input('filter_proses_id', 0);

        $q = TransaksiQc::with(['karyawanQc', 'partProses.part', 'shift', 'ngDetails.jenisNg', 'noteResolvedBy']);

        if ($pakaiTanggal) {
            $q->whereDate('tgl_pengecekan', '>=', $dateFrom)
                ->whereDate('tgl_pengecekan', '<=', $dateTo);
        }
        if ($filterKarId > 0) {
            $q->where('id_karyawan_qc', $filterKarId);
        }
        if ($filterJenisNgId > 0) {
            $q->whereHas('ngDetails', fn ($d) => $d->where('id_jenis_ng', $filterJenisNgId));
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
                $sub->whereHas('karyawanQc', fn ($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('shift', fn ($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('partProses', fn ($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProses.part', fn ($p) => $p->where('part_no', 'like', $like)
                        ->orWhere('part_name', 'like', $like)
                        ->orWhere('customer', 'like', $like)
                    );
            });
        }

        $highlightId = (int) $request->input('highlight_id', 0);
        if ($highlightId && ! $request->has('page')) {
            $target = TransaksiQc::find($highlightId);
            if ($target) {
                $position = (clone $q)
                    ->where(function ($sub) use ($target) {
                        $sub->whereDate('tgl_pengecekan', '>', $target->tgl_pengecekan)
                            ->orWhere(function ($s) use ($target) {
                                $s->whereDate('tgl_pengecekan', $target->tgl_pengecekan->format('Y-m-d'))
                                    ->where('created_at', '>', $target->created_at);
                            });
                    })
                    ->count();
                $page = (int) ceil(($position + 1) / 30);
                if ($page > 1) {
                    return redirect()->to($request->fullUrlWithQuery(['page' => $page]));
                }
            }
        }

        $transaksi = $q->orderByDesc('tgl_pengecekan')
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $karyawan = MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with(['proses' => fn ($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                $part->setRelation('proses', $part->proses->take(1));

                return $part;
            });
        $jenisNg = MasterJenisNg::orderBy('kode')->get();

        $myLastRecord = TransaksiQc::with(['karyawanQc', 'partProses.part'])
            ->where('created_by', auth()->id())
            ->latest()
            ->first();
        $myLastOnPage = $myLastRecord
            ? $transaksi->getCollection()->contains('id', $myLastRecord->id)
            : false;

        $customers = MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values();

        return view('input.qc.index', compact(
            'transaksi', 'dateFrom', 'dateTo', 'search',
            'karyawan', 'parts', 'jenisNg', 'customers',
            'pakaiTanggal', 'filterKarId', 'filterJenisNgId',
            'filterCustomer', 'filterPartId', 'filterProsesId',
            'myLastRecord', 'myLastOnPage'
        ));
    }

    public function create()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get();

        // Finish-good logic: QC hanya inspect produk jadi (proses terakhir = id_proses tertinggi)
        $parts = MasterPart::with(['proses' => fn ($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                // Sisakan hanya proses terakhir (id_proses max) per part
                $part->setRelation('proses', $part->proses->take(1));

                return $part;
            });

        $jenisNg = MasterJenisNg::orderBy('kode')->get();

        return view('input.qc.create', compact('shifts', 'karyawan', 'parts', 'jenisNg'));
    }

    /**
     * Menyimpan banyak baris LHP Quality sekali jalan dari halaman batch.
     *
     * Satu transaksi untuk seluruh baris: draft di browser tetap utuh saat
     * gagal, jadi menyimpan separuh akan membuat inspector mengirim ulang
     * baris yang sudah masuk.
     */
    public function batch(StoreQcBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->qcWriteService->simpan($row);
            }
        });

        $jumlah = count($rows);

        /* batch_tersimpan adalah bukti sukses yang dibaca index.blade.php untuk
         * membuang draft di localStorage. Draft hanya boleh dibuang setelah
         * server MEMBUKTIKAN barisnya tersimpan — bukan cuma karena halaman
         * batch tidak melihat galat validasi, yang juga terjadi saat request
         * tidak pernah sampai sini (419 sesi habis, 500, jaringan putus). */
        return redirect()->route('input.qc.index')
            ->with('success', "{$jumlah} baris LHP Quality berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }

    public function edit(TransaksiQc $qc)
    {
        $relations = ['karyawanQc', 'partProses.part.proses'];
        if (NgDetailService::available()) {
            $relations[] = 'ngDetails.jenisNg';
        }
        $qc->load($relations);
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with(['proses' => fn ($q) => $q->orderByDesc('id_proses')])
            ->has('proses')
            ->orderBy('part_name')
            ->get()
            ->map(function ($part) {
                $part->setRelation('proses', $part->proses->take(1));

                return $part;
            });
        $jenisNg = MasterJenisNg::orderBy('kode')->get();

        return view('input.qc.edit', compact('qc', 'shifts', 'karyawan', 'parts', 'jenisNg'));
    }

    public function update(UpdateQcRequest $request, TransaksiQc $qc)
    {
        $validated = $request->validated();
        $ngRows = $validated['ng'] ?? [];
        unset($validated['ng'], $validated['ng_count']);

        $qtyNg = array_sum(array_column($ngRows, 'qty'));

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            (int) $validated['qty_ok'],
            (int) $qtyNg
        );

        DB::transaction(function () use ($validated, $cycleTime, $ngRows, $qc) {
            $qc->update([
                ...$validated,
                'actual_cycle_time' => $cycleTime,
            ]);

            $this->ngDetailService->syncQc($qc, $ngRows);
        });

        $this->resolveStaffNoteIfRequested(
            $request, $qc, 'qc',
            $qc->tgl_pengecekan->format('Y-m-d')
        );

        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil diupdate.');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|mimes:xlsx,xls,csv|max:10240']);

        $import = new QcImport;
        Excel::import($import, $request->file('file'));

        $msg = "{$import->importedCount} data LHP Quality berhasil diimport.";

        return redirect()->route('input.qc.index')
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

        $filename = 'LHP_Quality_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(
            new LhpQcExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function importTemplate()
    {
        return Excel::download(new QcImportTemplate, 'template_lhp_quality.xlsx');
    }

    public function note(Request $request)
    {
        $request->validate(['staff_note' => 'nullable|string|max:1000', 'record_id' => 'required|integer']);
        $qc = TransaksiQc::find($request->input('record_id'));
        if (! $qc) {
            return back()->with('error', 'Data tidak ditemukan.');
        }
        if ($qc->staff_note_resolved_at) {
            return back()->with('error', 'Note sudah di-feedback admin, tidak dapat diubah.');
        }
        try {
            $qc->update([
                'staff_note' => $request->input('staff_note') ?: null,
                'staff_note_at' => $request->input('staff_note') ? now() : null,
                'staff_note_by' => $request->input('staff_note') ? auth()->id() : null,
            ]);
        } catch (\Throwable) { /* kolom belum ada di hosting */
        }
        if ($request->input('staff_note')) {
            try {
                $qc->refresh();
                $this->notifSvc->insertNoteSubmitted(
                    $qc, 'qc',
                    $qc->tgl_pengecekan->format('Y-m-d')
                );
            } catch (\Throwable) { /* notification failure must not break main flow */
            }
        }
        $msg = $request->input('staff_note') ? 'Note berhasil dikirim ke admin.' : 'Note berhasil dihapus.';

        return redirect()->route('input.qc.index')->with('success', $msg);
    }

    public function resolveNote(Request $request)
    {
        $request->validate(['record_id' => 'required|integer']);
        $qc = TransaksiQc::find($request->input('record_id'));
        if (! $qc) {
            return back()->with('error', 'Data tidak ditemukan.');
        }
        try {
            $qc->update([
                'staff_note_resolved_at' => now(),
                'staff_note_resolved_by' => auth()->id(),
            ]);
        } catch (\Throwable) {
        }
        $qc->refresh();
        try {
            $this->notifSvc->insertNoteResolved(
                $qc, 'qc',
                $qc->tgl_pengecekan->format('Y-m-d')
            );
        } catch (\Throwable) { /* notification failure must not break main flow */
        }

        return redirect()->route('input.qc.index')->with('success', 'Note ditandai sudah ditangani.');
    }

    public function destroy(TransaksiQc $qc)
    {
        if (auth()->user()->role !== 'admin' && $qc->created_by !== auth()->id()) {
            abort(403);
        }
        $qc->delete();

        return redirect()->route('input.qc.index')->with('success', 'Data berhasil dihapus.');
    }
}
