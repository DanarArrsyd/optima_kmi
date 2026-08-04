<?php

namespace App\Services;

use App\Models\TransaksiDandoriRincian;
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
        private KalenderKerjaService $kalender = new KalenderKerjaService,
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
            ->get(['id_part_proses', 'id_mesin', 'tanggal_produksi', 'qty_aktual', 'qty_ng', 'jam_start', 'jam_finish']);

        /*
         * Waktu dandori PER TANGGAL, bukan total rentang.
         *
         * Rumus overlap (KalenderKerjaService::jamProduktif) hanya valid di
         * granularitas harian: pada rentang sebulan, jam terjadwalnya ~216 jam
         * sementara jam LHP sehari cuma 8 — batas "jadwal − setup" tak pernah
         * mengikat dan pengurangannya mati diam-diam.
         */
        $dandoriPerTanggal = TransaksiDandoriRincian::query()
            ->where('id_mesin', $mesinId)
            ->where('tanggal', '>=', $from->toDateString())
            ->where('tanggal', '<', $to->copy()->addDay()->toDateString())
            ->groupBy('tanggal')
            ->selectRaw('tanggal, SUM(actual_menit) as menit')
            ->pluck('menit', 'tanggal')
            ->mapWithKeys(fn ($menit, $tgl) => [Carbon::parse($tgl)->toDateString() => ((float) $menit) / 60])
            ->all();

        $totalQty = 0;
        $totalNg = 0;
        $rowsNoStd = 0;

        // Dikumpulkan PER TANGGAL supaya rumus overlap dipakai di granularitas
        // yang benar.
        $teoretisPerTgl = [];
        $jamPerTgl = [];

        foreach ($rows as $r) {
            $totalQty += (int) $r->qty_aktual;
            $totalNg += (int) $r->qty_ng;

            $std = (float) ($r->partProses->sph_standar_produksi ?? 0);
            $wkj = $r->waktu_kerja_jam; // accessor: desimal jam, handle overnight
            if ($std > 0 && $wkj > 0) {
                $tgl = Carbon::parse($r->tanggal_produksi)->toDateString();
                $teoretisPerTgl[$tgl] = ($teoretisPerTgl[$tgl] ?? 0.0) + $std * $wkj;
                $jamPerTgl[$tgl] = ($jamPerTgl[$tgl] ?? 0.0) + $wkj;
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
        /*
         * Setup dikeluarkan dari penyebut PER TANGGAL. Lihat
         * KalenderKerjaService::jamProduktif() untuk penurunan rumusnya dan
         * alasan granularitas harian itu wajib.
         *
         * Diskalakan proporsional dalam tiap hari, bukan dikurangi dari baris
         * tertentu: SPH standar berbeda antar part_proses, jadi memilih baris mana
         * yang "kehilangan" jam berarti memilih part mana yang dihukum.
         */
        $theoretical = 0.0;
        $hariMustahil = 0;

        foreach ($teoretisPerTgl as $tgl => $teoretisHari) {
            $jamHari = $jamPerTgl[$tgl];
            $dandoriHari = $dandoriPerTanggal[$tgl] ?? 0.0;
            $produktif = $this->kalender->jamProduktif($jamHari, $dandoriHari, Carbon::parse($tgl));

            if ($produktif <= 0) {
                // Setup memakan SELURUH jam LHP hari itu — tak ada waktu produksi
                // yang bisa dinilai. Kontribusinya dibuang, bukan dipaksa: angka
                // apa pun di sini menghasilkan Performance optimis yang SALAH, dan
                // optimis yang salah menyembunyikan masalah.
                $hariMustahil++;

                continue;
            }

            $theoretical += $teoretisHari * ($produktif / $jamHari);
        }

        if ($hariMustahil > 0) {
            $catatan[] = "{$hariMustahil} hari diabaikan dari Performance: waktu dandori memakan "
                .'seluruh jam LHP hari itu. Cek pencatatan dandori.';
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

        /*
         * Performance di atas 100% itu SINYAL, bukan prestasi: di OEE standar
         * Ideal Cycle Time adalah kecepatan DESAIN mesin, jadi output yang
         * melebihinya berarti standarnya yang salah set. sph_standar_produksi
         * dipakai dua tujuan yang bertentangan — target (Achievement) dan
         * kecepatan teoretis (Performance) — dan satu kolom tak bisa jadi keduanya.
         */
        if ($performancePct !== null && $performancePct > 100) {
            $catatan[] = sprintf(
                'Performance %.1f%% melebihi 100%% — SPH standar terlalu rendah (dipakai sebagai '
                .'target, bukan kecepatan teoretis mesin), bukan mesin melampaui kemampuannya.',
                $performancePct
            );
        }

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
