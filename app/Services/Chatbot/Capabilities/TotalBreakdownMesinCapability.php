<?php

namespace App\Services\Chatbot\Capabilities;

/** getTotalBreakdownMesin — total kejadian breakdown semua mesin. */
class TotalBreakdownMesinCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getTotalBreakdownMesin';
    }

    public function description(): string
    {
        return 'Total kejadian breakdown semua mesin. Kosong=all-time.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
            ],
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['total breakdown mesin', 'jumlah breakdown mesin', 'berapa breakdown mesin'];
    }

    public function examples(): array
    {
        return ['Berapa total kejadian breakdown mesin?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getTotalBreakdownMesin(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
