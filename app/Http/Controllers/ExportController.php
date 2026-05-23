<?php
namespace App\Http\Controllers;
use App\Exports\ProduksiExport;
use App\Exports\QcExport;
use App\Exports\DandoriExport;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Barryvdh\DomPDF\Facade\Pdf;

class ExportController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function produksi(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $groupBy  = $request->input('group', 'karyawan');
        $format   = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $rows = $groupBy === 'mesin'
                ? $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId)
                : $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId);
            return Pdf::loadView('exports.produksi_pdf', [
                'rows'     => $rows,
                'dateFrom' => $dateFrom->format('d/m/Y'),
                'dateTo'   => $dateTo->format('d/m/Y'),
            ])->setPaper('a4', 'landscape')->download("vcb_produksi_{$dateFrom->format('Ymd')}.pdf");
        }

        return Excel::download(
            new ProduksiExport($this->dashboardService, $dateFrom, $dateTo, $shiftId, $groupBy),
            "vcb_produksi_{$dateFrom->format('Ymd')}.xlsx"
        );
    }

    public function qc(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo   = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId  = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $format   = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $rows = $this->dashboardService->getVcbQuality($dateFrom, $dateTo, $shiftId);
            return Pdf::loadView('exports.qc_pdf', [
                'rows'     => $rows,
                'dateFrom' => $dateFrom->format('d/m/Y'),
                'dateTo'   => $dateTo->format('d/m/Y'),
            ])->setPaper('a4', 'landscape')->download("vcb_quality_{$dateFrom->format('Ymd')}.pdf");
        }

        return Excel::download(
            new QcExport($this->dashboardService, $dateFrom, $dateTo, $shiftId),
            "vcb_quality_{$dateFrom->format('Ymd')}.xlsx"
        );
    }

    public function dandori(Request $request)
    {
        $bulan   = $request->input('bulan') ?: null;
        $week    = $request->input('week') ?: null;
        $mesinId = $request->filled('mesin_id') ? (int) $request->input('mesin_id') : null;
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $format  = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $result = $this->dashboardService->getVcbDandori($bulan, $week, $mesinId, $shiftId);
            return Pdf::loadView('exports.dandori_pdf', [
                'rows'  => $result['rows'],
                'bulan' => $bulan,
                'week'  => $week,
            ])->setPaper('a4', 'landscape')->download("vcb_dandori.pdf");
        }

        return Excel::download(
            new DandoriExport($this->dashboardService, $bulan, $week, $mesinId, $shiftId),
            "vcb_dandori.xlsx"
        );
    }
}
