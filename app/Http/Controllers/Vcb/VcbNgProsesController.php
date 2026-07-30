<?php

namespace App\Http\Controllers\Vcb;

use App\Exports\NgListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\NgListFilterRequest;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Services\NgDashboardService;
use App\Services\NgListService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class VcbNgProsesController extends Controller
{
    public function __construct(
        private NgDashboardService $svc,
        private NgListService $list = new NgListService,
    ) {}

    public function index()
    {
        return view('vcb.ng', [
            'judul' => 'VCB NG Proses',
            'sumber' => 'produksi',
            'routeData' => route('vcb.ng-proses.data'),
            'routeDetail' => route('vcb.ng-proses.detail'),
            'catatanKosong' => 'Belum ada data NG pada rentang ini.',
        ]);
    }

    public function data(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        return response()->json($this->svc->getVcbNg(
            'produksi',
            $request->input('date_from'),
            $request->input('date_to'),
        ));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return response()->json($this->svc->getVcbNgDetail('produksi', $request->input('bulan')));
    }

    public function daftar(NgListFilterRequest $request)
    {
        $filter = $request->filter();

        return view('vcb.ng-list', [
            'judul' => 'Daftar NG Proses',
            'sumber' => 'produksi',
            'labelOrang' => 'Operator',
            'filter' => $filter,
            'rows' => $this->list->paginate('produksi', $filter),
            'jenisNgOptions' => MasterJenisNg::orderBy('kode')->get(),
            'orangOptions' => MasterKaryawan::where('departemen', 'Produksi')->aktif()->orderBy('nama_karyawan')->get(),
            'routeDaftar' => route('vcb.ng-proses.daftar'),
            'routeExport' => route('vcb.ng-proses.daftar.export'),
            'catatanKosong' => 'Tidak ada NG yang cocok dengan filter ini.',
        ]);
    }

    public function export(NgListFilterRequest $request)
    {
        return Excel::download(
            new NgListExport('produksi', $request->filter()),
            'daftar-ng-proses-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
