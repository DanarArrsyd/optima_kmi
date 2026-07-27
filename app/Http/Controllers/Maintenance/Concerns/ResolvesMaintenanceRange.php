<?php

namespace App\Http\Controllers\Maintenance\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait ResolvesMaintenanceRange
{
    /**
     * Rentang tanggal untuk halaman maintenance. Kalau filter 'bulan' (YYYY-MM)
     * diisi, ia menang & override rentang ke satu bulan penuh; kalau tidak, pakai
     * date_from/date_to.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveRange(Request $request): array
    {
        if ($request->filled('bulan')) {
            $m = Carbon::parse($request->input('bulan').'-01');

            return [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()];
        }

        return [
            Carbon::parse($request->input('date_from', date('Y-01-01'))),
            Carbon::parse($request->input('date_to', date('Y-m-d'))),
        ];
    }
}
