<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;

/**
 * Menganalisis data LHP Produksi & LHP Quality dan mengidentifikasi kombinasi
 * Part/Proses yang SPH aktualnya konsisten melampaui SPH Standard — kandidat upgrade.
 *
 * Rekomendasi = rata-rata SPH aktual dari sesi yang melampaui standard,
 *               dibulatkan ke kelipatan 10 terdekat.
 *
 * Isolasi data lama: jika SPH pernah diupgrade, hanya data SETELAH tanggal upgrade
 * yang dianalisis, sehingga data era standar lama tidak mempengaruhi rekomendasi baru.
 */
class SphRecommendationService
{
    /* ══════════════════════════════════════════════════════
     |  PUBLIC API — PRODUKSI
     ╚══════════════════════════════════════════════════════ */

    /** Semua rekomendasi upgrade SPH Produksi. */
    public function getAll(): Collection
    {
        return $this->produksiQuery()
            ->orderByDesc('exceed_count')
            ->get()
            ->map(fn($r) => $this->enrichProduksi($r))
            ->filter()
            ->values();
    }

    /** Rekomendasi upgrade SPH Produksi untuk satu part. */
    public function forPart(int $idPart): Collection
    {
        return $this->produksiQuery()
            ->where('pp.id_part', $idPart)
            ->get()
            ->map(fn($r) => $this->enrichProduksi($r))
            ->filter()
            ->values();
    }

    /* ══════════════════════════════════════════════════════
     |  PUBLIC API — QUALITY
     ╚══════════════════════════════════════════════════════ */

    /** Semua rekomendasi upgrade SPH Quality. */
    public function getAllQc(): Collection
    {
        return $this->qcQuery()
            ->orderByDesc('exceed_count')
            ->get()
            ->map(fn($r) => $this->enrichQc($r))
            ->filter()
            ->values();
    }

    /** Rekomendasi upgrade SPH Quality untuk satu part. */
    public function forPartQc(int $idPart): Collection
    {
        return $this->qcQuery()
            ->where('pp.id_part', $idPart)
            ->get()
            ->map(fn($r) => $this->enrichQc($r))
            ->filter()
            ->values();
    }

    /* ══════════════════════════════════════════════════════
     |  INTERNALS
     ╚══════════════════════════════════════════════════════ */

