<?php

namespace App\Http\Controllers\Sistem;

use App\Http\Controllers\Controller;
use App\Services\QrStasiunService;

class QrStasiunController extends Controller
{
    public function __construct(private QrStasiunService $qr) {}

    public function index()
    {
        $stasiun = collect(config('qr_stasiun'))->map(function (array $item) {
            $path = route($item['route'], absolute: false);
            $url = rtrim(config('app.url'), '/').$path;

            return [
                'label' => $item['label'],
                'url' => $url,
                'svg' => $this->qr->svgFor($url),
            ];
        })->values();

        return view('sistem.qr-stasiun.index', compact('stasiun'));
    }
}
