<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;

class VcbMesinController extends Controller
{
    public function index() { return response('ok', 200); }
    public function data() { return response()->json([]); }
}