    /**
     * Ekspresi SQL untuk SPH Produksi aktual.
     * Identik dengan TransaksiProduksi::getSphAktualAttribute()
     */
    private function sphProdExpr(string $alias = 'lhp'): string
    {
        return "ROUND(
            {$alias}.qty_aktual * 3600 / GREATEST(
                IF({$alias}.jam_finish > {$alias}.jam_start,
                    TIME_TO_SEC(TIMEDIFF({$alias}.jam_finish, {$alias}.jam_start)),
                    TIME_TO_SEC(TIMEDIFF(ADDTIME({$alias}.jam_finish, '24:00:00'), {$alias}.jam_start))
                ),
                1
            )
        )";
    }

    /**
     * Ekspresi SQL untuk SPH Quality aktual.
     * Identik dengan TransaksiQc::getSphAktualAttribute() — pakai qty_ok + qty_ng
     */
    private function sphQcExpr(string $alias = 'qc'): string
    {
        return "ROUND(
            ({$alias}.qty_ok + COALESCE({$alias}.qty_ng, 0)) * 3600 / GREATEST(
                IF({$alias}.jam_finish > {$alias}.jam_start,
                    TIME_TO_SEC(TIMEDIFF({$alias}.jam_finish, {$alias}.jam_start)),
                    TIME_TO_SEC(TIMEDIFF(ADDTIME({$alias}.jam_finish, '24:00:00'), {$alias}.jam_start))
                ),
                1
            )
        )";
    }

    private function waktuExpr(string $alias): string
    {
        return "GREATEST(
            IF({$alias}.jam_finish > {$alias}.jam_start,
                TIME_TO_SEC(TIMEDIFF({$alias}.jam_finish, {$alias}.jam_start)),
                TIME_TO_SEC(TIMEDIFF(ADDTIME({$alias}.jam_finish, '24:00:00'), {$alias}.jam_start))
            ),
            1
        )";
    }

    /* ── Produksi query ──────────────────────────────────── */

    private function produksiQuery()
    {
        $sph = $this->sphProdExpr();

        return DB::table('transaksi_produksi as lhp')
            ->join('master_part_proses as pp', 'lhp.id_part_proses', '=', 'pp.id')
            ->join('master_part as p', 'pp.id_part', '=', 'p.id')
            ->whereNull('lhp.deleted_at')
            ->whereNull('pp.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('pp.sph_standar_produksi', '>', 0)
            /* Isolasi data lama: hanya scan setelah tanggal upgrade terakhir */
            ->where(function ($q) {
                $q->whereNull('pp.sph_last_upgraded_at')
                  ->orWhereRaw('lhp.tanggal_produksi >= DATE(pp.sph_last_upgraded_at)');
            })
            ->select(
                'pp.id                    as id_part_proses',
                'pp.id_part',
                'p.part_no',
                'p.part_name',
                'p.customer',
                'pp.id_proses',
                'pp.nama_proses',
                'pp.sph_standar_produksi  as sph_standar',
                'pp.sph_last_upgraded_at  as last_upgraded_at',
                DB::raw('COUNT(lhp.id)                                                               as total_sessions'),
                DB::raw("SUM(CASE WHEN {$sph} > pp.sph_standar_produksi THEN 1 ELSE 0 END)          as exceed_count"),
                DB::raw("MAX({$sph})                                                                  as max_sph"),
                DB::raw("AVG(CASE WHEN {$sph} > pp.sph_standar_produksi THEN {$sph} ELSE NULL END)  as avg_exceed_sph"),
            )
            ->groupBy(
                'pp.id', 'pp.id_part',
                'p.part_no', 'p.part_name', 'p.customer',
                'pp.id_proses', 'pp.nama_proses',
                'pp.sph_standar_produksi', 'pp.sph_last_upgraded_at',
            )
            ->having('exceed_count', '>', 0);
    }

    /* ── QC query ────────────────────────────────────────── */

    private function qcQuery()
    {
        $sph = $this->sphQcExpr();

        return DB::table('transaksi_qc as qc')
            ->join('master_part_proses as pp', 'qc.id_part_proses', '=', 'pp.id')
            ->join('master_part as p', 'pp.id_part', '=', 'p.id')
            ->whereNull('qc.deleted_at')
            ->whereNull('pp.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('pp.sph_standar_qc', '>', 0)
            /* Isolasi data lama: hanya scan setelah tanggal upgrade terakhir QC */
            ->where(function ($q) {
                $q->whereNull('pp.sph_qc_last_upgraded_at')
                  ->orWhereRaw('qc.tgl_pengecekan >= DATE(pp.sph_qc_last_upgraded_at)');
            })
            ->select(
                'pp.id                       as id_part_proses',
                'pp.id_part',
                'p.part_no',
                'p.part_name',
                'p.customer',
                'pp.id_proses',
                'pp.nama_proses',
                'pp.sph_standar_qc           as sph_standar',
                'pp.sph_qc_last_upgraded_at  as last_upgraded_at',
                DB::raw('COUNT(qc.id)                                                          as total_sessions'),
                DB::raw("SUM(CASE WHEN {$sph} > pp.sph_standar_qc THEN 1 ELSE 0 END)          as exceed_count"),
                DB::raw("MAX({$sph})                                                            as max_sph"),
                DB::raw("AVG(CASE WHEN {$sph} > pp.sph_standar_qc THEN {$sph} ELSE NULL END)  as avg_exceed_sph"),
            )
            ->groupBy(
                'pp.id', 'pp.id_part',
                'p.part_no', 'p.part_name', 'p.customer',
                'pp.id_proses', 'pp.nama_proses',
                'pp.sph_standar_qc', 'pp.sph_qc_last_upgraded_at',
            )
            ->having('exceed_count', '>', 0);
    }

    /* ── Enrich helpers ──────────────────────────────────── */

    private function enrichProduksi(object $row): ?object
    {
        return $this->enrich($row, 'sph_standar_produksi');
    }

    private function enrichQc(object $row): ?object
    {
        return $this->enrich($row, 'sph_standar_qc');
    }

    /**
     * Hitung nilai turunan dan buang baris yang tidak layak direkomendasikan.
     * $stdKey digunakan sebagai alias balik ke nama kolom asli (untuk view).
     */
    private function enrich(object $row, string $stdKey): ?object
    {
        if (!$row->avg_exceed_sph) return null;

        // Rekomendasi = rata-rata sesi yang melampaui, bulatkan ke 10 terdekat
        $recommended = (int) (round((float) $row->avg_exceed_sph / 10) * 10);
        $current     = (int) $row->sph_standar;

        if ($recommended <= $current) return null;

        $row->recommended_sph = $recommended;
        $row->max_sph         = (int) round((float) $row->max_sph);
        $row->avg_exceed_sph  = (int) round((float) $row->avg_exceed_sph);
        $row->exceed_count    = (int) $row->exceed_count;
        $row->total_sessions  = (int) $row->total_sessions;
        $row->sph_standar     = $current;

        // Buat alias yang sama dipakai view (backward compat produksi)
        $row->$stdKey         = $current;

        $row->exceed_pct  = $row->total_sessions > 0
            ? (int) round($row->exceed_count / $row->total_sessions * 100)
            : 0;

        $row->increase_pct = (int) round(($row->recommended_sph / $current - 1) * 100);

        return $row;
    }
}
