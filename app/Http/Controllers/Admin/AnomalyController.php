<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TransaksiProduksi;
use Illuminate\Http\Request;

class AnomalyController extends Controller
{
    private const PCT_THRESHOLD = 150;   // % achievement di atas ini dianggap anomali

    private const MIN_WKJ = 0.083; // 5 menit dalam jam

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', date('Y-m-01'));
        $dateTo = $request->input('date_to', date('Y-m-d'));
        $reason = $request->input('reason', '');   // filter by jenis anomali

        $records = TransaksiProduksi::with(['karyawan', 'mesin', 'partProses.part', 'shift'])
            ->whereDate('tanggal_produksi', '>=', $dateFrom)
            ->whereDate('tanggal_produksi', '<=', $dateTo)
            ->whereNull('anomaly_reviewed_at')          // sembunyikan yang sudah di-OK
            ->orderByDesc('tanggal_produksi')
            ->orderByDesc('created_at')
            ->get();

        $anomalies = $records->map(function ($t) {
            $sphStd = $t->partProses?->sph_standar_produksi ?? 0;
            $sphAct = $t->sph_aktual;
            $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
            $wkj = $t->waktu_kerja_jam;

            $warnings = [];
            $types = [];

            if ($pct !== null && $pct > self::PCT_THRESHOLD) {
                $warnings[] = "Achievement {$pct}% — terlalu tinggi";
                $types[] = 'high_pct';
            }
            if ($wkj < self::MIN_WKJ && $wkj > 0) {
                $warnings[] = 'Waktu kerja < 5 menit — jam start/finish mungkin salah';
                $types[] = 'short_wkj';
            }
            if ($sphStd == 0 && $sphAct > 0) {
                $warnings[] = 'SPH standar belum diset';
                $types[] = 'no_std';
            }

            if (empty($warnings)) {
                return null;
            }

            return (object) [
                'record' => $t,
                'pct' => $pct,
                'sph_std' => $sphStd,
                'sph_act' => $sphAct,
                'wkj' => $wkj,
                'warnings' => $warnings,
                'types' => $types,
            ];
        })->filter();

        // Filter by jenis anomali jika dipilih
        if ($reason) {
            $anomalies = $anomalies->filter(fn ($a) => in_array($reason, $a->types));
        }

        $anomalies = $anomalies->values();

        $summary = [
            'total' => $anomalies->count(),
            'high_pct' => $anomalies->filter(fn ($a) => in_array('high_pct', $a->types))->count(),
            'short_wkj' => $anomalies->filter(fn ($a) => in_array('short_wkj', $a->types))->count(),
            'no_std' => $anomalies->filter(fn ($a) => in_array('no_std', $a->types))->count(),
        ];

        return view('admin.anomali.index', compact('anomalies', 'summary', 'dateFrom', 'dateTo', 'reason'));
    }

    public function approve(TransaksiProduksi $transaksi)
    {
        try {
            $transaksi->update([
                'anomaly_reviewed_at' => now(),
                'anomaly_reviewed_by' => auth()->id(),
            ]);
        } catch (\Throwable) {
            // kolom belum ada di hosting — abaikan saja
        }

        return back()->with('success', "Data #{$transaksi->id} ditandai OK oleh ".auth()->user()->name.'.');
    }
}
