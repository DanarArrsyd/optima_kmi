<?php

namespace App\Http\Controllers\Admin;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Models\TransaksiProduksi;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Facades\Excel;

class AnomalyController extends Controller
{
    /*
     * Ambang pindah ke config/anomali_lhp.php karena sekarang dipakai DUA tempat:
     * halaman ini dan SphTeoretisService yang membuang baris anomali sebelum
     * menghitung kandidat SPH teoretis. Dua salinan angka akan melenceng, dan
     * melencengnya senyap — baris yang ditandai di sini tetap dipakai di sana.
     *
     * Default dipertahankan sebagai fallback: kalau config hilang, perilakunya
     * tidak berubah diam-diam.
     */
    private const PCT_THRESHOLD_DEFAULT = 150;

    private const MIN_WKJ_DEFAULT = 0.083; // 5 menit dalam jam

    public function index(Request $request)
    {
        [$anomalies, $dateFrom, $dateTo, $reason] = $this->cariAnomali($request);

        $summary = [
            'total' => $anomalies->count(),
            'high_pct' => $anomalies->filter(fn ($a) => in_array('high_pct', $a->types))->count(),
            'short_wkj' => $anomalies->filter(fn ($a) => in_array('short_wkj', $a->types))->count(),
            'no_std' => $anomalies->filter(fn ($a) => in_array('no_std', $a->types))->count(),
        ];

        return view('admin.anomali.index', compact('anomalies', 'summary', 'dateFrom', 'dateTo', 'reason'));
    }

    /**
     * Anomali dalam rentang + filter jenis — BERSAMA index() dan export().
     *
     * @return array{0: Collection, 1: string, 2: string, 3: string}
     */
    private function cariAnomali(Request $request): array
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

            if ($pct !== null && $pct > config('anomali_lhp.pct_maks', self::PCT_THRESHOLD_DEFAULT)) {
                $warnings[] = "Achievement {$pct}% — terlalu tinggi";
                $types[] = 'high_pct';
            }
            if ($wkj < config('anomali_lhp.wkj_min', self::MIN_WKJ_DEFAULT) && $wkj > 0) {
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

        return [$anomalies, $dateFrom, $dateTo, $reason];
    }

    /** Export Excel daftar anomali — isi sama dengan tabel, filter ikut. */
    public function export(Request $request)
    {
        [$anomalies, $dateFrom, $dateTo] = $this->cariAnomali($request);

        $baris = $anomalies->map(function ($a) {
            $t = $a->record;

            return [
                $t->tanggal_produksi?->format('Y-m-d'), $t->shift?->nama_shift, $t->karyawan?->nama_karyawan,
                $t->mesin?->id_mesin, $t->partProses?->part?->part_no, $t->partProses?->part?->part_name,
                $t->partProses?->nama_proses, substr((string) $t->jam_start, 0, 5).'–'.substr((string) $t->jam_finish, 0, 5),
                round((float) $a->wkj, 2), $t->qty_aktual, $a->sph_std, $a->sph_act, $a->pct, implode('; ', $a->warnings),
            ];
        })->all();

        return Excel::download(new TabelExport(
            ['Tanggal', 'Shift', 'Operator', 'Mesin', 'Part No', 'Part Name', 'Proses', 'Jam', 'W. Kerja (jam)', 'Qty Act', 'SPH Std', 'SPH Aktual', 'Achievement %', 'Anomali'],
            $baris, 'Anomali LHP',
        ), 'anomali-lhp-'.$dateFrom.'-'.$dateTo.'.xlsx');
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
