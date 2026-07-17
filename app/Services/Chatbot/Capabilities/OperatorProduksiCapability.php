<?php
namespace App\Services\Chatbot\Capabilities;

/** getOperatorProduksi — performa per operator: total qty + rata-rata SPH tiap operator. */
class OperatorProduksiCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getOperatorProduksi';
    }

    public function description(): string
    {
        return 'Performa per operator: total qty produksi + rata-rata SPH tiap operator. Pakai untuk "operator produksi terbanyak", "SPH operator terbaik/terendah". Kosong=all-time.';
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
        return ['operator', 'sph operator', 'performa operator', 'karyawan', 'operator terbaik', 'operator terbanyak'];
    }

    public function examples(): array
    {
        return ['Operator produksi terbanyak siapa?', 'SPH operator terbaik siapa?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getOperatorProduksi(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
