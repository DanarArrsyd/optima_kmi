<?php

namespace App\Services\Chatbot\Capabilities;

/** getTopProblemMesin — ranking problem mesin paling sering (Pareto) per teks problem. */
class TopProblemMesinCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getTopProblemMesin';
    }

    public function description(): string
    {
        return 'Seperti getTopProblemDies untuk mesin.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                'idMesin' => ['type' => 'string', 'description' => 'Opsional kode mesin, kosong=semua'],
            ],
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['problem mesin', 'masalah mesin', 'pareto mesin', 'penyebab mesin', 'problem mesin tersering'];
    }

    public function examples(): array
    {
        return ['Problem mesin apa yang paling sering terjadi?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getTopProblemMesin(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
            $args['idMesin'] ?? null,
        );
    }
}
