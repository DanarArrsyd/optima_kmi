<?php
namespace App\Services\Chatbot\Capabilities;

/** getRekapDandori — rekap dandori per jenis losstime + total over-menit. */
class RekapDandoriCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getRekapDandori';
    }

    public function description(): string
    {
        return 'Rekap dandori per jenis losstime + total over-menit, rentang bulan. Kosong=all-time.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                'idMesin'    => ['type' => 'string', 'description' => 'Opsional kode mesin, kosong=semua'],
            ],
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['dandori', 'losstime', 'setup', 'ganti part', 'over menit', 'over-menit'];
    }

    public function examples(): array
    {
        return ['Jenis losstime dandori apa yang paling banyak makan waktu?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getRekapDandori(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
            $args['idMesin'] ?? null,
        );
    }
}
