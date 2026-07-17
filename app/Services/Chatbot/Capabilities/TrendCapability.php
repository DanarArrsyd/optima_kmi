<?php
namespace App\Services\Chatbot\Capabilities;

/** getTrend — deret bulanan sebuah metrik untuk analisa tren (naik/turun). */
class TrendCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getTrend';
    }

    public function description(): string
    {
        return 'Tren bulanan sebuah metrik + ringkasan arah (naik/turun). metric: produksi (qty), ng (rate NG %), breakdown_mesin, breakdown_dies, dandori (over-menit). Default 6 bulan terakhir.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'metric' => [
                    'type' => 'string',
                    'enum' => ['produksi', 'ng', 'breakdown_mesin', 'breakdown_dies', 'dandori'],
                    'description' => 'Metrik yang ingin dilihat trennya.',
                ],
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=6 bulan terakhir'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif'],
            ],
            'required' => ['metric'],
        ];
    }

    public function keywords(): array
    {
        return ['trend', 'tren', 'perkembangan', 'per bulan', 'bulanan', 'naik turun', 'grafik', 'pergerakan', 'tiap bulan'];
    }

    public function examples(): array
    {
        return [
            'Tren produksi 6 bulan terakhir gimana?',
            'Tren NG rate naik atau turun?',
            'Perkembangan breakdown mesin per bulan.',
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->getTrend(
            $args['metric'] ?? '',
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
