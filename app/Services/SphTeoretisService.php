<?php

namespace App\Services;

use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;

/**
 * Kandidat SPH TEORETIS per part+proses, diusulkan dari data LHP historis.
 *
 * READ-ONLY. Kelas ini tidak menulis apa pun — ia menghasilkan USULAN untuk
 * ditinjau manusia. Menulisnya langsung ke master adalah keputusan terpisah.
 *
 * ── Kenapa ini ada ───────────────────────────────────────────────────────
 * sph_standar_produksi dipakai dua tujuan yang bertentangan: TARGET (menilai
 * operator lewat Achievement) dan KECEPATAN TEORETIS (menilai mesin lewat
 * Performance). Satu kolom tak bisa jadi keduanya, dan akibatnya Performance
 * bisa melebihi 100% — bukan karena mesin melampaui kemampuan fisiknya, tapi
 * karena operator melampaui target. Di OEE standar (Nakajima/TPM, ISO 22400-2)
 * penyebut Performance adalah Ideal Cycle Time: kecepatan DESAIN, bukan target.
 *
 * ── Kenapa bukan MAX() ───────────────────────────────────────────────────
 * MAX adalah nilai paling ekstrem, jadi justru paling mungkin salah input.
 * Satu typo qty (3.000 → 30.000) atau satu jam_finish salah akan menyetel palang
 * SELAMANYA, dan arah kerusakannya jahat: SPH teoretis yang menggelembung
 * menekan Performance part itu secara permanen. Mesin terlihat buruk dan tak ada
 * yang bisa menjelaskan kenapa — kebalikan dari masalah sekarang, dan jauh lebih
 * sulit dicurigai karena tidak ada yang mempertanyakan angka yang terlihat jelek.
 *
 * Empat pengaman, berlapis:
 *   1. Baris anomali DIBUANG, kecuali sudah di-review admin. Memakai definisi
 *      yang SAMA dengan halaman Anomali LHP (config/anomali_lhp.php) — bukan
 *      salinan angkanya.
 *   2. Persentil, bukan maksimum. Outlier tunggal tidak menentukan hasil.
 *   3. Minimum jumlah baris DAN minimum tanggal berbeda. Rekor yang cuma sekali
 *      tercapai bukan kemampuan terbukti.
 *   4. Batas kewajaran terhadap target. Kandidat yang jauh di atas target
 *      ditandai CURIGA, tidak diusulkan.
 *
 * ⚠️ SISA RISIKO yang tidak bisa ditutup statistik: salah input yang KONSISTEN
 * (mis. satu operator berbulan-bulan menulis 2 jam padahal kerja 8 jam) tidak
 * tampak sebagai outlier karena ia menjadi mayoritas datanya. Yang menangkapnya
 * hanya mata orang yang tahu part itu, saat meninjau kandidatnya. Langkah tinjau
 * bukan formalitas — itu satu-satunya pertahanan untuk kelas error ini.
 */
class SphTeoretisService
{
    /** Persentil yang dipakai sebagai kandidat. 95 = tahan outlier, masih dekat kemampuan puncak. */
    public const PERSENTIL = 95;

    /** Minimum baris LHP bersih sebelum sebuah kandidat layak diusulkan. */
    public const MIN_BARIS = 5;

    /** Minimum tanggal BERBEDA. Lima baris di satu hari yang sama bukan bukti kemampuan. */
    public const MIN_TANGGAL = 2;

    /**
     * Kandidat di atas (target × ini) ditandai CURIGA.
     *
     * Teoretis realistis biasanya 1,1–1,5× target — targetnya kan teoretis minus
     * kelonggaran. Tiga kali lipat bukan kelonggaran, itu salah input.
     *
     * ⚠️ TUGASNYA BUKAN menangkap salah input mentah — filter anomali sudah
     * melakukannya lebih dulu, dan ambangnya (150% target) jauh di bawah batas ini
     * (300%), jadi baris mentah yang seekstrem itu tak pernah sampai ke sini.
     *
     * Yang ditangkap batas ini: baris yang sudah di-review dan di-OK admin tapi
     * TETAP absurd. Review manusia adalah satu-satunya jalan melewati filter
     * anomali, dan meng-OK borongan tanpa dilihat itu hal yang wajar terjadi.
     * Ini jaring terakhirnya.
     */
    public const MAKS_RASIO = 3.0;

