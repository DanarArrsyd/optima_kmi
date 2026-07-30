<?php

namespace App\Services\Chatbot\Capabilities;

/** getNgRate — NG rate dalam PPM dan persen, plus tren per bulan. */
class NgRateCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getNgRate';
    }

    public function description(): string
    {
        return 'NG rate dalam PPM dan persen sekaligus, plus tren per bulan. '
            .'Penyebut = barang baik + NG (total diproduksi). '
            .'sumber "produksi" (NG Proses) atau "qc" (NG Total). periode opsional YYYY-MM.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sumber' => [
                    'type' => 'string',
                    'enum' => ['produksi', 'qc'],
                    'description' => 'produksi = NG Proses (LHP produksi), qc = NG Total (LHP quality). Default produksi.',
                ],
                'periode' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong = semua data'],
            ],
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['ppm', 'ng rate', 'rate ng', 'persentase ng', 'berapa persen ng',
            'tingkat cacat', 'defect rate', 'tren ng'];
    }

    public function examples(): array
    {
        return [
            'Berapa PPM NG?',
            'NG rate bulan Juli berapa?',
            'Tren NG proses setahun terakhir',
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->getNgRate(
            $args['sumber'] ?? 'produksi',
            $args['periode'] ?? null,
        );
    }
}
