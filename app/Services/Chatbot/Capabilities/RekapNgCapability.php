<?php

namespace App\Services\Chatbot\Capabilities;

/** getRekapNg — total & rate NG per nama proses, periode opsional. */
class RekapNgCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getRekapNg';
    }

    public function description(): string
    {
        return 'Total & rate NG (PPM + persen), opsional disaring per nama proses. '
            .'sumber "produksi" (NG Proses) atau "qc" (NG Total). periode opsional YYYY-MM. '
            .'Untuk rincian per JENIS NG pakai getParetoNg.';
    }

    public function parameters(): array
    {
        return [
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
            /*
             * NOL parameter wajib. Dulu namaProses required, dan itu memaksa LLM
             * mengarang isinya: "Pareto NG proses" masuk sebagai namaProses="PARETO"
             * lalu dijawab "tidak ada data QC untuk proses PARETO".
             */
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['ng', 'cacat', 'defect', 'reject', 'rekap ng', 'quality', 'qc', 'rate'];
    }

    public function examples(): array
    {
        return ['Berapa rate NG proses bending?', 'Total NG bulan Juli berapa?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getRekapNg(
            $args['namaProses'] ?? null,
            $args['periode'] ?? null,
            $args['sumber'] ?? 'produksi',
        );
    }
}
