<?php

namespace App\Http\Controllers\Input;

use App\Exports\LhpRepairExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepairBatchRequest;
use App\Http\Requests\UpdateRepairRequest;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Services\RepairWriteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class RepairController extends Controller
{
    public function __construct(private RepairWriteService $repairWriteService) {}

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $search = trim((string) $request->input('q', ''));
        $filterCustomer = trim((string) $request->input('filter_customer', ''));
        $filterPartId = (int) $request->input('filter_part_id', 0);
        $filterProsesId = (int) $request->input('filter_proses_id', 0);
        $filterKarId = (int) $request->input('filter_karyawan_id', 0);

        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));

        $q = TransaksiRepair::with(['karyawan', 'partProsesRepair.part', 'shift']);

        if ($pakaiTanggal) {
            $q->whereDate('tanggal_repair', '>=', $dateFrom)
                ->whereDate('tanggal_repair', '<=', $dateTo);
        }
        if ($filterCustomer !== '') {
            $q->whereHas('partProsesRepair.part', fn ($p) => $p->where('customer', $filterCustomer));
        }
        if ($filterPartId > 0) {
            $q->whereHas('partProsesRepair', fn ($pp) => $pp->where('id_part', $filterPartId));
        }
        if ($filterProsesId > 0) {
            $q->where('id_part_proses_repair', $filterProsesId);
        }
        if ($filterKarId > 0) {
            $q->where('id_karyawan', $filterKarId);
        }
        if ($search !== '') {
            $like = '%'.$search.'%';
            $q->where(function ($sub) use ($like) {
                $sub->whereHas('karyawan', fn ($k) => $k->where('nama_karyawan', 'like', $like))
                    ->orWhereHas('shift', fn ($s) => $s->where('nama_shift', 'like', $like))
                    ->orWhereHas('partProsesRepair', fn ($pp) => $pp->where('nama_proses', 'like', $like))
                    ->orWhereHas('partProsesRepair.part', fn ($p) => $p->where('part_no', 'like', $like)
                        ->orWhere('part_name', 'like', $like)
                        ->orWhere('customer', 'like', $like));
            });
        }

        $repairs = $q->orderByDesc('tanggal_repair')->orderByDesc('created_at')->paginate(30)->withQueryString();

        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepair')->orderBy('part_name')->get();
        $customers = MasterPart::distinct()->orderBy('customer')->pluck('customer')->filter()->values();
        $filterActive = $search !== '' || $filterCustomer !== '' || $filterPartId > 0 || $filterProsesId > 0 || $filterKarId > 0;

        return view('input.repair.index', compact(
            'repairs', 'dateFrom', 'dateTo', 'search', 'pakaiTanggal',
            'filterCustomer', 'filterPartId', 'filterProsesId', 'filterKarId',
            'karyawan', 'parts', 'customers', 'filterActive'
        ));
    }

    public function create()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepair')->orderBy('part_name')->get();

        return view('input.repair.create', compact('shifts', 'karyawan', 'parts'));
    }

    public function batch(StoreRepairBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->repairWriteService->simpan($row);
            }
        });

        $jumlah = count($rows);

        return redirect()->route('input.repair.index')
            ->with('success', "{$jumlah} baris LHR berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }

    public function edit(TransaksiRepair $repair)
    {
        $repair->load(['karyawan', 'partProsesRepair.part']);
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::aktif()->orderBy('nama_karyawan')->get();
        $parts = MasterPart::with('prosesRepair')->orderBy('part_name')->get();

        return view('input.repair.edit', compact('repair', 'shifts', 'karyawan', 'parts'));
    }

    public function update(UpdateRepairRequest $request, TransaksiRepair $repair)
    {
        $validated = $request->validated();

        $cycleTime = $this->repairWriteService->hitungCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            (int) $validated['qty_aktual']
        );

        $repair->update([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
        ]);

        return redirect()->route('input.repair.index', $this->backQueryParams($request))
            ->with('success', 'LHR berhasil diupdate.');
    }

    private function backQueryParams(Request $request): array
    {
        parse_str((string) $request->input('back_query', ''), $params);

        return collect($params)->only([
            'date_from', 'date_to', 'q', 'filter_customer', 'filter_part_id',
            'filter_proses_id', 'filter_karyawan_id', 'pakai_tanggal', 'filter_apply', 'page',
        ])->toArray();
    }

    public function export(Request $request)
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $customer = $request->input('customer') ?: null;
        $partName = $request->input('part_name') ?: null;
        $namaProses = $request->input('nama_proses') ?: null;

        $filename = 'LHR_Repair_'.now()->format('Ymd_His').'.xlsx';

        return Excel::download(
            new LhpRepairExport($dateFrom, $dateTo, $customer, $partName, $namaProses),
            $filename
        );
    }

    public function destroy(TransaksiRepair $repair)
    {
        $repair->delete();

        return back(fallback: route('input.repair.index'))->with('success', 'Data berhasil dihapus.');
    }
}
