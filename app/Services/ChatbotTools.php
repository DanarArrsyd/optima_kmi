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

class ChatbotTools
{
    public function __construct(
        private MaintenanceDiesDashboardService $diesDashboard = new MaintenanceDiesDashboardService(),
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService(),
    ) {}

    /** MTBF/MTTR untuk sebuah part/dies (per proses), dalam 1 bulan. */
    public function getMtbfMttrDies(string $partNo, string $bulan): array
    {
        $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
        if (!$part) {
            return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
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
        $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
        if (!$mesin) {
            return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
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
    public function getRekapNg(string $namaProses, string $periode): array
    {
        [$from, $to] = $this->monthRange($periode);
        if (!$from) {
            return ['error' => "Format periode \"{$periode}\" tidak valid, gunakan format YYYY-MM."];
        }

        $q = TransaksiQc::whereHas('partProses', fn ($p) =>
                $p->whereRaw('LOWER(TRIM(nama_proses)) = ?', [strtolower(trim($namaProses))])
            )
            ->whereBetween('tgl_pengecekan', [$from->toDateString(), $to->toDateString()]);

        $totalOk = (int) $q->clone()->sum('qty_ok');
        $totalNg = (int) $q->clone()->sum('qty_ng');
        $total   = $totalOk + $totalNg;

        if ($total === 0) {
            return ['error' => "Tidak ada data QC untuk proses \"{$namaProses}\" di periode {$periode}."];
        }

        return [
            'nama_proses'     => $namaProses,
            'periode'         => $periode,
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
            $part = MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [strtolower(trim($partNo))])->first();
            if (!$part) {
                return ['error' => "Part/dies dengan nomor \"{$partNo}\" tidak ditemukan di master data."];
            }
            $prosesIds = MasterPartProses::where('id_part', $part->id)->pluck('id');
        }

        $query = TransaksiBreakdownDies::with('partProses.part')
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($prosesIds !== null, fn ($q) => $q->whereIn('id_part_proses', $prosesIds))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            $label = $partNo ? " untuk part \"{$partNo}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data breakdown dies{$label}{$periodLabel}."];
        }

        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'part'     => $r->partProses?->part?->part_name ?? '—',
            'problem'  => $r->problem_dies,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        $result = [
            'bulan_mulai'  => $bulanMulai ?? 'semua periode',
            'bulan_akhir'  => $bulanAkhir ?? 'semua periode',
            'total_record' => $total,
            'data'         => $rows,
        ];

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
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
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }

        $query = TransaksiBreakdownMesin::with('mesin')
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->orderByDesc('tanggal_breakdown');

