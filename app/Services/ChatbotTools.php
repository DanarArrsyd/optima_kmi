<?php
namespace App\Services;

use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChatbotTools
{
    /** Batas baris detail mentah yang dikirim ke LLM sebelum dipotong. */
    private const MAX_DETAIL_ROWS = 150;

    /** Tanggal lantai untuk analisa "semua periode" (all-time) saat filter bulan kosong. */
    private const ALL_TIME_FROM = '2000-01-01';

    /** Tabel bisnis yang boleh ditelusuri describeSchema/queryDatabase (whitelist). */
    private const BUSINESS_TABLES = [
        'master_karyawan'            => 'Data karyawan/operator/inspector (nik, nama_karyawan, departemen, is_aktif).',
        'master_mesin'               => 'Master mesin press (id_mesin, nama_mesin, line, tipe_mesin, kapasitas_max_stroke).',
        'master_part'                => 'Master part/dies (part_no, part_name, customer, tanggal_instalasi).',
        'master_part_proses'         => 'Proses per part + standar SPH (nama_proses, sph_standar_produksi, sph_standar_qc).',
        'master_part_proses_sph_log' => 'Riwayat perubahan standar SPH per proses.',
        'master_shift'               => 'Master shift (nama_shift, jam_start, jam_finish).',
        'master_shift_istirahat'     => 'Jadwal istirahat per shift.',
        'master_jenis_ng'            => 'Master jenis defect/NG.',
        'master_losstime'            => 'Master jenis losstime dandori + standar menit.',
        'transaksi_produksi'         => 'LHP produksi (tanggal_produksi, id_karyawan, id_mesin, id_part_proses, id_shift, qty_aktual, qty_ng).',
        'transaksi_qc'               => 'LHP quality (tgl_pengecekan, id_karyawan_qc, id_part_proses, qty_ok, qty_ng).',
        'transaksi_dandori'          => 'Header dandori (ganti setup/part).',
        'transaksi_dandori_rincian'  => 'Rincian dandori per losstime (tanggal, id_mesin, id_losstime, actual_menit, std_menit, over_menit).',
        'transaksi_breakdown_mesin'  => 'Breakdown mesin (tanggal_breakdown, id_mesin, problem_mesin, penyebab_breakdown, tindakan_perbaikan, repair_time, total_downtime).',
        'transaksi_breakdown_dies'   => 'Breakdown dies (tanggal_breakdown, id_part_proses, problem_dies, penyebab_breakdown, tindakan_perbaikan, repair_time, total_downtime).',
    ];

    /** Batas baris hasil queryDatabase sebelum ditandai truncated. */
    /** Baris queryDatabase yang dikirim balik ke LLM. Kecil sengaja — 200 baris mentah
     *  membengkakkan token per request sampai kena rate limit provider. LLM cukup butuh
     *  sampel untuk merangkum; kalau kepotong, flag 'truncated' memberi tahu ada sisanya. */
    private const QUERY_ROW_CAP = 25;

    public function __construct(
        private MaintenanceDiesDashboardService $diesDashboard = new MaintenanceDiesDashboardService(),
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService(),
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

    /** Total kejadian breakdown untuk SEMUA dies dalam rentang bulan. */
    public function getTotalBreakdownDies(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = $this->diesDashboard->compute($from, $to);

        return [
            'bulan_mulai'           => $bulanMulai,
            'bulan_akhir'           => $bulanAkhir,
            'total_breakdown'       => array_sum(array_column($rows, 'count')),
            'jumlah_dies_terdampak' => count(array_filter($rows, fn ($r) => $r['count'] > 0)),
        ];
    }

    /** Total kejadian breakdown untuk SEMUA mesin dalam rentang bulan. */
    public function getTotalBreakdownMesin(string $bulanMulai, string $bulanAkhir): array
    {
        [$from, $to, $error] = $this->monthSpan($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = $this->mesinDashboard->compute($from, $to);

        return [
            'bulan_mulai'            => $bulanMulai,
            'bulan_akhir'            => $bulanAkhir,
            'total_breakdown'        => array_sum(array_column($rows, 'count')),
            'jumlah_mesin_terdampak' => count(array_filter($rows, fn ($r) => $r['count'] > 0)),
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
            'total_qty_aktual' => (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'   => (int) round($rows->avg(fn ($r) => $r->sph_aktual)),
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
            'top_problem'    => $groups->toArray(),
        ];
    }

    /** Introspeksi struktur DB untuk LLM: daftar tabel atau kolom real satu tabel. */
    public function describeSchema(?string $table = null): array
    {
        $table = trim($table ?? '');

        if ($table === '') {
            return [
                'tables' => collect(self::BUSINESS_TABLES)
                    ->map(fn ($desc, $name) => ['table' => $name, 'deskripsi' => $desc])
                    ->values()->toArray(),
            ];
        }

        if (!array_key_exists($table, self::BUSINESS_TABLES)) {
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
            'deskripsi' => self::BUSINESS_TABLES[$table],
            'columns'   => $columns,
        ];
    }

    /** Guarded read-only SELECT untuk pertanyaan lintas-tabel di luar tool analitik. */
    public function queryDatabase(string $sql): array
    {
        // 0. Buang komentar DULU supaya tak bisa dipakai memisah token atau menyembunyikan tabel.
        $stripped = preg_replace('/\/\*.*?\*\//s', ' ', trim($sql));
        $stripped = preg_replace('/--[^\n]*/', ' ', $stripped);
        $stripped = trim(preg_replace('/#[^\n]*/', ' ', $stripped));

        // 1. Harus SELECT/WITH.
        if (!preg_match('/^(select|with)\b/i', $stripped)) {
            return ['error' => 'Hanya query baca (SELECT) yang diizinkan.'];
        }

        // 2. Tolak multi-statement (titik-koma selain trailing tunggal).
        if (str_contains(rtrim($stripped, "; \n\t"), ';')) {
            return ['error' => 'Hanya satu perintah query yang diizinkan.'];
        }

        // 3. Tolak keyword tulis/DDL sebagai kata utuh.
        if (preg_match('/\b(insert|update|delete|replace|drop|alter|truncate|create|grant|revoke|attach|pragma|into|lock|call|exec|merge)\b/i', $stripped)) {
            return ['error' => 'Query mengandung operasi yang tidak diizinkan.'];
        }

        // 3b. Tolak fungsi baca-file / bocor-metadata dan akses system-variable — allowlist
        //     tabel bukan sandbox penuh. Bentuk fungsi `fn(` + `@@` untuk system var.
        if (preg_match('/\b(load_file|user|current_user|system_user|session_user|database|schema|version)\s*\(|@@/i', $stripped)) {
            return ['error' => 'Query mengandung fungsi yang tidak diizinkan.'];
        }

        // 4. Blacklist keras: nama tabel sistem / skema metadata tak boleh muncul sama sekali,
        //    apa pun cara referensinya (identifier bersifat atomik — tak bisa dipecah komentar).
        if (preg_match('/\b(users|sessions|jobs|job_batches|failed_jobs|password_reset_tokens|cache|cache_locks|activity_log|app_notifications|chat_conversations|chat_messages|migrations|information_schema|performance_schema|mysql|sys|sqlite_master|sqlite_sequence|sqlite_schema)\b/i', $stripped)) {
            return ['error' => 'Query menyentuh tabel yang tidak tersedia untuk ditelusuri.'];
        }

        // 5. Whitelist positif: tiap tabel setelah FROM/JOIN (termasuk daftar dipisah koma) wajib bisnis.
        preg_match_all('/\b(?:from|join)\s+([a-zA-Z_][a-zA-Z0-9_]*(?:\s*,\s*[a-zA-Z_][a-zA-Z0-9_]*)*)/i', $stripped, $mm);
        $referenced = [];
        foreach ($mm[1] as $clause) {
            foreach (preg_split('/\s*,\s*/', $clause) as $tbl) {
                $referenced[] = strtolower(trim($tbl));
            }
        }
        if (empty($referenced)) {
            return ['error' => 'Query harus menyebut tabel bisnis yang valid.'];
        }
        foreach ($referenced as $tbl) {
            if (!array_key_exists($tbl, self::BUSINESS_TABLES)) {
                return ['error' => "Tabel \"{$tbl}\" tidak tersedia untuk ditelusuri."];
            }
        }

        // 6. Clamp SEMUA angka LIMIT ke cap (bentuk `n` dan `offset, count`), lalu pastikan
        //    ada LIMIT top-level (append kalau query tak diakhiri LIMIT — mis. LIMIT cuma di subquery).
        $capped = preg_replace_callback(
            '/\blimit\s+(\d+)\s*,\s*(\d+)/i',
            fn ($x) => 'LIMIT ' . $x[1] . ', ' . min((int) $x[2], self::QUERY_ROW_CAP),
            $stripped
        );
        $capped = preg_replace_callback(
            '/\blimit\s+(\d+)(?!\s*,)/i',
            fn ($x) => 'LIMIT ' . min((int) $x[1], self::QUERY_ROW_CAP),
            $capped
        );
        if (!preg_match('/\blimit\s+\d+(\s*,\s*\d+)?\s*;?\s*$/i', $capped)) {
            $capped = rtrim($capped, "; \n\t") . ' LIMIT ' . self::QUERY_ROW_CAP;
        }

        // 7. Eksekusi read-only + SELALU rollback (safety net). Cap keras hasil di PHP sebagai
        //    jaminan terakhir (≤ cap) walau ada LIMIT yang lolos di level DB.
        try {
            DB::beginTransaction();
            $rows = DB::select($capped);
        } catch (\Throwable $e) {
            return ['error' => 'Query gagal dijalankan.'];
        } finally {
            try { DB::rollBack(); } catch (\Throwable) {}
        }

        $rows = array_map(fn ($r) => (array) $r, $rows);
        $overCap = count($rows) > self::QUERY_ROW_CAP;
        if ($overCap) {
            $rows = array_slice($rows, 0, self::QUERY_ROW_CAP);
        }

        return [
            'row_count' => count($rows),
            'truncated' => $overCap || count($rows) >= self::QUERY_ROW_CAP,
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
                    'problem'         => trim($latest->{$problemField}),
                    'jumlah_kejadian' => $rows->count(),
                    'pct_dari_total'  => round($rows->count() / $total * 100, 2),
                    'contoh_penyebab' => $latest->{$penyebabField},
                    'contoh_tindakan' => $latest->{$tindakanField},
                ];
            })
            ->sortByDesc('jumlah_kejadian')
            ->values()
            ->take(10);
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

    /** Provider-neutral tool schema (name/description/JSON-Schema parameters), wrapped per-provider by each ChatbotProviderInterface implementation. */
    public function schemaDefinitions(): array
    {
        return [
            ...$this->mtbfMttrSchemas(),
            ...$this->productionSchemas(),
            ...$this->breakdownAggregateSchemas(),
            ...$this->breakdownDetailSchemas(),
            ...$this->dandoriSchemas(),
            ...$this->dataExplorerSchemas(),
        ];
    }

    // Deskripsi schema sengaja padat: dikirim TIAP request × loop, jadi tiap kata
    // makan kuota token provider (free tier ~12K/menit). Nama & parameter tetap utuh.
    private function mtbfMttrSchemas(): array
    {
        return [
            [
                'name' => 'getMtbfMttrDies',
                'description' => 'MTBF/MTTR part/dies per proses, satu bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'partNo' => ['type' => 'string', 'description' => 'No part/dies, mis. BT-091A-3'],
                        'bulan'  => ['type' => 'string', 'description' => 'YYYY-MM'],
                    ],
                    'required' => ['partNo', 'bulan'],
                ],
            ],
            [
                'name' => 'getMtbfMttrMesin',
                'description' => 'MTBF/MTTR satu mesin, satu bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'idMesin' => ['type' => 'string', 'description' => 'Kode mesin, mis. M-01'],
                        'bulan'   => ['type' => 'string', 'description' => 'YYYY-MM'],
                    ],
                    'required' => ['idMesin', 'bulan'],
                ],
            ],
        ];
    }

    private function productionSchemas(): array
    {
        return [
            [
                'name' => 'getRekapNg',
                'description' => 'Total & rate NG per nama proses. periode opsional.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'namaProses' => ['type' => 'string', 'description' => 'Nama proses, mis. BENDING'],
                        'periode'    => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=semua'],
                    ],
                    'required' => ['namaProses'],
                ],
            ],
            [
                'name' => 'bandingkanProduksi',
                'description' => 'Banding qty & rata-rata SPH produksi antar 2 bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periode1' => ['type' => 'string', 'description' => 'YYYY-MM'],
                        'periode2' => ['type' => 'string', 'description' => 'YYYY-MM'],
                    ],
                    'required' => ['periode1', 'periode2'],
                ],
            ],
            [
                'name' => 'getTotalProduksi',
                'description' => 'Total qty (Pcs) & rata-rata SPH semua proses dalam rentang bulan. Kosong=all-time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    private function breakdownAggregateSchemas(): array
    {
        return [
            [
                'name' => 'getTotalBreakdownDies',
                'description' => 'Total kejadian breakdown semua dies dalam rentang bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'YYYY-MM inklusif'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
            [
                'name' => 'getTotalBreakdownMesin',
                'description' => 'Total kejadian breakdown semua mesin dalam rentang bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'YYYY-MM inklusif'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
            [
                'name' => 'getRankingBreakdownMesin',
                'description' => 'Ranking mesin paling sering breakdown. Kosong=all-time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getRankingBreakdownDies',
                'description' => 'Ranking dies/part paling sering breakdown. Kosong=all-time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    private function breakdownDetailSchemas(): array
    {
        return [
            [
                'name' => 'getProblemDies',
                'description' => 'Daftar mentah problem/penyebab/tindakan breakdown dies. Rangkum HANYA yang ada di data. Kosong=all-time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                        'partNo' => ['type' => 'string', 'description' => 'Opsional no part/dies, kosong=semua'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getProblemMesin',
                'description' => 'Seperti getProblemDies untuk mesin.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional kode mesin, kosong=semua'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getTopProblemDies',
                'description' => 'Ranking problem dies paling sering (Pareto) per teks problem. Kosong=all-time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                        'partNo' => ['type' => 'string', 'description' => 'Opsional no part/dies, kosong=semua'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getTopProblemMesin',
                'description' => 'Seperti getTopProblemDies untuk mesin.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional kode mesin, kosong=semua'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    private function dandoriSchemas(): array
    {
        return [
            [
                'name' => 'getRekapDandori',
                'description' => 'Rekap dandori per jenis losstime + total over-menit, rentang bulan. Kosong=all-time.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional kode mesin, kosong=semua'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }

    private function dataExplorerSchemas(): array
    {
        return [
            [
                'name' => 'describeSchema',
                'description' => 'Lihat struktur DB. Tanpa arg: daftar tabel. Dengan table: kolom+tipe. Panggil sebelum queryDatabase.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'table' => ['type' => 'string', 'description' => 'Opsional nama tabel, kosong=daftar semua'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'queryDatabase',
                'description' => 'SQL SELECT read-only untuk pertanyaan di luar tool lain (master data, per-operator/shift, jenis NG, lintas-tabel). Hanya SELECT tabel bisnis. Cek describeSchema dulu. Maks 25 baris.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'sql' => ['type' => 'string', 'description' => 'Satu SELECT MySQL valid. Mis: SELECT nama_karyawan,departemen FROM master_karyawan WHERE is_aktif=1'],
                    ],
                    'required' => ['sql'],
                ],
            ],
        ];
    }
}
