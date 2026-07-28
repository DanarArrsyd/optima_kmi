<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Services\NgDashboardService;
use Illuminate\Http\Request;

class VcbNgTotalController extends Controller
{
    public function __construct(private NgDashboardService $svc) {}

    public function index()
    {
        return view('vcb.ng', [
            'judul' => 'VCB NG Total',
            'sumber' => 'qc',
            'routeData' => route('vcb.ng-total.data'),
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
}
