<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
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
     * @return array{model: class-string, tanggal: string, qty_baik: string, detail: class-string}
     */
    private function config(string $sumber): array
    {
        return match ($sumber) {
            'produksi' => [
                'model' => TransaksiProduksi::class,
                'tanggal' => 'tanggal_produksi',
                'qty_baik' => 'qty_aktual',
                'detail' => TransaksiProduksiNg::class,
            ],
            'qc' => [
                'model' => TransaksiQc::class,
                'tanggal' => 'tgl_pengecekan',
                'qty_baik' => 'qty_ok',
                'detail' => TransaksiQcNg::class,
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
                'bulan' => Carbon::createFromFormat('!Y-m', $key)->format('M Y'),
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

    /**
     * Rincian NG untuk satu bulan, per kombinasi Part + Jenis NG, urut qty
     * terbanyak. Dulu cuma dikelompokkan per jenis NG (semua part digabung) —
     * operator/QC perlu tahu PART mana yang kena, bukan cuma jenis NG-nya.
     *
     * @param  string  $bulan  format 'YYYY-MM'
     * @return list<array{id_jenis_ng: int, kode: string, nama_ng: string, part_no: string, part_name: string, qty: int, porsi: float}>
     */
    public function getVcbNgDetail(string $sumber, string $bulan): array
    {
        $cfg = $this->config($sumber);

        /*
         * Tabel detail bisa belum ada di jendela antara rsync dan migrate.
         * Halaman harus tampil dengan keadaan kosong, bukan 500.
         */
        if (! NgDetailService::available()) {
            return [];
        }

        [$tahun, $bln] = array_map('intval', explode('-', $bulan));

        /*
         * whereHas menerapkan global scope model induk, jadi sesi ter-soft-delete
         * otomatis tidak ikut. Part/proses BISA soft-delete sementara transaksi
         * lamanya tetap ada — partProses/part di-load lewat relasi biasa (bukan
         * withTrashed) jadi baris begitu jatuh ke fallback "Part terhapus",
         * bukan exception.
         */
        $rows = $cfg['detail']::query()
            ->with(['jenisNg', 'transaksi.partProses.part'])
            ->whereHas('transaksi', fn ($q) => $q
                ->whereYear($cfg['tanggal'], $tahun)
                ->whereMonth($cfg['tanggal'], $bln))
            ->get();

        $byGroup = [];
        $total = 0;

        foreach ($rows as $row) {
            $idJenis = (int) $row->id_jenis_ng;
            $idProses = (int) $row->transaksi?->id_part_proses;
            $qty = (int) $row->qty;
            $key = $idProses.'-'.$idJenis;

            $part = $row->transaksi?->partProses?->part;

            if (! isset($byGroup[$key])) {
                $byGroup[$key] = [
                    /* Part + jenis NG yang sama tidak lagi menjamin id_jenis_ng
                     * unik per baris (satu jenis NG bisa muncul di banyak part
                     * bulan yang sama) — 'key' inilah yang dipakai Alpine
                     * x-for, BUKAN id_jenis_ng, supaya baris tidak kolaps
                     * diam-diam kalau dua part kena jenis NG yang sama. */
                    'key' => $key,
                    'id_jenis_ng' => $idJenis,
                    'kode' => $row->jenisNg?->kode ?? '?',
                    'nama_ng' => $row->jenisNg?->nama_ng ?? 'Jenis NG terhapus',
                    'part_no' => $part?->part_no ?? '—',
                    'part_name' => $part?->part_name ?? 'Part terhapus',
                    'qty' => 0,
                ];
            }

            $byGroup[$key]['qty'] += $qty;
            $total += $qty;
        }

        $hasil = array_values($byGroup);
        usort($hasil, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        foreach ($hasil as $i => $row) {
            $hasil[$i]['porsi'] = $total > 0 ? round($row['qty'] / $total * 100, 1) : 0.0;
        }

        return $hasil;
    }

    /**
     * Empat desimal, bukan satu.
     *
     * NG Rate pabrik stamping berada di orde 0,00x% — 211 NG dari 2.668.690
     * pcs = 0,0079%. Pembulatan ke 1 desimal membuang seluruh sinyal itu:
     * setiap bulan menjadi 0,0, tiap batang grafik bertinggi nol, dan halaman
     * tampak kosong padahal datanya ada. Terjadi di prod 2026-07-28.
     *
     * Empat desimal menahan skala PPM (1 PPM = 0,0001%), yang merupakan satuan
     * yang benar-benar dipakai di lantai produksi.
     */
    private function rate(int $ng, int $check): float
    {
        return $check > 0 ? round($ng / $check * 100, 4) : 0.0;
    }
}
