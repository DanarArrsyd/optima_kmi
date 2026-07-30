<?php

namespace App\Services\Chatbot\Capabilities;

/** getParetoNg — jenis NG terbanyak berikut porsi & kumulatifnya (aturan 80/20). */
class ParetoNgCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getParetoNg';
    }

    public function description(): string
    {
        return 'Pareto jenis NG: jenis defect mana yang paling banyak, berikut porsi & kumulatif persen. '
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
            // Nol parameter wajib: LLM tidak boleh dipaksa mengarang argumen.
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['pareto', 'jenis ng', 'jenis defect', 'defect terbanyak', 'ng terbanyak',
            'dominan', 'penyumbang ng', '80/20', 'kode ng'];
    }

    public function examples(): array
    {
        return [
            'Pareto NG proses',
            'Jenis NG apa yang paling banyak bulan ini?',
            'Defect apa yang paling sering di QC?',
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->getParetoNg(
            $args['sumber'] ?? 'produksi',
            $args['periode'] ?? null,
        );
    }
}
