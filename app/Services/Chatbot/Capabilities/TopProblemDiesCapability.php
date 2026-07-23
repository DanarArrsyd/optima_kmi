<?php

namespace App\Services\Chatbot\Capabilities;

/** getTopProblemDies — ranking problem dies paling sering (Pareto) per teks problem. */
class TopProblemDiesCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getTopProblemDies';
    }

    public function description(): string
    {
        return 'Ranking problem dies paling sering (Pareto) per teks problem. Kosong=all-time.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                'partNo' => ['type' => 'string', 'description' => 'Opsional no part/dies, kosong=semua'],
            ],
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['problem dies', 'masalah dies', 'pareto dies', 'penyebab dies', 'problem dies tersering'];
    }

    public function examples(): array
    {
        return ['Problem dies apa yang paling sering muncul?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getTopProblemDies(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
            $args['partNo'] ?? null,
        );
    }
}
