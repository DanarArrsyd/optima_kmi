<?php

namespace App\Services\Chatbot\Capabilities;

/** getRankingBreakdownDies — ranking dies/part paling sering breakdown. */
class RankingBreakdownDiesCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getRankingBreakdownDies';
    }

    public function description(): string
    {
        return 'Ranking dies/part paling sering breakdown. Kosong=all-time.';
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
        return ['ranking dies', 'dies sering breakdown', 'dies sering rusak', 'breakdown dies', 'dies bermasalah'];
    }

    public function examples(): array
    {
        return ['Dies mana yang paling sering breakdown?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getRankingBreakdownDies(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
