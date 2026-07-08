<?php
namespace App\Services;

use App\Models\MasterMesin;
use App\Models\MasterPart;
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
        ];
    }
}
