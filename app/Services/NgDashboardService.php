<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use Illuminate\Support\Carbon;

/**
 * Agregasi NG untuk dua halaman dashboard: VCB NG Proses (sisi produksi) dan
 * VCB NG Total (sisi quality).
 *
 * Satu method dengan parameter $sumber, bukan dua method kembar — yang berbeda
 * antara kedua sisi hanya nama tabel, kolom tanggal, dan kolom qty baik.
 *
 * Rumus NG Rate sama di kedua sisi supaya angkanya bisa dibandingkan langsung:
 *   NG Rate = qty_ng / (qty_baik + qty_ng) * 100
 * Produksi memakai qty_aktual sebagai qty baik — kolom itu berisi barang baik
 * saja, NG dicatat terpisah lewat tabel detail.
 */
class NgDashboardService
{
    /**
     * @return array{model: class-string, tanggal: string, qty_baik: string}
     */
    private function config(string $sumber): array
    {
        return match ($sumber) {
            'produksi' => [
                'model' => TransaksiProduksi::class,
                'tanggal' => 'tanggal_produksi',
                'qty_baik' => 'qty_aktual',
            ],
            'qc' => [
                'model' => TransaksiQc::class,
                'tanggal' => 'tgl_pengecekan',
                'qty_baik' => 'qty_ok',
            ],
            default => throw new \InvalidArgumentException(
                "Sumber NG tidak dikenal: {$sumber}. Yang sah: 'produksi' atau 'qc'."
            ),
        };
    }

    public function getVcbNg(string $sumber, string $dateFrom, string $dateTo): array
    {
        $cfg = $this->config($sumber);

        /*
         * Query lewat Eloquent, bukan DB::table, supaya global scope SoftDeletes
         * ikut berlaku — sesi yang dihapus tidak boleh terhitung.
         */
        $records = $cfg['model']::query()
            ->whereDate($cfg['tanggal'], '>=', $dateFrom)
            ->whereDate($cfg['tanggal'], '<=', $dateTo)
            ->get([$cfg['tanggal'], $cfg['qty_baik'], 'qty_ng']);

        $byMonth = [];
        $totalNg = 0;
        $totalCheck = 0;

        foreach ($records as $r) {
            $tanggal = $r->{$cfg['tanggal']};
            $key = $tanggal instanceof Carbon
                ? $tanggal->format('Y-m')
                : Carbon::parse($tanggal)->format('Y-m');

            $ng = (int) ($r->qty_ng ?? 0);
            $baik = (int) ($r->{$cfg['qty_baik']} ?? 0);

            if (! isset($byMonth[$key])) {
                $byMonth[$key] = ['total_ng' => 0, 'total_check' => 0];
            }

            $byMonth[$key]['total_ng'] += $ng;
            $byMonth[$key]['total_check'] += $baik + $ng;

            $totalNg += $ng;
            $totalCheck += $baik + $ng;
        }

        /*
         * Kunci bulan datang dari transaksi yang benar-benar ada, jadi bulan
         * tanpa transaksi memang tidak pernah muncul. Bulan dengan transaksi
         * tapi nol NG tetap muncul dengan rate 0% — itu informasi, bukan lubang.
         */
        ksort($byMonth);

        $rows = [];
        foreach ($byMonth as $key => $agg) {
            $rows[] = [
                'bulan' => Carbon::createFromFormat('Y-m', $key)->format('M Y'),
                'bulan_raw' => $key,
                'total_ng' => $agg['total_ng'],
                'total_check' => $agg['total_check'],
                'ng_rate' => $this->rate($agg['total_ng'], $agg['total_check']),
            ];
        }

        return [
            'summary' => [
                'total_ng' => $totalNg,
                'total_check' => $totalCheck,
                'ng_rate' => $this->rate($totalNg, $totalCheck),
            ],
            'by_month' => $rows,
        ];
    }

    private function rate(int $ng, int $check): float
    {
        return $check > 0 ? round($ng / $check * 100, 1) : 0.0;
    }
}
