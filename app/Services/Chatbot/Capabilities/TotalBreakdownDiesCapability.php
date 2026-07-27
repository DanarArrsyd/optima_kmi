<?php

namespace App\Services\Chatbot\Capabilities;

/** getTotalBreakdownDies — total kejadian breakdown semua dies. */
class TotalBreakdownDiesCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getTotalBreakdownDies';
    }

    public function description(): string
    {
        return 'Total kejadian breakdown semua dies. Kosong=all-time.';
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
        return ['total breakdown dies', 'jumlah breakdown dies', 'berapa breakdown dies'];
    }

    public function examples(): array
    {
        return ['Berapa total kejadian breakdown dies?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getTotalBreakdownDies(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