        $total = $query->count();
        if ($total === 0) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data breakdown mesin{$label}{$periodLabel}."];
        }

        $rows = $query->limit(150)->get()->map(fn ($r) => [
            'tanggal'  => $r->tanggal_breakdown->format('Y-m-d'),
            'mesin'    => $r->mesin?->nama_mesin ?? '—',
            'problem'  => $r->problem_mesin,
            'penyebab' => $r->penyebab_breakdown,
            'tindakan' => $r->tindakan_perbaikan,
        ])->toArray();

        $result = [
            'bulan_mulai'  => $bulanMulai ?? 'semua periode',
            'bulan_akhir'  => $bulanAkhir ?? 'semua periode',
            'total_record' => $total,
            'data'         => $rows,
        ];

        if ($total > 150) {
            $result['note'] = "Data dipotong ke 150 record terbaru dari total {$total}. Persempit rentang bulan untuk detail lengkap.";
        }

        return $result;
    }

    /** Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan opsional. */
    public function getRankingBreakdownMesin(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $computeFrom = $from ?? Carbon::createFromDate(2000, 1, 1)->startOfDay();
        $computeTo   = $to ?? Carbon::now()->endOfDay();

        $rows = collect($this->mesinDashboard->compute($computeFrom, $computeTo))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada kejadian breakdown mesin{$periodLabel}."];
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

        $computeFrom = $from ?? Carbon::createFromDate(2000, 1, 1)->startOfDay();
        $computeTo   = $to ?? Carbon::now()->endOfDay();

        $rows = collect($this->diesDashboard->compute($computeFrom, $computeTo))
            ->filter(fn ($r) => $r['count'] > 0)
            ->sortByDesc('count')
            ->values()
            ->take(10);

        if ($rows->isEmpty()) {
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada kejadian breakdown dies{$periodLabel}."];
        }

        return [
            'bulan_mulai' => $bulanMulai ?? 'semua periode',
            'bulan_akhir' => $bulanAkhir ?? 'semua periode',
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
            $mesin = MasterMesin::whereRaw('LOWER(TRIM(id_mesin)) = ?', [strtolower(trim($idMesin))])->first();
            if (!$mesin) {
                return ['error' => "Mesin dengan kode \"{$idMesin}\" tidak ditemukan di master data."];
            }
            $mesinId = $mesin->id;
        }

        $records = TransaksiDandoriRincian::with('losstime')
            ->when($from && $to, fn ($q) => $q->whereBetween('tanggal', [$from->toDateString(), $to->toDateString()]))
            ->when($mesinId !== null, fn ($q) => $q->where('id_mesin', $mesinId))
            ->get();

        if ($records->isEmpty()) {
            $label = $idMesin ? " untuk mesin \"{$idMesin}\"" : '';
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data dandori{$label}{$periodLabel}."];
        }

        $groups = $records->groupBy(fn ($r) => $r->losstime?->jenis_losstime ?? 'Tidak diketahui')
            ->map(fn ($rows, $jenis) => [
                'jenis_losstime'    => $jenis,
                'jumlah_kejadian'   => $rows->count(),
                'total_actual_menit'=> (int) $rows->sum('actual_menit'),
                'total_over_menit'  => (int) $rows->sum('over_menit'),
            ])
            ->sortByDesc('total_over_menit')
            ->values();

        return [
            'bulan_mulai'        => $bulanMulai ?? 'semua periode',
            'bulan_akhir'        => $bulanAkhir ?? 'semua periode',
            'total_kejadian'     => $records->count(),
            'total_over_menit'   => (int) $records->sum('over_menit'),
            'top_jenis_losstime' => $groups->toArray(),
        ];
    }

    /** Total qty aktual & rata-rata SPH produksi untuk SEMUA proses dalam rentang bulan opsional. */
    public function getTotalProduksi(?string $bulanMulai = null, ?string $bulanAkhir = null): array
    {
        [$from, $to, $error] = $this->monthSpanOptional($bulanMulai, $bulanAkhir);
        if ($error) {
            return ['error' => $error];
        }

        $rows = TransaksiProduksi::when($from && $to, fn ($q) => $q->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()]))
            ->get();

        if ($rows->isEmpty()) {
            $periodLabel = ($bulanMulai && $bulanAkhir) ? " di periode {$bulanMulai} s/d {$bulanAkhir}" : '';
            return ['error' => "Tidak ada data produksi{$periodLabel}."];
        }

        return [
            'bulan_mulai'      => $bulanMulai ?? 'semua periode',
            'bulan_akhir'      => $bulanAkhir ?? 'semua periode',
            'total_qty_aktual' => (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'   => (int) round($rows->avg(fn ($r) => $r->sph_aktual)),
        ];
    }

    private function produksiSummary(string $periode): array
    {
        [$from, $to] = $this->monthRange($periode);
        if (!$from) {
            return ['error' => "Format periode \"{$periode}\" tidak valid, gunakan format YYYY-MM."];
        }

        $rows = TransaksiProduksi::whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])->get();

        return [
            'periode'         => $periode,
            'total_qty_aktual'=> (int) $rows->sum('qty_aktual'),
            'avg_sph_aktual'  => $rows->count() > 0 ? (int) round($rows->avg(fn ($r) => $r->sph_aktual)) : 0,
        ];
    }

    private function monthRange(string $bulan): array
    {
        try {
            $from = Carbon::createFromFormat('Y-m', $bulan)->startOfMonth();
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

    /** Provider-neutral tool schema (name/description/JSON-Schema parameters), wrapped per-provider by each ChatbotProviderInterface implementation. */
    public function schemaDefinitions(): array
    {
        return [
            [
                'name' => 'getMtbfMttrDies',
                'description' => 'Ambil MTBF/MTTR untuk sebuah part/dies pada bulan tertentu, per proses.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'partNo' => ['type' => 'string', 'description' => 'Nomor part/dies, contoh: BT-091A-3'],
                        'bulan'  => ['type' => 'string', 'description' => 'Format YYYY-MM, contoh: 2026-07'],
                    ],
                    'required' => ['partNo', 'bulan'],
                ],
            ],
            [
                'name' => 'getMtbfMttrMesin',
                'description' => 'Ambil MTBF/MTTR untuk sebuah mesin pada bulan tertentu.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'idMesin' => ['type' => 'string', 'description' => 'Kode mesin, contoh: M-01'],
                        'bulan'   => ['type' => 'string', 'description' => 'Format YYYY-MM'],
                    ],
                    'required' => ['idMesin', 'bulan'],
                ],
            ],
            [
                'name' => 'getRekapNg',
                'description' => 'Rekap total qty NG dan NG rate untuk sebuah nama proses pada bulan tertentu.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'namaProses' => ['type' => 'string', 'description' => 'Nama proses, contoh: BENDING'],
                        'periode'    => ['type' => 'string', 'description' => 'Format YYYY-MM'],
                    ],
                    'required' => ['namaProses', 'periode'],
                ],
            ],
            [
                'name' => 'bandingkanProduksi',
                'description' => 'Bandingkan total qty aktual & SPH rata-rata produksi antara 2 bulan.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'periode1' => ['type' => 'string', 'description' => 'Format YYYY-MM, periode pertama'],
                        'periode2' => ['type' => 'string', 'description' => 'Format YYYY-MM, periode kedua'],
                    ],
                    'required' => ['periode1', 'periode2'],
                ],
            ],
            [
                'name' => 'getTotalBreakdownDies',
                'description' => 'Total kejadian breakdown untuk SEMUA dies dalam rentang bulan tertentu (dipakai untuk pertanyaan luas/agregat, bukan per part spesifik).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
            [
                'name' => 'getTotalBreakdownMesin',
                'description' => 'Total kejadian breakdown untuk SEMUA mesin dalam rentang bulan tertentu (dipakai untuk pertanyaan luas/agregat, bukan per mesin spesifik).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Bulan mulai, format YYYY-MM'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Bulan akhir (inklusif), format YYYY-MM'],
                    ],
                    'required' => ['bulanMulai', 'bulanAkhir'],
                ],
            ],
            [
                'name' => 'getProblemDies',
                'description' => 'Ambil daftar mentah problem, penyebab, dan tindakan perbaikan breakdown dies dalam rentang bulan. Dipakai untuk pertanyaan root-cause seperti "problem apa yang sering muncul", "penyebabnya apa", "perbaikan yang cocok apa" — kamu HARUS merangkum sendiri dari data yang dikembalikan, jangan menambah pola yang tidak ada di teks. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'partNo' => ['type' => 'string', 'description' => 'Opsional, nomor part/dies spesifik. Kosongkan untuk semua dies.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getProblemMesin',
                'description' => 'Sama seperti getProblemDies tapi untuk mesin — problem/penyebab/tindakan breakdown mesin. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getRankingBreakdownMesin',
                'description' => 'Ranking mesin berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. Dipakai untuk pertanyaan seperti "mesin apa aja yang sering rusak". Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getRankingBreakdownDies',
                'description' => 'Ranking dies/part berdasarkan jumlah kejadian breakdown terbanyak dalam rentang bulan. Dipakai untuk pertanyaan seperti "dies apa aja yang sering rusak". Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getRekapDandori',
                'description' => 'Rekap kejadian dandori (ganti setup/part) dikelompokkan per jenis losstime, termasuk total menit over standar, dalam rentang bulan. Opsional filter per mesin. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                        'idMesin' => ['type' => 'string', 'description' => 'Opsional, kode mesin spesifik. Kosongkan untuk semua mesin.'],
                    ],
                    'required' => [],
                ],
            ],
            [
                'name' => 'getTotalProduksi',
                'description' => 'Total qty aktual produksi (dalam Pcs) dan rata-rata SPH untuk SEMUA proses dalam rentang bulan (bisa multi-bulan). Beda dengan bandingkanProduksi yang membandingkan 2 periode terpisah — ini untuk total gabungan satu rentang. Kosongkan bulanMulai/bulanAkhir untuk analisa semua periode (all-time).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'bulanMulai' => ['type' => 'string', 'description' => 'Opsional, bulan mulai format YYYY-MM. Kosongkan untuk semua periode.'],
                        'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional, bulan akhir (inklusif) format YYYY-MM. Kosongkan untuk semua periode.'],
                    ],
                    'required' => [],
                ],
            ],
        ];
    }
}
