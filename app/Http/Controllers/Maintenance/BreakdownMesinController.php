<?php

namespace App\Http\Controllers\Maintenance;

use App\Exports\BreakdownMesinExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakdownMesinBatchRequest;
use App\Http\Requests\UpdateBreakdownMesinRequest;
use App\Imports\BreakdownMesinImport;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Services\BreakdownMesinService;
use App\Services\BreakdownMesinWriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class BreakdownMesinController extends Controller
{
    public function __construct(
        private BreakdownMesinService $svc,
        private BreakdownMesinWriteService $writeService,
    ) {}

    public function export(Request $request)
    {
        return Excel::download(new BreakdownMesinExport(
            $request->input('date_from'),
            $request->input('date_to'),
            (int) $request->input('mesin_id', 0) ?: null,
        ), 'breakdown_mesin.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new BreakdownMesinImport($this->svc);
        Excel::import($import, $request->file('file'));

        $msg = "{$import->imported} breakdown berhasil diimport"
             .($import->skipped ? ", {$import->skipped} baris dilewati." : '.');

        return back()->with('import_success', $msg)->with('import_errors', $import->errors);
    }

    public function importTemplate()
    {
        $export = new class implements FromArray, WithHeadings
        {
            public function headings(): array
            {
                return [
                    'Nomor Urut', 'ID Mesin',
                    'Tanggal Breakdown', 'Jam Breakdown',
                    'Tanggal Mulai Perbaikan', 'Jam Mulai Perbaikan',
                    'Tanggal Selesai Perbaikan', 'Jam Selesai Perbaikan',
                    'Problem', 'Penyebab', 'Tindakan', 'PIC',
                ];
            }

            public function array(): array
            {
                return [
                    [1, 'M001', '2026-06-10', '08:00', '2026-06-10', '09:00', '2026-06-10', '11:00', 'Motor overheat', 'Bearing aus', 'Ganti bearing', 'Budi'],
                ];
            }
        };

        return Excel::download($export, 'template_breakdown_mesin.xlsx');
    }

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $mesinId = (int) $request->input('mesin_id', 0);

        /* Rentang tanggal bisa dimatikan lewat centang di dock; bawaannya
         * nyala supaya halaman tidak membuka seluruh riwayat tanpa diminta. */
        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));
        $filterPic = trim((string) $request->input('filter_pic', ''));
        $filterStatus = trim((string) $request->input('filter_status', ''));

        $breakdowns = TransaksiBreakdownMesin::with('mesin')
            /* whereDate, BUKAN whereBetween.

             Laravel menulis "2026-08-20 00:00:00" ke kolom bertipe date.
             MySQL memotongnya jadi "2026-08-20" sehingga whereBetween dengan
             batas tanggal-saja bekerja; SQLite menyimpannya apa adanya,
             sehingga baris di hari TERAKHIR rentang terbuang.

             Akibatnya bukan sekadar beda hasil: fitur ini jadi tidak mungkin
             diuji, karena suite berjalan di SQLite. whereDate membandingkan
             bagian tanggalnya saja di kedua dialek. */
            ->when($pakaiTanggal, fn ($q) => $q
                ->whereDate('tanggal_breakdown', '>=', $dateFrom)
                ->whereDate('tanggal_breakdown', '<=', $dateTo))
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->when($filterPic !== '', fn ($q) => $q->where('pic_maintenance', $filterPic))
            ->when($filterStatus !== '', fn ($q) => $q->where('status_breakdown', $filterStatus))
            ->orderByDesc('tanggal_breakdown')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('maintenance.breakdown.index', [
            'breakdowns' => $breakdowns,
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'picList' => MasterKaryawan::where('departemen', 'Maintenance')->aktif()->orderBy('nama_karyawan')->get(),
            'pakaiTanggal' => $pakaiTanggal,
            'filterPic' => $filterPic,
            'filterStatus' => $filterStatus,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'mesinId' => $mesinId,
        ]);
    }

    public function create()
    {
        return view('maintenance.breakdown.create', [
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
            'picList' => MasterKaryawan::where('departemen', 'Maintenance')->aktif()->orderBy('nama_karyawan')->get(),
            'nextNomor' => $this->svc->nextNomorUrut(),
        ]);
    }

    public function lastNoLkm()
    {
        return response()->json(['next_nomor' => $this->svc->nextNomorUrut()]);
    }

    public function batch(StoreBreakdownMesinBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        $jumlah = DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->writeService->simpan($row);
            }

            return count($rows);
        });

        /* batch_tersimpan adalah bukti sukses yang dibaca index.blade.php untuk
         * membuang draft di localStorage. Draft hanya boleh dibuang setelah
         * server MEMBUKTIKAN barisnya tersimpan — bukan cuma karena halaman
         * batch tidak melihat galat validasi, yang juga terjadi saat request
         * tidak pernah sampai sini (419 sesi habis, 500, jaringan putus). */
        return redirect()->route('maintenance.breakdown.index')
            ->with('success', "{$jumlah} baris data Breakdown Mesin berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }

    public function edit(TransaksiBreakdownMesin $breakdown)
    {
        return view('maintenance.breakdown.edit', [
            'b' => $breakdown,
            'mesinList' => MasterMesin::orderBy('id_mesin')->get(),
        ]);
    }

    public function update(UpdateBreakdownMesinRequest $request, TransaksiBreakdownMesin $breakdown)
    {
        $data = $request->validated();
        $noLkm = $this->svc->generateNoLkm((int) $data['nomor_urut'], $data['tanggal_breakdown']);

        $dupe = TransaksiBreakdownMesin::withTrashed()->where('no_lkm', $noLkm)->where('id', '!=', $breakdown->id)->exists();
        if ($dupe) {
            return back()->withInput()->withErrors(['nomor_urut' => "No LKM {$noLkm} sudah ada, gunakan nomor lain."]);
        }

        $derived = $this->svc->computeDerived($data);
        $breakdown->update([
            'no_lkm' => $noLkm,
            'id_mesin' => $data['id_mesin'],
            'tanggal_breakdown' => $data['tanggal_breakdown'],
            'jam_breakdown' => $data['jam_breakdown'],
            'tanggal_mulai_perbaikan' => $data['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan' => $data['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $data['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan' => $data['jam_selesai_perbaikan'],
            'problem_mesin' => $data['problem_mesin'],
            'penyebab_breakdown' => $data['penyebab_breakdown'],
            'tindakan_perbaikan' => $data['tindakan_perbaikan'],
            'pic_maintenance' => $data['pic_maintenance'],
            ...$derived,
        ]);

        return redirect()->route('maintenance.breakdown.index')->with('success', "Breakdown {$noLkm} diperbarui.");
    }

    public function destroy(TransaksiBreakdownMesin $breakdown)
    {
        $breakdown->delete();

        return redirect()->route('maintenance.breakdown.index')->with('success', 'Breakdown dihapus.');
    }
}
