<?php
namespace App\Services;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Services\Chatbot\BusinessTableCatalog;
use App\Services\Chatbot\SqlGuard;
use App\Services\Chatbot\ToolSchemaCatalog;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facade tool chatbot: mengeksekusi tool analitik domain (MTBF/MTTR, breakdown,
 * dandori, produksi) + memapar schema + guarded SQL. Tanggung jawab yang berdiri
 * sendiri sudah dipisah: whitelist tabel ({@see BusinessTableCatalog}), validasi
 * SQL ({@see SqlGuard}), dan katalog schema LLM ({@see ToolSchemaCatalog}).
 */
class ChatbotTools
{
    /** Batas baris detail mentah yang dikirim ke LLM sebelum dipotong. */
    private const MAX_DETAIL_ROWS = 150;

    /** Tanggal lantai untuk analisa "semua periode" (all-time) saat filter bulan kosong. */
    private const ALL_TIME_FROM = '2000-01-01';

    public function __construct(
        private MaintenanceDiesDashboardService $diesDashboard = new MaintenanceDiesDashboardService(),
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService(),
        private BusinessTableCatalog $tableCatalog = new BusinessTableCatalog(),
        private SqlGuard $sqlGuard = new SqlGuard(),
        private ToolSchemaCatalog $schemaCatalog = new ToolSchemaCatalog(),
    ) {}

    /** MTBF/MTTR untuk sebuah part/dies (per proses), dalam 1 bulan. */
    public function getMtbfMttrDies(string $partNo, string $bulan): array
    {
        $part = $this->findPartByNo($partNo);
        if (is_array($part)) {
            return $part;
        }

        [$from, $to] = $this->monthRange($bulan);
        if (!$from) {
            return ['error' => "Format bulan \"{$bulan}\" tidak valid, gunakan format YYYY-MM."];
        }

        $rows = $this->diesDashboard->compute($from, $to, $part->id);
        if (empty($rows)) {
            return ['error' => "Tidak ada data breakdown untuk part \"{$partNo}\" di periode {$bulan}."];
        }

        return array_map(fn ($r) => [
            'nama_proses'      => $r['nama_proses'],
            'count'            => $r['count'],
            'mttr_jam'         => $r['mttr_jam'],
            'mtbf_jam'         => $r['mtbf_jam'],
            'availability_pct' => $r['availability_pct'],
        ], $rows);
    }

    /** MTBF/MTTR untuk sebuah mesin, dalam 1 bulan. */
    public function getMtbfMttrMesin(string $idMesin, string $bulan): array
    {
        $mesin = $this->findMesinByKode($idMesin);
        if (is_array($mesin)) {
            return $mesin;
        }

        [$from, $to] = $this->monthRange($bulan);
        if (!$from) {
            return ['error' => "Format bulan \"{$bulan}\" tidak valid, gunakan format YYYY-MM."];
        }

        $rows = $this->mesinDashboard->compute($from, $to, $mesin->id);
        if (empty($rows)) {
            return ['error' => "Tidak ada data breakdown untuk mesin \"{$idMesin}\" di periode {$bulan}."];
        }

        return [
            'count'            => $rows[0]['count'],
            'mttr_jam'         => $rows[0]['mttr_jam'],
            'mtbf_jam'         => $rows[0]['mtbf_jam'],
            'availability_pct' => $rows[0]['availability_pct'],
        ];
    }

    /** Rekap qty NG untuk sebuah nama proses, dalam 1 bulan. */
    public function getRekapNg(string $namaProses, ?string $periode = null): array
    {
        $from = $to = null;
        if ($periode !== null) {
            [$from, $to] = $this->monthRange($periode);
            if (!$from) {
                return ['error' => "Format periode \"{$periode}\" tidak valid, gunakan format YYYY-MM."];
            }
        }

        $q = TransaksiQc::whereHas('partProses', fn ($p) =>
                $p->whereRaw('LOWER(TRIM(nama_proses)) = ?', [strtolower(trim($namaProses))])
            )
            ->when($from && $to, fn ($x) => $x->whereBetween('tgl_pengecekan', [$from->toDateString(), $to->toDateString()]));

        $totalOk = (int) $q->clone()->sum('qty_ok');
        $totalNg = (int) $q->clone()->sum('qty_ng');
        $total   = $totalOk + $totalNg;

        if ($total === 0) {
            $periodeLabel = $periode ? " di periode {$periode}" : '';
            return ['error' => "Tidak ada data QC untuk proses \"{$namaProses}\"{$periodeLabel}."];
        }

        return [
            'nama_proses'     => $namaProses,
            'periode'         => $periode ?? 'semua data',
            'total_ok'        => $totalOk,
            'total_ng'        => $totalNg,
            'total_inspeksi'  => $total,
            'ng_rate_pct'     => round($totalNg / $total * 100, 2),
        ];
    }

