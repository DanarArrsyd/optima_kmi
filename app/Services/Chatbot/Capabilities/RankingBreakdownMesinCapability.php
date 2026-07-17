<?php
namespace App\Services\Chatbot\Capabilities;

/** getRankingBreakdownMesin — ranking mesin paling sering breakdown. */
class RankingBreakdownMesinCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getRankingBreakdownMesin';
    }

    public function description(): string
    {
        return 'Ranking mesin paling sering breakdown. Kosong=all-time.';
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
        return ['ranking mesin', 'mesin sering breakdown', 'mesin sering rusak', 'breakdown mesin', 'mesin bermasalah'];
    }

    public function examples(): array
    {
        return ['Mesin mana yang paling sering breakdown?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getRankingBreakdownMesin(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
