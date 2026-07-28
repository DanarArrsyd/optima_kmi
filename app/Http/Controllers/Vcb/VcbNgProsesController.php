<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Services\NgDashboardService;
use Illuminate\Http\Request;

class VcbNgProsesController extends Controller
{
    public function __construct(private NgDashboardService $svc) {}

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
}
