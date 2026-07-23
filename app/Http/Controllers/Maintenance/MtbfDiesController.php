<?php

namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use App\Models\MasterPart;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class MtbfDiesController extends Controller
{
    public function __construct(private MaintenanceDiesDashboardService $svc) {}

    public function index()
    {
        return view('maintenance.mtbf-dies', ['partList' => MasterPart::orderBy('part_no')->get(), 'customers' => MasterPart::whereNotNull('customer')->where('customer', '!=', '')->distinct()->orderBy('customer')->pluck('customer')]);
    }

    public function data(Request $request)
    {
        $from = Carbon::parse($request->input('date_from', date('Y-01-01')));
        $to = Carbon::parse($request->input('date_to', date('Y-m-d')));
        $partId = (int) $request->input('part_id', 0) ?: null;
        $customer = trim((string) $request->input('customer', '')) ?: null;
        $rows = $this->svc->compute($from, $to, $partId, $customer);

        return response()->json([
            'rows' => $rows,
            'summary' => [
                'avg_mtbf' => round((float) collect($rows)->whereNotNull('mtbf_jam')->avg('mtbf_jam'), 2),
                'total_bd' => (int) collect($rows)->sum('count'),
            ],
        ]);
    }
}
