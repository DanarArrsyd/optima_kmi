<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ExportController extends Controller
{
    public function produksi() { return response('ok', 200); }
    public function qc() { return response('ok', 200); }
    public function dandori() { return response('ok', 200); }
}