    public function __construct(
        private KalenderKerjaService $kalender = new KalenderKerjaService,
    ) {}

    /**
     * @return list<array{
     *   part_no: string, nama_part: string, nama_proses: string,
     *   sph_target: float, kandidat: float|null, rasio: float|null,
     *   baris_bersih: int, baris_dibuang: int, tanggal_berbeda: int,
     *   status: string, alasan: string
     * }>
     */
    public function kandidat(): array
    {
        $rows = TransaksiProduksi::with('partProses.part')
            ->get([
                'id_part_proses', 'id_mesin', 'tanggal_produksi',
                'qty_aktual', 'jam_start', 'jam_finish', 'durasi_menit',
                'anomaly_reviewed_at',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $dandori = $this->dandoriPerMesinTanggal();
        $jamLhpPerMesinTgl = $this->jamLhpPerMesinTanggal($rows);

        $pctMaks = (float) config('anomali_lhp.pct_maks', 150);
        $wkjMin = (float) config('anomali_lhp.wkj_min', 0.083);

        $perProses = [];

        foreach ($rows as $r) {
            $pr = $r->partProses;
            if ($pr === null) {
                continue;
            }

            $kunciProses = $pr->id;
            $perProses[$kunciProses] ??= ['pr' => $pr, 'sph' => [], 'tanggal' => [], 'dibuang' => 0];

            $sphStd = (float) ($pr->sph_standar_produksi ?? 0);
            $wkj = (float) $r->waktu_kerja_jam;

            if ($wkj <= 0 || $r->qty_aktual <= 0) {
                continue;
            }

            /*
             * Definisi anomali SAMA dengan halaman Anomali LHP — dibaca dari
             * config yang sama, bukan disalin angkanya. Baris anomali dibuang
             * KECUALI admin sudah me-review dan meng-OK-nya: review itu berarti
             * manusia sudah menyatakan angkanya nyata.
             */
            $sphAktualMentah = $r->qty_aktual / $wkj;
            $pct = $sphStd > 0 ? $sphAktualMentah / $sphStd * 100 : null;

            $anomali = ($pct !== null && $pct > $pctMaks)
                || ($wkj < $wkjMin)
                || ($sphStd <= 0);

            if ($anomali && $r->anomaly_reviewed_at === null) {
                $perProses[$kunciProses]['dibuang']++;

                continue;
            }

            /*
             * SPH dihitung atas jam PRODUKTIF (setelah setup dikeluarkan), sama
             * seperti penyebut Performance. Memakai jam LHP mentah membuat
             * kandidatnya terlalu RENDAH, dan kandidat yang terlalu rendah
             * membuat Performance terlalu TINGGI — arah optimis yang justru
             * sedang diperbaiki.
             */
            $tgl = Carbon::parse($r->tanggal_produksi);
            $kunciHari = $r->id_mesin.'|'.$tgl->toDateString();

            $jamHariMesin = $jamLhpPerMesinTgl[$kunciHari] ?? $wkj;
            $produktifHariMesin = $this->kalender->jamProduktif(
                $jamHariMesin,
                $dandori[$kunciHari] ?? 0.0,
                $tgl
            );

            // Skala proporsional: bagian jam baris ini dari total jam mesin+hari.
            $jamProduktifBaris = $jamHariMesin > 0
                ? $wkj * ($produktifHariMesin / $jamHariMesin)
                : 0.0;

            if ($jamProduktifBaris <= 0) {
                $perProses[$kunciProses]['dibuang']++;

                continue;
            }

            $perProses[$kunciProses]['sph'][] = $r->qty_aktual / $jamProduktifBaris;
            $perProses[$kunciProses]['tanggal'][$tgl->toDateString()] = true;
        }

        return $this->rangkum($perProses);
    }

    /** @param array<int, array{pr: mixed, sph: list<float>, tanggal: array<string,bool>, dibuang: int}> $perProses */
    private function rangkum(array $perProses): array
    {
        $hasil = [];

        foreach ($perProses as $g) {
            $pr = $g['pr'];
            $sphStd = (float) ($pr->sph_standar_produksi ?? 0);
            $jumlah = count($g['sph']);
            $tanggal = count($g['tanggal']);

            [$kandidat, $status, $alasan] = $this->nilai($g['sph'], $tanggal, $sphStd);

            $hasil[] = [
                'part_no' => (string) ($pr->part->part_no ?? '—'),
                'nama_part' => (string) ($pr->part->part_name ?? '—'),
                'nama_proses' => (string) ($pr->nama_proses ?? '—'),
                'sph_target' => round($sphStd, 1),
                'kandidat' => $kandidat === null ? null : round($kandidat, 1),
                'rasio' => ($kandidat !== null && $sphStd > 0) ? round($kandidat / $sphStd, 2) : null,
                'baris_bersih' => $jumlah,
                'baris_dibuang' => $g['dibuang'],
                'tanggal_berbeda' => $tanggal,
                'status' => $status,
                'alasan' => $alasan,
            ];
        }

        /*
         * Diurut rasio menurun: part dengan jurang terlebar antara kemampuan dan
         * target adalah penyumbang Performance >100%, jadi paling layak ditinjau
         * lebih dulu. Yang tidak punya rasio ditaruh belakang.
         */
        usort($hasil, fn ($a, $b) => ($b['rasio'] ?? -1) <=> ($a['rasio'] ?? -1));

        return $hasil;
    }

    /**
     * @param  list<float>  $sph
     * @return array{0: float|null, 1: string, 2: string}
     */
    private function nilai(array $sph, int $tanggalBerbeda, float $sphStd): array
    {
        $jumlah = count($sph);

        if ($jumlah < self::MIN_BARIS) {
            return [null, 'kurang_data', "Cuma {$jumlah} baris bersih, minimum ".self::MIN_BARIS.'.'];
        }

        if ($tanggalBerbeda < self::MIN_TANGGAL) {
            return [null, 'kurang_data', "Cuma {$tanggalBerbeda} tanggal berbeda, minimum ".self::MIN_TANGGAL.'.'];
        }

        $kandidat = $this->persentil($sph, self::PERSENTIL);

        if ($sphStd <= 0) {
            return [$kandidat, 'curiga', 'SPH target belum diset, kandidat tak bisa dibandingkan.'];
        }

        $rasio = $kandidat / $sphStd;

        if ($rasio > self::MAKS_RASIO) {
            return [
                $kandidat,
                'curiga',
                sprintf('Kandidat %.1f× target — di atas batas %.1f×, hampir pasti salah input.', $rasio, self::MAKS_RASIO),
            ];
        }

        return [$kandidat, 'siap', sprintf('%.2f× target, dari %d baris / %d tanggal.', $rasio, $jumlah, $tanggalBerbeda)];
    }

    /** @param  list<float>  $nilai */
    private function persentil(array $nilai, int $p): float
    {
        sort($nilai);
        $idx = (int) ceil($p / 100 * count($nilai)) - 1;

        return $nilai[max(0, min($idx, count($nilai) - 1))];
    }

    /** @return array<string, float> menit→jam, kunci "idMesin|Y-m-d" */
    private function dandoriPerMesinTanggal(): array
    {
        return TransaksiDandoriRincian::query()
            ->groupBy('id_mesin', 'tanggal')
            ->selectRaw('id_mesin, tanggal, SUM(actual_menit) as menit')
            ->get()
            ->mapWithKeys(fn ($r) => [
                $r->id_mesin.'|'.Carbon::parse($r->tanggal)->toDateString() => ((float) $r->menit) / 60,
            ])
            ->all();
    }

    /** @return array<string, float> kunci "idMesin|Y-m-d" */
    private function jamLhpPerMesinTanggal(mixed $rows): array
    {
        $hasil = [];

        foreach ($rows as $r) {
            $wkj = (float) $r->waktu_kerja_jam;
            if ($wkj <= 0) {
                continue;
            }
            $kunci = $r->id_mesin.'|'.Carbon::parse($r->tanggal_produksi)->toDateString();
            $hasil[$kunci] = ($hasil[$kunci] ?? 0.0) + $wkj;
        }

        return $hasil;
    }
}
