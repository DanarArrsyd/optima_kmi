<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function index() { return view('maintenance.availability'); }
    public function data(Request $request) { return response()->json([]); }
}
