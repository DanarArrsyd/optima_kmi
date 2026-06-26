<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class BreakdownMesinController extends Controller
{
    public function index(Request $request)
    {
        return view('maintenance.breakdown.index', ['breakdowns' => collect()]);
    }

    public function create()
    {
        return view('maintenance.breakdown.index', ['breakdowns' => collect()]);
    }

    public function store(Request $request)
    {
        return redirect()->route('maintenance.breakdown.index');
    }

    public function edit($breakdown)
    {
        return view('maintenance.breakdown.index', ['breakdowns' => collect()]);
    }

    public function update(Request $request, $breakdown)
    {
        return redirect()->route('maintenance.breakdown.index');
    }

    public function destroy($breakdown)
    {
        return redirect()->route('maintenance.breakdown.index');
    }

    public function lastNoLkm(Request $request)
    {
        return response()->json(['no_lkm' => null]);
    }
}
