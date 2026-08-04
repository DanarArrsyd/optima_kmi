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
 *   - Availability : dari MaintenanceDashboardService (jam shift TERJADWAL −
 *     downtime breakdown − waktu dandori). Jam terjadwal = jumlah jam shift yang
 *     ditandai master_shift.dihitung_availability, dikurangi istirahat, hanya
 *     pada hari kerja. Kalau basisnya diubah, ubah di MaintenanceDashboardService
 *     — jangan di sini.
 *   - Performance  : total diproduksi (qty_aktual + qty_ng) ÷ output teoretis
 *     pada SPH standar, DIKURANGI waktu dandori dari penyebutnya.
 *     Konvensi pencatatan: waktu ganti dies MASUK ke jam_start–jam_finish LHP,
 *     jadi kalau setup dibiarkan di penyebut ia dihukum DUA KALI — sekali di
 *     Performance, sekali di Availability. Di OEE standar penyebut Performance
 *     adalah Operating Time, yaitu waktu SETELAH setup dikeluarkan.
 *     SENGAJA BEDA dari "Achievement %" di AnomalyController dan VCB Produksi,
 *     yang memakai barang baik saja DAN tidak mengurangi dandori: keduanya
 *     menilai output OPERATOR atas jam yang dia pegang. Mengkredit operator atas
 *     barang NG itu salah, dan apakah setup tanggung jawabnya adalah pertanyaan
 *     bisnis — bukan efek samping perubahan rumus OEE.
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
        $jamLhp = 0.0;
        $rowsNoStd = 0;

        foreach ($rows as $r) {
            $totalQty += (int) $r->qty_aktual;
            $totalNg += (int) $r->qty_ng;

            $std = (float) ($r->partProses->sph_standar_produksi ?? 0);
            $wkj = $r->waktu_kerja_jam; // accessor: desimal jam, handle overnight
            if ($std > 0 && $wkj > 0) {
                $theoretical += $std * $wkj;
                $jamLhp += $wkj;
            } elseif ($std <= 0) {
                $rowsNoStd++;
            }
        }

        $totalProduksi = $totalQty + $totalNg;

        /*
         * ── Waktu dandori dikeluarkan dari penyebut Performance ──
         *
         * Konvensi pencatatan yang disepakati: waktu ganti dies MASUK ke dalam
         * jam_start–jam_finish LHP. Operator mencatat jendela shift apa adanya,
         * jadi jam LHP memuat setup.
         *
         * Kalau setup dibiarkan di penyebut, ia dihukum DUA KALI: sekali menekan
         * Performance (jam terpakai tanpa output) dan sekali menekan Availability
         * (dandori sudah dikurangi di MaintenanceDashboardService). Pemisahan tiga
         * komponen OEE ada justru untuk mencegah hukuman ganda itu — di OEE
         * standar penyebut Performance adalah Operating Time, yaitu waktu SETELAH
         * setup dikeluarkan.
         *
         * Diskalakan proporsional, bukan dikurangi dari satu baris: SPH standar
         * berbeda antar part_proses, jadi mengurangi jam dari baris tertentu akan
         * memilih part mana yang "kehilangan" kapasitas. Rasio membagi
         * pengurangannya menurut kontribusi jam tiap baris, tertimbang SPH-nya
         * sendiri.
         *
         * sum_dandori diambil dari baris compute() yang SUDAH di-query untuk
         * Availability — nol query tambahan, dan angkanya dijamin sama dengan yang
         * dipakai Availability.
         */
        $dandoriJam = (float) ($avRow['sum_dandori'] ?? 0);
        $jamBersih = $jamLhp - $dandoriJam;

        if ($jamLhp > 0 && $dandoriJam > 0) {
            if ($jamBersih <= 0) {
                /*
                 * Data MUSTAHIL di konvensi "include": dandori tidak bisa lebih
                 * lama dari jam LHP yang memuatnya. Berarti baris ini diisi dengan
                 * konvensi lama (dandori DI LUAR jam LHP) dan tercampur.
                 *
                 * Penyebut dinolkan supaya Performance jadi null + bercatatan,
                 * BUKAN diam-diam menghasilkan angka besar. Angka optimis yang
                 * salah menyembunyikan masalah; null memaksa orang melihat.
                 */
                $theoretical = 0.0;
                $catatan[] = sprintf(
                    'Waktu dandori (%.1f jam) melebihi jam LHP (%.1f jam) — Performance tak bisa dihitung. '
                    .'Kemungkinan LHP diisi TANPA memasukkan waktu dandori, bercampur dengan konvensi baru.',
                    $dandoriJam,
                    $jamLhp
                );
            } else {
                $theoretical *= $jamBersih / $jamLhp;
            }
        }

        /*
         * Performance mengukur KECEPATAN MESIN, jadi pembilangnya total yang
         * benar-benar diproduksi — mesin tetap menekan untuk barang NG.
         *
         * Rumus lama memakai qty_aktual (barang baik saja), sehingga NG dihukum
         * di Performance DAN di Quality. Pemisahan tiga komponen OEE ada justru
         * untuk mencegah hukuman ganda itu, dan efeknya berlipat: pada 3000 baik
         * + 200 NG dengan teoretis 3200, P x Q lama memberi 87,89% padahal yang
         * benar 93,75% — selisih 5,86 poin.
         */
        $performancePct = $theoretical > 0 ? round($totalProduksi / $theoretical * 100, 2) : null;

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
                ? 'Performance = total diproduksi ÷ output teoretis × 100 = '.($qty + $ng)." ÷ {$teoretis} × 100 = {$perf}%. Total diproduksi = qty aktual (barang baik) + qty NG, karena mesin tetap menekan untuk barang NG. Output teoretis = SPH standar produksi × jam kerja tiap LHP (ditotal)."
                : 'Performance belum bisa dihitung: SPH standar proses belum diset.',
            'quality' => $qual !== null
                ? "Quality = qty baik ÷ total diproduksi × 100 = {$qty} ÷ ".($qty + $ng)." × 100 = {$qual}%. Total diproduksi = qty aktual (barang baik) + qty NG."
                : 'Quality belum bisa dihitung: belum ada produksi (baik maupun NG) di rentang ini.',
            'oee' => 'OEE = Availability × Performance × Quality (ketiga dalam desimal), lalu ×100.',
        ];
    }
}
