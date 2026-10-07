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

class VcbNgTotalController extends Controller
{
    public function __construct(
        private NgDashboardService $svc,
        private NgListService $list = new NgListService,
    ) {}

    public function index()
    {
        return view('vcb.ng', [
            'judul' => 'VCB NG Total',
            'sumber' => 'qc',
            'routeData' => route('vcb.ng-total.data'),
            // Ekspor grafik = rincian NG di balik grafiknya (Daftar NG), rentang yang sama.
            'routeExport' => route('vcb.ng-total.daftar.export'),
            'routeDetail' => route('vcb.ng-total.detail'),
            'catatanKosong' => 'Belum ada data NG pada rentang ini. '
                .'NG dari sisi Quality memang belum pernah tercatat sejak sistem berjalan — '
                .'halaman ini akan terisi begitu inspector mulai mencatat NG di LHP Quality.',
        ]);
    }

    public function data(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        return response()->json($this->svc->getVcbNg(
            'qc',
            $request->input('date_from'),
            $request->input('date_to'),
        ));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return response()->json($this->svc->getVcbNgDetail('qc', $request->input('bulan')));
    }

    public function daftar(NgListFilterRequest $request)
    {
        $filter = $request->filter();

        return view('vcb.ng-list', [
            'judul' => 'Daftar NG Total',
            'sumber' => 'qc',
            'labelOrang' => 'Inspector',
            'filter' => $filter,
            'rows' => $this->list->paginate('qc', $filter),
            'jenisNgOptions' => MasterJenisNg::orderBy('kode')->get(),
            'orangOptions' => MasterKaryawan::where('departemen', 'QC')->aktif()->orderBy('nama_karyawan')->get(),
            'routeDaftar' => route('vcb.ng-total.daftar'),
            'routeExport' => route('vcb.ng-total.daftar.export'),
            // Sisi QC lahir kosong: nol NG tercatat sejak sistem berjalan. Halaman
            // harus mengatakan itu, bukan menampilkan tabel kosong yang terbaca
            // sebagai kerusakan.
            'catatanKosong' => 'Tidak ada NG yang cocok dengan filter ini. '
                .'NG dari sisi Quality memang belum pernah tercatat sejak sistem berjalan — '
                .'halaman ini akan terisi begitu inspector mulai mencatat NG di LHP Quality.',
        ]);
    }

    public function export(NgListFilterRequest $request)
    {
        return Excel::download(
            new NgListExport('qc', $request->filter()),
            'daftar-ng-total-'.now()->format('Ymd-His').'.xlsx'
        );
    }
}
