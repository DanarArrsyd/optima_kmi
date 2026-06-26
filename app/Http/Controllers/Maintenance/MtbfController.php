<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MtbfController extends Controller
{
    public function index() { return view('maintenance.mtbf'); }
    public function data(Request $request) { return response()->json([]); }
}
