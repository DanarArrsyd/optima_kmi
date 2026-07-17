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
 *   - Quality      : (qty_aktual − qty_ng) ÷ qty_aktual.
 *
 * Komponen yang tak bisa dihitung (mis. instalasi belum diset, SPH standar 0)
 * dikembalikan null + catatan — JANGAN ditebak. OEE hanya diisi bila ketiga
 * komponen ada.
 */
class OeeService
{
    public function __construct(
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService(),
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
        $totalNg  = 0;
        $theoretical = 0.0;
        $rowsNoStd = 0;

        foreach ($rows as $r) {
            $totalQty += (int) $r->qty_aktual;
            $totalNg  += (int) $r->qty_ng;

            $std = (float) ($r->partProses->sph_standar_produksi ?? 0);
            $wkj = $r->waktu_kerja_jam; // accessor: desimal jam, handle overnight
            if ($std > 0 && $wkj > 0) {
                $theoretical += $std * $wkj;
            } elseif ($std <= 0) {
                $rowsNoStd++;
            }
        }

        $performancePct = $theoretical > 0 ? round($totalQty / $theoretical * 100, 2) : null;
        $qualityPct     = $totalQty > 0 ? round(($totalQty - $totalNg) / $totalQty * 100, 2) : null;

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

        return [
            'availability_pct' => $availabilityPct,
            'performance_pct'  => $performancePct,
            'quality_pct'      => $qualityPct,
            'oee_pct'          => $oeePct,
            'total_qty'        => $totalQty,
            'total_ng'         => $totalNg,
            'jumlah_lhp'       => $rows->count(),
            'catatan'          => $catatan,
        ];
    }
}
