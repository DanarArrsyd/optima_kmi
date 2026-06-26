<?php
namespace App\Http\Controllers\Maintenance;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class MttrController extends Controller
{
    public function index() { return view('maintenance.mttr'); }
    public function data(Request $request) { return response()->json([]); }
}
