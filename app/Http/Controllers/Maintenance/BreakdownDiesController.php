<?php

namespace App\Http\Controllers\Maintenance;

use App\Exports\BreakdownDiesExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBreakdownDiesRequest;
use App\Http\Requests\UpdateBreakdownDiesRequest;
use App\Imports\BreakdownDiesImport;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\TransaksiBreakdownDies;
use App\Services\BreakdownDiesService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Facades\Excel;

class BreakdownDiesController extends Controller
{
    public function __construct(private BreakdownDiesService $svc) {}

    public function export(Request $request)
    {
        return Excel::download(new BreakdownDiesExport(
            $request->input('date_from'),
            $request->input('date_to'),
            (int) $request->input('part_id', 0) ?: null,
        ), 'breakdown_dies.xlsx');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);
        $import = new BreakdownDiesImport($this->svc);
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
                    'Nomor Urut', 'Part No', 'Id Proses',
                    'Tanggal Breakdown', 'Jam Breakdown',
                    'Tanggal Mulai Perbaikan', 'Jam Mulai Perbaikan',
                    'Tanggal Selesai Perbaikan', 'Jam Selesai Perbaikan',
                    'Problem', 'Penyebab', 'Tindakan', 'PIC',
                ];
            }

            public function array(): array
            {
                return [
                    [1, 'BT-091A-3', 'OP10', '2026-06-10', '08:00', '2026-06-10', '09:00', '2026-06-10', '11:00', 'Punch patah', 'Material keras', 'Ganti punch', 'Budi'],
                ];
            }
        };

        return Excel::download($export, 'template_breakdown_dies.xlsx');
    }

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $partId = (int) $request->input('part_id', 0);

        /* Rentang tanggal bisa dimatikan lewat centang di dock; bawaannya
         * nyala supaya halaman tidak membuka seluruh riwayat tanpa diminta. */
        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));
        $filterPic = trim((string) $request->input('filter_pic', ''));
        $filterStatus = trim((string) $request->input('filter_status', ''));
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterProsesId = (int) $request->input('filter_proses_id', 0);

        $breakdowns = TransaksiBreakdownDies::with('partProses.part')
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
            ->when($partId, fn ($q) => $q->whereHas('partProses', fn ($p) => $p->where('id_part', $partId)))
            ->when($filterProsesId, fn ($q) => $q->where('id_part_proses', $filterProsesId))
            ->when($filterPic !== '', fn ($q) => $q->where('pic_maintenance', $filterPic))
            ->when($filterStatus !== '', fn ($q) => $q->where('status_breakdown', $filterStatus))
            ->when($filterCustomer !== '', fn ($q) => $q->whereHas('partProses.part', fn ($p) => $p->where('customer', $filterCustomer)))
            ->orderByDesc('tanggal_breakdown')->orderByDesc('id')
            ->paginate(50)->withQueryString();

        return view('maintenance.breakdown-dies.index', [
            'breakdowns' => $breakdowns,
            'partList' => MasterPart::with('proses')->orderBy('part_no')->get(),
            'picMaintenance' => MasterKaryawan::where('departemen', 'Maintenance')->aktif()->orderBy('nama_karyawan')->get(),
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'partId' => $partId,
            'customers' => MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values(),
            'pakaiTanggal' => $pakaiTanggal,
            'filterPic' => $filterPic,
            'filterStatus' => $filterStatus,
            'filterCustomer' => $filterCustomer,
            'filterProsesId' => $filterProsesId,
            'nextNomor' => $this->svc->nextNomorUrut(),
        ]);
    }

    public function lastNoLkd()
    {
        return response()->json(['next_nomor' => $this->svc->nextNomorUrut()]);
    }

    public function store(StoreBreakdownDiesRequest $request)
    {
        $data = $request->validated();
        $noLkd = $this->svc->generateNoLkd((int) $data['nomor_urut'], $data['tanggal_breakdown']);

        if (TransaksiBreakdownDies::withTrashed()->where('no_lkd', $noLkd)->exists()) {
            return back()->withInput()->withErrors(['nomor_urut' => "No LKD {$noLkd} sudah ada, gunakan nomor lain."]);
        }

        $derived = $this->svc->computeDerived($data);
        TransaksiBreakdownDies::create([
            'no_lkd' => $noLkd,
            'id_part_proses' => $data['id_part_proses'],
            'tanggal_breakdown' => $data['tanggal_breakdown'],
            'jam_breakdown' => $data['jam_breakdown'],
            'tanggal_mulai_perbaikan' => $data['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan' => $data['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $data['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan' => $data['jam_selesai_perbaikan'],
            'problem_dies' => $data['problem_dies'],
            'penyebab_breakdown' => $data['penyebab_breakdown'],
            'tindakan_perbaikan' => $data['tindakan_perbaikan'],
            'pic_maintenance' => $data['pic_maintenance'],
            ...$derived,
        ]);

        return redirect()->route('maintenance.breakdown-dies.index')->with('success', "Breakdown {$noLkd} tersimpan.");
    }

    public function edit(TransaksiBreakdownDies $breakdownDy)
    {
        return view('maintenance.breakdown-dies.edit', [
            'b' => $breakdownDy,
            'partList' => MasterPart::with('proses')->orderBy('part_no')->get(),
            'picMaintenance' => MasterKaryawan::where('departemen', 'Maintenance')->aktif()->orderBy('nama_karyawan')->get(),
        ]);
    }

    public function update(UpdateBreakdownDiesRequest $request, TransaksiBreakdownDies $breakdownDy)
    {
        $data = $request->validated();
        $noLkd = $this->svc->generateNoLkd((int) $data['nomor_urut'], $data['tanggal_breakdown']);

        $dupe = TransaksiBreakdownDies::withTrashed()->where('no_lkd', $noLkd)->where('id', '!=', $breakdownDy->id)->exists();
        if ($dupe) {
            return back()->withInput()->withErrors(['nomor_urut' => "No LKD {$noLkd} sudah ada, gunakan nomor lain."]);
        }

        $derived = $this->svc->computeDerived($data);
        $breakdownDy->update([
            'no_lkd' => $noLkd,
            'id_part_proses' => $data['id_part_proses'],
            'tanggal_breakdown' => $data['tanggal_breakdown'],
            'jam_breakdown' => $data['jam_breakdown'],
            'tanggal_mulai_perbaikan' => $data['tanggal_mulai_perbaikan'],
            'jam_mulai_perbaikan' => $data['jam_mulai_perbaikan'],
            'tanggal_selesai_perbaikan' => $data['tanggal_selesai_perbaikan'],
            'jam_selesai_perbaikan' => $data['jam_selesai_perbaikan'],
            'problem_dies' => $data['problem_dies'],
            'penyebab_breakdown' => $data['penyebab_breakdown'],
            'tindakan_perbaikan' => $data['tindakan_perbaikan'],
            'pic_maintenance' => $data['pic_maintenance'],
            ...$derived,
        ]);

        return redirect()->route('maintenance.breakdown-dies.index')->with('success', "Breakdown {$noLkd} diperbarui.");
    }

    public function destroy(TransaksiBreakdownDies $breakdownDy)
    {
        $breakdownDy->delete();

        return redirect()->route('maintenance.breakdown-dies.index')->with('success', 'Breakdown dihapus.');
    }
}