    /** Bandingkan qty aktual & SPH rata-rata produksi antara 2 periode bulanan. */
    public function bandingkanProduksi(string $periode1, string $periode2): array
    {
        $p1 = $this->produksiSummary($periode1);
        $p2 = $this->produksiSummary($periode2);

        if (isset($p1['error']) || isset($p2['error'])) {
            return ['error' => $p1['error'] ?? $p2['error']];
        }

        return [
            'periode1'            => $p1,
            'periode2'            => $p2,
            'delta_qty_aktual'    => $p2['total_qty_aktual'] - $p1['total_qty_aktual'],
            'delta_avg_sph_aktual'=> $p2['avg_sph_aktual'] - $p1['avg_sph_aktual'],
        ];
    }

    /** Total kejadian breakdown SEMUA dies. Hitung baris mentah (konsisten dgn getTopProblemDies). Bulan opsional = all-time. */
    public function getTotalBreakdownDies(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $q = TransaksiBreakdownDies::query()
            ->when($from && $to, fn ($x) => $x->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]));

        return [
            'bulan_mulai'           => $bulanMulai ?? 'semua periode',
            'bulan_akhir'           => $bulanAkhir ?? 'semua periode',
            'total_breakdown'       => (int) $q->clone()->count(),
            'jumlah_dies_terdampak' => (int) $q->clone()->distinct('id_part_proses')->count('id_part_proses'),
        ];
    }

    /** Total kejadian breakdown SEMUA mesin. Hitung baris mentah (konsisten dgn getTopProblemMesin). Bulan opsional = all-time. */
    public function getTotalBreakdownMesin(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $q = TransaksiBreakdownMesin::query()
            ->when($from && $to, fn ($x) => $x->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]));

        return [
            'bulan_mulai'            => $bulanMulai ?? 'semua periode',
            'bulan_akhir'            => $bulanAkhir ?? 'semua periode',
            'total_breakdown'        => (int) $q->clone()->count(),
            'jumlah_mesin_terdampak' => (int) $q->clone()->distinct('id_mesin')->count('id_mesin'),
        ];
    }

    /** Daftar mentah problem/penyebab/tindakan breakdown dies dalam rentang bulan opsional (opsional filter per part). */
    public function getProblemDies(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $partNo = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $prosesIds = null;
        if ($partNo !== null) {
            $part = $this->findPartByNo($partNo);
            if (is_array($part)) {
                return $part;
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }

        $query = TransaksiBreakdownDies::with('partProses.part')
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($prosesIds !== null, fn ($q) => $q->whereIn('id_part_proses', $prosesIds))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            return ['error' => "Tidak ada data breakdown dies" . $this->filterLabel($partNo, 'part') . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        $rows = $query->limit(self::MAX_DETAIL_ROWS)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'part'     => $r->partProses?->part?->part_name ?? '—',
            'problem'  => $r->problem_dies,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        return $this->truncatedResult($total, $rows, $bulanMulai ?? 'semua periode', $bulanAkhir ?? 'semua periode');
    }

    /** Daftar mentah problem/penyebab/tindakan breakdown mesin dalam rentang bulan opsional (opsional filter per mesin). */
    public function getProblemMesin(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = $this->findMesinByKode($idMesin);
            if (is_array($mesin)) {
                return $mesin;
            }
            $mesinId = $mesin->id;
        }

        $query = TransaksiBreakdownMesin::with('mesin')
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            return ['error' => "Tidak ada data breakdown mesin" . $this->filterLabel($idMesin, 'mesin') . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        $rows = $query->limit(self::MAX_DETAIL_ROWS)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'mesin'    => $r->mesin?->nama_mesin ?? '—',
            'problem'  => $r->problem_mesin,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        return $this->truncatedResult($total, $rows, $bulanMulai ?? 'semua periode', $bulanAkhir ?? 'semua periode');
    }

    /** Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan opsional. */
    public function getRankingBreakdownMesin(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        [$computeFrom, $computeTo] = $this->allTimeWindow($from, $to);

        $rows = collect($this->mesinDashboard->compute($computeFrom, $computeTo))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada kejadian breakdown mesin" . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        return [
            'bulan_mulai' => $bulanMulai ?? 'semua periode',
            'bulan_akhir' => $bulanAkhir ?? 'semua periode',
            'ranking'     => $rows->map(fn ($r) => [
                'mesin'            => $r['nama_mesin'],
                'kode_mesin'       => $r['kode_mesin'],
                'jumlah_breakdown' => $r['count'],
                'availability_pct' => $r['availability_pct'],
            ])->toArray(),
        ];
    }

    /** Ranking dies/part berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan opsional. */
    public function getRankingBreakdownDies(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        [$computeFrom, $computeTo] = $this->allTimeWindow($from, $to);

        $rows = collect($this->diesDashboard->compute($computeFrom, $computeTo))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada kejadian breakdown dies" . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        return [
            'bulan_mulai' => $bulanMulai ?? 'semua periode',
            'bulan_akhir' => $bulanAkhir ?? 'semua periode',
            // MaintenanceDiesDashboardService sengaja mirror key mesin agar view dashboard
            // dies & mesin bisa dipakai bareng: 'nama_mesin' = part_name, 'kode_mesin' = part_no.
            // Jangan tergoda "membetulkan" jadi $r['part_name'] — key itu tidak ada.
            // Kontrak mapping ini dikunci test_get_ranking_breakdown_dies_maps_part_fields.
            'ranking'     => $rows->map(fn ($r) => [
                'part'             => $r['nama_mesin'],
                'part_no'          => $r['kode_mesin'],
                'nama_proses'      => $r['nama_proses'],
                'jumlah_breakdown' => $r['count'],
            ])->toArray(),
        ];
    }

    /** Rekap kejadian dandori dikelompokkan per jenis losstime dalam rentang bulan opsional (opsional filter per mesin). */
    public function getRekapDandori(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = $this->findMesinByKode($idMesin);
            if (is_array($mesin)) {
                return $mesin;
            }
            $mesinId = $mesin->id;
        }

        // Agregasi di DB (GROUP BY jenis_losstime) — hindari load semua rincian ke memori.
        // leftJoin + deleted_at IS NULL meniru eager-load ->with('losstime'): losstime yang
        // soft-deleted diperlakukan sebagai relasi kosong → jatuh ke "Tidak diketahui".
        $groups = TransaksiDandoriRincian::query()
            ->leftJoin('master_losstime', function ($join) {
                $join->on('transaksi_dandori_rincian.id_losstime', '=', 'master_losstime.id')
                     ->whereNull('master_losstime.deleted_at');
            })
            ->when($from && $to, fn ($q) => $q->whereBetween('transaksi_dandori_rincian.tanggal', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('transaksi_dandori_rincian.id_mesin', $mesinId))
            ->groupBy('jenis_losstime')
            ->orderByRaw('SUM(transaksi_dandori_rincian.over_menit) DESC')
            ->get([
                DB::raw("COALESCE(master_losstime.jenis_losstime, 'Tidak diketahui') as jenis_losstime"),
                DB::raw('COUNT(*) as jumlah_kejadian'),
                DB::raw('SUM(transaksi_dandori_rincian.actual_menit) as total_actual_menit'),
                DB::raw('SUM(transaksi_dandori_rincian.over_menit) as total_over_menit'),
            ]);

        if ($groups->isEmpty()) {
            return [
                'total_kejadian'     => 0,
                'top_jenis_losstime' => [],
                'catatan'            => "Belum ada data dandori" . $this->filterLabel($idMesin, 'mesin') . $this->periodeLabel($bulanMulai, $bulanAkhir) . " yang tercatat.",
            ];
        }

        $topJenis = $groups->map(fn ($r) => [
            'jenis_losstime'     => $r->jenis_losstime,
            'jumlah_kejadian'    => (int) $r->jumlah_kejadian,
            'total_actual_menit' => (int) $r->total_actual_menit,
            'total_over_menit'   => (int) $r->total_over_menit,
        ])->values();

        return [
            'bulan_mulai'        => $bulanMulai ?? 'semua periode',
            'bulan_akhir'        => $bulanAkhir ?? 'semua periode',
            'total_kejadian'     => (int) $topJenis->sum('jumlah_kejadian'),
            'total_over_menit'   => (int) $topJenis->sum('total_over_menit'),
            'top_jenis_losstime' => $topJenis->toArray(),
        ];
    }

    /** Total qty aktual & rata-rata SPH produksi untuk SEMUA proses dalam rentang bulan opsional. */
    public function getTotalProduksi(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        // avg_sph_aktual bergantung pada accessor per-row (parse jam_start/jam_finish),
        // jadi tidak bisa dijadikan AVG() SQL murni. Minimal: hydrate hanya kolom yang
        // dipakai accessor + agregasi, bukan seluruh baris — kurangi jejak memori.
        $rows = TransaksiProduksi::when($from && $to, fn ($q) => $q->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()]))
            ->get(['qty_aktual', 'jam_start', 'jam_finish']);

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada data produksi" . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        return [
            'bulan_mulai'      => $bulanMulai ?? 'semua periode',
            'bulan_akhir'      => $bulanAkhir ?? 'semua periode',
            'jumlah_lhp'       => $rows->count(),
            'total_qty_aktual' => (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'   => (int) round($rows->avg(fn ($r) => $r->sph_aktual)),
        ];
    }

    /**
     * Performa per operator: total qty, jumlah LHP, DAN rata-rata SPH.
     * SPH = accessor per baris (qty/waktu kerja), jadi tak bisa via SQL murni —
     * dihitung di PHP. Kembalikan semua operator (cap 50) supaya model bisa jawab
     * "produksi terbanyak", "SPH terbaik", maupun "SPH terendah" dari satu daftar.
     */
    public function getOperatorProduksi(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = TransaksiProduksi::when($from && $to, fn ($q) => $q->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()]))
            ->get(['id_karyawan', 'qty_aktual', 'jam_start', 'jam_finish']);

        if ($rows->isEmpty()) {
            return ['error' => "Tidak ada data produksi" . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        $nama = MasterKaryawan::pluck('nama_karyawan', 'id');

        $ranking = $rows
            ->groupBy('id_karyawan')
            ->map(fn ($g, $id) => [
                'operator'         => $nama[$id] ?? 'Tidak diketahui',
                'total_qty_aktual' => (int) $g->sum('qty_aktual'),
                'jumlah_lhp'       => $g->count(),
                'avg_sph_aktual'   => (int) round($g->avg(fn ($r) => $r->sph_aktual)),
            ])
            ->sortByDesc('total_qty_aktual')
            ->values()
            ->take(50)
            ->toArray();

        return [
            'bulan_mulai' => $bulanMulai ?? 'semua periode',
            'bulan_akhir' => $bulanAkhir ?? 'semua periode',
            'catatan'     => 'Daftar performa per operator: total_qty_aktual (produksi) & avg_sph_aktual (SPH rata-rata). Untuk "SPH terbaik/terendah", bandingkan avg_sph_aktual.',
            'ranking'     => $ranking,
        ];
    }

    /** Ranking problem dies PALING SERING muncul (Pareto), dikelompokkan per teks problem, dalam rentang bulan opsional. */
    public function getTopProblemDies(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $partNo = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $prosesIds = null;
        if ($partNo !== null) {
            $part = $this->findPartByNo($partNo);
            if (is_array($part)) {
                return $part;
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }

        $records = TransaksiBreakdownDies::query()
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($prosesIds !== null, fn ($q) => $q->whereIn('id_part_proses', $prosesIds))
            ->orderByDesc('tanggal_breakdown')
            ->get(['problem_dies', 'penyebab_breakdown', 'tindakan_perbaikan', 'tanggal_breakdown']);

        if ($records->isEmpty()) {
            return ['error' => "Tidak ada data breakdown dies" . $this->filterLabel($partNo, 'part') . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        $groups = $this->groupByFrequency($records, 'problem_dies', 'penyebab_breakdown', 'tindakan_perbaikan');

        return [
            'bulan_mulai'    => $bulanMulai ?? 'semua periode',
            'bulan_akhir'    => $bulanAkhir ?? 'semua periode',
            'total_kejadian' => $records->count(),
            'pareto'         => $this->paretoFocus($records, 'problem_dies'),
            'top_keyword'    => $this->keywordFrequency($records, 'problem_dies'),
            'top_problem'    => $groups->toArray(),
        ];
    }

    /** Ranking problem mesin PALING SERING muncul (Pareto), dikelompokkan per teks problem, dalam rentang bulan opsional. */
    public function getTopProblemMesin(?string $bulanMulai = null, ?string $bulanAkhir = null, ?string $idMesin = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $mesinId = null;
        if ($idMesin !== null) {
            $mesin = $this->findMesinByKode($idMesin);
            if (is_array($mesin)) {
                return $mesin;
            }
            $mesinId = $mesin->id;
        }

        $records = TransaksiBreakdownMesin::query()
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown')
            ->get(['problem_mesin', 'penyebab_breakdown', 'tindakan_perbaikan', 'tanggal_breakdown']);

        if ($records->isEmpty()) {
            return ['error' => "Tidak ada data breakdown mesin" . $this->filterLabel($idMesin, 'mesin') . $this->periodeLabel($bulanMulai, $bulanAkhir) . "."];
        }

        $groups = $this->groupByFrequency($records, 'problem_mesin', 'penyebab_breakdown', 'tindakan_perbaikan');

        return [
            'bulan_mulai'    => $bulanMulai ?? 'semua periode',
            'bulan_akhir'    => $bulanAkhir ?? 'semua periode',
            'total_kejadian' => $records->count(),
            'pareto'         => $this->paretoFocus($records, 'problem_mesin'),
            'top_keyword'    => $this->keywordFrequency($records, 'problem_mesin'),
            'top_problem'    => $groups->toArray(),
        ];
    }

    /** Introspeksi struktur DB untuk LLM: daftar tabel atau kolom real satu tabel. */
    public function describeSchema(?string $table = null): array
    {
        $table = trim($table ?? '');

        if ($table === '') {
            return [
                'tables' => collect($this->tableCatalog->all())
                    ->map(fn ($desc, $name) => ['table' => $name, 'deskripsi' => $desc])
                    ->values()->toArray(),
            ];
        }

        if (!$this->tableCatalog->has($table)) {
            return ['error' => "Tabel \"{$table}\" tidak tersedia untuk ditelusuri."];
        }

        try {
            $columns = array_map(
                fn ($col) => ['nama' => $col, 'tipe' => Schema::getColumnType($table, $col)],
                Schema::getColumnListing($table)
            );
        } catch (\Throwable $e) {
            return ['error' => "Struktur tabel \"{$table}\" tidak bisa dibaca saat ini."];
        }

        return [
            'table'     => $table,
            'deskripsi' => $this->tableCatalog->describe($table),
            'columns'   => $columns,
        ];
    }

    /** Guarded read-only SELECT untuk pertanyaan lintas-tabel di luar tool analitik. */
    public function queryDatabase(string $sql): array
    {
        // Validasi & clamp LIMIT dipisah ke SqlGuard (boundary keamanan teruji terisolasi).
        $guard = $this->sqlGuard->sanitize($sql);
        if (isset($guard['error'])) {
            return $guard;
        }
        $capped = $guard['sql'];

        // Eksekusi read-only + SELALU rollback (safety net). Cap keras hasil di PHP sebagai
        // jaminan terakhir (≤ cap) walau ada LIMIT yang lolos di level DB.
        try {
            DB::beginTransaction();
            $rows = DB::select($capped);
        } catch (\Throwable $e) {
            return ['error' => 'Query gagal dijalankan.'];
        } finally {
            try { DB::rollBack(); } catch (\Throwable) {}
        }

        $rows = array_map(fn ($r) => (array) $r, $rows);
        $overCap = count($rows) > SqlGuard::ROW_CAP;
        if ($overCap) {
            $rows = array_slice($rows, 0, SqlGuard::ROW_CAP);
        }

        return [
            'row_count' => count($rows),
            'truncated' => $overCap || count($rows) >= SqlGuard::ROW_CAP,
            'rows'      => $rows,
        ];
    }

    private function produksiSummary(string $periode): array
    {
        [$from, $to] = $this->monthRange($periode);
        if (!$from) {
            return ['error' => "Format periode \"{$periode}\" tidak valid, gunakan format YYYY-MM."];
        }

        // Hydrate hanya kolom yang dipakai (lihat catatan di getTotalProduksi).
        $rows = TransaksiProduksi::whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->get(['qty_aktual', 'jam_start', 'jam_finish']);

        return [
            'periode'         => $periode,
            'total_qty_aktual'=> (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'  => $rows->count() > 0 ? (int) round($rows->avg(fn ($r) => $r->sph_aktual)) : 0,
        ];
    }

    private function monthRange(string $bulan): array
    {
        $bulan = trim($bulan);
        $year = $month = null;

        // Toleran terhadap variasi format yang sering dikirim LLM:
        // "2026-07", "2026-7", "2026/07", "2026-07-09", "07-2026", dll.
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})/', $bulan, $m)) {
            [$year, $month] = [(int) $m[1], (int) $m[2]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{4})$/', $bulan, $m)) {
            [$year, $month] = [(int) $m[2], (int) $m[1]];
        }

        if ($year !== null && $month >= 1 && $month <= 12 && $year >= 2000 && $year <= 2100) {
            $from = Carbon::create($year, $month, 1)->startOfMonth();
            return [$from, $from->copy()->endOfMonth()];
        }

        // Fallback: parser umum Carbon (mis. nama bulan "Juli 2026").
        try {
            $from = Carbon::parse($bulan)->startOfMonth();
            return [$from, $from->copy()->endOfMonth()];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private function monthSpan(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, ] = $this->monthRange($bulanMulai);
        [, $to]   = $this->monthRange($bulanAkhir);

        if (!$from || !$to) {
            return [null, null, 'Format bulan tidak valid, gunakan format YYYY-MM.'];
        }
        if ($from->gt($to)) {
            return [null, null, 'Bulan mulai tidak boleh setelah bulan akhir.'];
        }

        return [$from, $to, null];
    }

    private function monthSpanOptional(?string $bulanMulai, ?string $bulanAkhir): array
    {
        if ($bulanMulai === null && $bulanAkhir === null) {
            return [null, null, null];
        }
        if ($bulanMulai === null || $bulanAkhir === null) {
            return [null, null, 'Kalau isi salah satu periode, isi juga yang satunya.'];
        }

        return $this->monthSpan($bulanMulai, $bulanAkhir);
    }

    private function truncatedResult(int $total, array $rows, string $bulanMulaiLabel, string $bulanAkhirLabel, array $extra = []): array
    {
        $result = array_merge([
            'bulan_mulai'  => $bulanMulaiLabel,
            'bulan_akhir'  => $bulanAkhirLabel,
            'total_record' => $total,
            'data'         => $rows,
        ], $extra);

        if ($total > self::MAX_DETAIL_ROWS) {
            $result['note'] = "Data dipotong ke " . self::MAX_DETAIL_ROWS . " record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }

    private function groupByFrequency(\Illuminate\Support\Collection $records, string $problemField, string $penyebabField, string $tindakanField): \Illuminate\Support\Collection
    {
        $total = $records->count();

        return $records
            ->groupBy(fn ($r) => strtolower(trim($r->{$problemField})))
            ->map(function ($rows) use ($total, $problemField, $penyebabField, $tindakanField) {
                $latest = $rows->first();
                return [
                    'problem'          => trim($latest->{$problemField}),
                    'jumlah_kejadian'  => $rows->count(),
                    'pct_dari_total'   => round($rows->count() / $total * 100, 2),
                    // Penyebab & tindakan TERSERING yang tercatat di data (basis rekomendasi).
                    'penyebab_teratas' => $this->topValues($rows, $penyebabField, 2),
                    'tindakan_teratas' => $this->topValues($rows, $tindakanField, 2),
                ];
            })
            ->sortByDesc('jumlah_kejadian')
            ->values()
            ->take(10);
    }

    /** Nilai teks yang paling sering muncul di sebuah field (top $n, dengan frekuensi). */
    private function topValues(\Illuminate\Support\Collection $rows, string $field, int $n): array
    {
        return $rows
            ->map(fn ($r) => trim((string) $r->{$field}))
            ->filter(fn ($v) => $v !== '')
            ->countBy()
            ->sortDesc()
            ->take($n)
            ->map(fn ($count, $teks) => ['teks' => $teks, 'jumlah' => $count])
            ->values()
            ->toArray();
    }

    /**
     * Ringkasan Pareto: berapa SEDIKIT problem teratas yang sudah menutup ~$threshold%
     * dari seluruh kejadian (dihitung atas SEMUA grup problem, bukan cuma top 10).
     */
    private function paretoFocus(\Illuminate\Support\Collection $records, string $problemField, float $threshold = 80.0): array
    {
        $counts = $records
            ->groupBy(fn ($r) => strtolower(trim($r->{$problemField})))
            ->map->count()
            ->sortDesc()
            ->values();

        $total = $counts->sum();
        if ($total === 0) {
            return [];
        }

        $cum = 0;
        $n = 0;
        foreach ($counts as $c) {
            $cum += $c;
            $n++;
            if ($cum / $total * 100 >= $threshold) {
                break;
            }
        }

        return [
            'jumlah_problem_fokus' => $n,
            'total_jenis_problem'  => $counts->count(),
            'threshold_pct'        => $threshold,
            'cakupan_pct'          => round($cum / $total * 100, 2),
        ];
    }

    /**
     * Grouping DETERMINISTIK per kata kunci cacat, dihitung dari data (bukan tebakan LLM).
     * Untuk tiap event, ambil kata (huruf ≥3, buang filler), lalu hitung berapa event
     * yang problem-nya memuat kata itu. Contoh: semua varian "BURRY" → total event-nya.
     */
    private function keywordFrequency(\Illuminate\Support\Collection $records, string $problemField): array
    {
        $stop = [
            'HASIL','PART','PROSES','MENGALAMI','PADA','YANG','DAN','ATAU','DARI','UNTUK',
            'DENGAN','TIDAK','ADA','ADALAH','KARENA','SAAT','AKAN','DIES','DIE','PCS','MESIN',
        ];
        $total = $records->count();
        $counts = [];
        foreach ($records as $r) {
            preg_match_all('/[A-Za-z]{3,}/', (string) $r->{$problemField}, $m);
            $words = array_unique(array_map('strtoupper', $m[0]));
            foreach ($words as $w) {
                if (in_array($w, $stop, true)) {
                    continue;
                }
                $counts[$w] = ($counts[$w] ?? 0) + 1;
            }
        }
        arsort($counts);

        $out = [];
        foreach (array_slice($counts, 0, 10, true) as $kw => $c) {
            $out[] = [
                'keyword'         => $kw,
                'jumlah_kejadian' => $c,
                'pct_dari_total'  => $total > 0 ? round($c / $total * 100, 2) : 0,
            ];
        }
        return $out;
    }

    /** Rentang compute untuk ranking: pakai filter bulan, atau all-time kalau kosong. */
    private function allTimeWindow(?Carbon $from, ?Carbon $to): array
    {
        return [
            $from ?? Carbon::parse(self::ALL_TIME_FROM)->startOfDay(),
            $to ?? Carbon::now()->endOfDay(),
        ];
    }

    /** Potongan " di periode X s/d Y" untuk pesan error, kosong kalau bulan tidak diisi. */
    private function periodeLabel(?string $bulanMulai, ?string $bulanAkhir): string
    {
        return ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
    }

    /** Potongan " untuk {noun} \"{value}\"" untuk pesan error, kosong kalau filter tidak diisi. */
    private function filterLabel(?string $value, string $noun): string
    {
        return $value ? " untuk {$noun} \"{$value}\"" : '';
    }

    private function findPartByNo(string $partNo): MasterPart|array
    {
        $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
        return $part ?? ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
    }

    private function findMesinByKode(string $idMesin): MasterMesin|array
    {
        $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
        return $mesin ?? ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
    }

    /**
     * Schema function-calling yang dipapar ke LLM. Data schema-nya hidup di
     * ToolSchemaCatalog; method ini tetap public karena dipanggil provider
     * ({@see OpenAiCompatibleChatbotService::toolSchema()}).
     */
    public function schemaDefinitions(): array
    {
        return $this->schemaCatalog->definitions();
    }
}
