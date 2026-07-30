<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;

/**
 * Hitung OEE (Overall Equipment Effectiveness) = Availability × Performance × Quality
 * untuk satu mesin dalam satu rentang tanggal.
 *
 * Definisi tiap komponen SENGAJA konsisten dengan angka yang sudah dipakai di
 * app, supaya chatbot & dashboard tak saling bertentangan:
 *   - Availability : dari MaintenanceDashboardService (jam kalender sejak
 *     instalasi − downtime). CATATAN: basis kalender (24/7), bukan jam shift
 *     terjadwal — sama seperti dashboard maintenance.
 *   - Performance  : output aktual ÷ output teoretis pada SPH standar
 *     (sph_standar_produksi × waktu kerja), tertimbang waktu. Sama basis dengan
 *     "Achievement %" di AnomalyController.
 *   - Quality      : qty_aktual ÷ (qty_aktual + qty_ng). qty_aktual berisi
 *     barang BAIK saja; NG dicatat terpisah, jadi penyebutnya total diproduksi.
 *
 * Komponen yang tak bisa dihitung (mis. instalasi belum diset, SPH standar 0)
 * dikembalikan null + catatan — JANGAN ditebak. OEE hanya diisi bila ketiga
 * komponen ada.
 */
class OeeService
{
    public function __construct(
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService,
    ) {}

    /**
     * @return array{
     *   availability_pct: float|null, performance_pct: float|null,
     *   quality_pct: float|null, oee_pct: float|null,
     *   total_qty: int, total_ng: int, jumlah_lhp: int, catatan: list<string>
     * }
     */
    public function computeMesin(int $mesinId, Carbon $from, Carbon $to): array
    {
        $catatan = [];

        // --- Availability (reuse definisi dashboard maintenance) ---
        $avRow = collect($this->mesinDashboard->compute($from, $to, $mesinId))->first();
        $availabilityPct = $avRow['availability_pct'] ?? null;
        if ($availabilityPct === null) {
            $catatan[] = 'Availability tak bisa dihitung: tanggal instalasi mesin belum diset.';
        }

        // --- Performance & Quality dari LHP produksi mesin ini ---
        $rows = TransaksiProduksi::with('partProses:id,sph_standar_produksi')
            ->where('id_mesin', $mesinId)
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->get(['id_part_proses', 'id_mesin', 'qty_aktual', 'qty_ng', 'jam_start', 'jam_finish']);

        $totalQty = 0;
        $totalNg = 0;
        $theoretical = 0.0;
        $rowsNoStd = 0;

        foreach ($rows as $r) {
            $totalQty += (int) $r->qty_aktual;
            $totalNg += (int) $r->qty_ng;

            $std = (float) ($r->partProses->sph_standar_produksi ?? 0);
            $wkj = $r->waktu_kerja_jam; // accessor: desimal jam, handle overnight
            if ($std > 0 && $wkj > 0) {
                $theoretical += $std * $wkj;
            } elseif ($std <= 0) {
                $rowsNoStd++;
            }
        }

        $performancePct = $theoretical > 0 ? round($totalQty / $theoretical * 100, 2) : null;
        /*
         * qty_aktual berisi barang BAIK saja — NG dicatat terpisah (dikonfirmasi
         * ke produksi 2026-07-28). Jadi total diproduksi = baik + NG, dan Quality
         * adalah porsi yang baik dari total itu.
         *
         * Rumus lama (qty_aktual − qty_ng) ÷ qty_aktual mengurangi NG dari barang
         * baik, sehingga NG dihukum dua kali dan Quality jatuh lebih dalam dari
         * seharusnya. Pada qty_ng > qty_aktual rumus itu bahkan menghasilkan
         * Quality NEGATIF, yang lalu masuk ke perkalian OEE.
         */
        $totalProduksi = $totalQty + $totalNg;
        $qualityPct = $totalProduksi > 0 ? round($totalQty / $totalProduksi * 100, 2) : null;

        if ($rows->isEmpty()) {
            $catatan[] = 'Tidak ada LHP produksi di rentang ini: Performance & Quality tak bisa dihitung.';
        } else {
            if ($performancePct === null) {
                $catatan[] = 'Performance tak bisa dihitung: SPH standar proses belum diset.';
            } elseif ($rowsNoStd > 0) {
                $catatan[] = "{$rowsNoStd} LHP diabaikan dari Performance karena SPH standar belum diset.";
            }
        }

        // --- OEE hanya bila ketiga komponen ada ---
        $oeePct = ($availabilityPct !== null && $performancePct !== null && $qualityPct !== null)
            ? round($availabilityPct / 100 * $performancePct / 100 * $qualityPct / 100 * 100, 2)
            : null;
        if ($oeePct === null) {
            $catatan[] = 'OEE tak lengkap: sebagian komponen belum tersedia (lihat catatan di atas).';
        }

        $theoreticalOut = (int) round($theoretical);

        return [
            'availability_pct' => $availabilityPct,
            'performance_pct' => $performancePct,
            'quality_pct' => $qualityPct,
            'oee_pct' => $oeePct,
            'total_qty' => $totalQty,
            'total_ng' => $totalNg,
            'output_teoretis' => $theoreticalOut,
            'jumlah_lhp' => $rows->count(),
            // Rumus + angka yang BENAR-BENAR dipakai sistem, biar chatbot bisa
            // menjelaskan cara hitungnya dari data (bukan rumus textbook tebakan).
            'metodologi' => $this->metodologi($availabilityPct, $performancePct, $qualityPct, $totalQty, $totalNg, $theoreticalOut),
            'catatan' => $catatan,
        ];
    }

    /**
     * Penjelasan rumus tiap komponen OEE + substitusi angkanya. Komponen yang
     * null diberi keterangan "belum bisa dihitung" — jangan diarang.
     *
     * @return array<string,string>
     */
    private function metodologi(?float $av, ?float $perf, ?float $qual, int $qty, int $ng, int $teoretis): array
    {
        return [
            'availability' => $av !== null
                ? "Availability = waktu uptime ÷ jam operasi × 100 = {$av}%. Jam operasi = jam kalender sejak tanggal instalasi mesin − total downtime breakdown (basis kalender 24/7, sama seperti dashboard maintenance)."
                : 'Availability belum bisa dihitung: tanggal instalasi mesin belum diset.',
            'performance' => $perf !== null
                ? "Performance = output aktual ÷ output teoretis × 100 = {$qty} ÷ {$teoretis} × 100 = {$perf}%. Output teoretis = SPH standar produksi × jam kerja tiap LHP (ditotal)."
                : 'Performance belum bisa dihitung: SPH standar proses belum diset.',
            'quality' => $qual !== null
                ? "Quality = qty baik ÷ total diproduksi × 100 = {$qty} ÷ ".($qty + $ng)." × 100 = {$qual}%. Total diproduksi = qty aktual (barang baik) + qty NG."
                : 'Quality belum bisa dihitung: belum ada produksi (baik maupun NG) di rentang ini.',
            'oee' => 'OEE = Availability × Performance × Quality (ketiga dalam desimal), lalu ×100.',
        ];
    }
}
