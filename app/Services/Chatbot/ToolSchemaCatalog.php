<?php

namespace App\Services\Chatbot;

/**
 * Katalog schema function-calling yang dipapar ke LLM. Dipisah dari ChatbotTools
 * (yang mengeksekusi tool) supaya definisi schema — data murni — punya tempat sendiri.
 *
 * Deskripsi schema sengaja padat: dikirim TIAP request × loop, jadi tiap kata
 * makan kuota token provider (free tier ~12K/menit). Nama & parameter tetap utuh.
 */
class ToolSchemaCatalog
{
    /**
     * Tool yang DIPAPAR ke LLM. Sengaja subset esensial: schema dikirim tiap
     * request × loop, jadi makin sedikit tool makin banyak jatah token tersisa
     * untuk jawaban (free tier ~12K/menit). Tool yang butuh komputasi PHP tetap
     * dipapar (MTBF/MTTR, SPH, ranking-availability); agregat sederhana
     * (COUNT/SUM/GROUP BY, Pareto, rekap) dilayani lewat queryDatabase.
     * Method non-papar tetap ada & tetap berfungsi bila dipanggil.
     */
    private const EXPOSED_TOOLS = [
        'getMtbfMttrDies', 'getMtbfMttrMesin',
        'getRekapNg', 'getTotalProduksi', 'bandingkanProduksi', 'getOperatorProduksi',
        'getRankingBreakdownMesin', 'getRankingBreakdownDies',
        'getTopProblemDies', 'getTopProblemMesin',
        'getTotalBreakdownDies', 'getTotalBreakdownMesin',
        'getRekapDandori',
        'describeSchema', 'queryDatabase',
    ];

    /** Schema tool yang dipapar ke LLM (subset EXPOSED_TOOLS), siap dikirim ke provider. */
    public function definitions(): array
    {
        $all = [
            ...$this->mtbfMttrSchemas(),
            ...$this->productionSchemas(),
            ...$this->breakdownAggregateSchemas(),
            ...$this->breakdownDetailSchemas(),
            ...$this->dandoriSchemas(),
            ...$this->dataExplorerSchemas(),
        ];

        return array_values(array_filter($all, fn ($t) => in_array($t['name'], self::EXPOSED_TOOLS, true)));
    }

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
                        'bulan' => ['type' => 'string', 'description' => 'YYYY-MM'],
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
                        'idMesin' => ['type' => 'string', 'description' => 'Kode mesin (mis. A4, B2, C10) ATAU nama mesin (mis. AMADA 80T). Kirim apa adanya yang user sebut.'],
                        'bulan' => ['type' => 'string', 'description' => 'YYYY-MM'],
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
                'description' => 'Total & rate NG (PPM + persen), opsional disaring per nama proses. '
                    .'sumber "produksi" (NG Proses) atau "qc" (NG Total). periode opsional YYYY-MM. '
                    .'Untuk rincian per JENIS NG pakai getParetoNg.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'namaProses' => ['type' => 'string', 'description' => 'Opsional. Nama proses, mis. BENDING. Kosong = semua proses.'],
                        'periode' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=semua'],
                        'sumber' => [
                            'type' => 'string',
                            'enum' => ['produksi', 'qc'],
                            'description' => 'produksi = NG Proses (LHP produksi), qc = NG Total (LHP quality). Default produksi.',
                        ],
                    ],
                    // Lihat RekapNgCapability: namaProses dulu wajib dan memaksa LLM mengarang.
                    'required' => [],
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
            [
                'name' => 'getOperatorProduksi',
                'description' => 'Performa per operator: total qty produksi + rata-rata SPH tiap operator. Pakai untuk "operator produksi terbanyak", "SPH operator terbaik/terendah". Kosong=all-time.',
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
                'description' => 'Total kejadian breakdown semua dies. Kosong=all-time.',
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
                'name' => 'getTotalBreakdownMesin',
                'description' => 'Total kejadian breakdown semua mesin. Kosong=all-time.',
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
