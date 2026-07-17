<?php
namespace App\Services\Chatbot\Capabilities;

/**
 * getTotalProduksi — total qty & rata-rata SPH semua proses. Mendelegasi ke
 * ChatbotTools::getTotalProduksi yang sudah teruji.
 */
class ProductionTotalCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getTotalProduksi';
    }

    public function description(): string
    {
        return 'Total qty (Pcs) & rata-rata SPH semua proses dalam rentang bulan. Kosong=all-time.';
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
        return ['produksi', 'qty', 'pcs', 'sph', 'output'];
    }

    public function examples(): array
    {
        return [
            'Total produksi keseluruhan berapa Pcs?',
            'Rata-rata SPH produksi bulan ini berapa?',
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->getTotalProduksi(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
        );
    }
}
