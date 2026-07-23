<?php

namespace App\Services\Chatbot\Capabilities;

/** getRootCause — bahan RCA (penyebab & tindakan tercatat) untuk breakdown mesin/dies. */
class RootCauseCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getRootCause';
    }

    public function description(): string
    {
        return 'Bahan Root Cause Analysis (RCA/5-Why/Fishbone) untuk breakdown. Fokus 1 problem (tersering atau yang diminta) + penyebab & tindakan yang TERCATAT di lapangan (dengan frekuensi) + contoh kejadian. Pakai ini untuk susun analisa, jangan ngarang akar.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'target' => ['type' => 'string', 'enum' => ['mesin', 'dies'], 'description' => 'Breakdown mesin atau dies.'],
                'kode' => ['type' => 'string', 'description' => 'Opsional kode/nama mesin (mis. A4, AMADA 80T) atau part/dies. Kosong=semua.'],
                'problem' => ['type' => 'string', 'description' => 'Opsional teks problem yang mau dianalisa (boleh sebagian). Kosong=problem paling sering.'],
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif'],
            ],
            'required' => ['target'],
        ];
    }

    public function keywords(): array
    {
        return ['rca', 'root cause', 'akar masalah', '5 why', '5why', 'fishbone', 'kenapa', 'penyebab utama', 'analisa penyebab', 'kenapa breakdown'];
    }

    public function examples(): array
    {
        return [
            'RCA breakdown mesin A4 dong',
            'Akar masalah dies paling sering breakdown apa?',
            '5 why kenapa mesin A4 sering rusak',
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->getRootCause(
            $args['target'] ?? '',
            $args['kode'] ?? null,
            $args['problem'] ?? null,
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
