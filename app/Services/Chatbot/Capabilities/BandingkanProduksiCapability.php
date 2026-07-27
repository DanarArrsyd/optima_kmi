<?php

namespace App\Services\Chatbot\Capabilities;

/** bandingkanProduksi — banding qty & rata-rata SPH produksi antar 2 bulan. */
class BandingkanProduksiCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'bandingkanProduksi';
    }

    public function description(): string
    {
        return 'Banding qty & rata-rata SPH produksi antar 2 bulan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'periode1' => ['type' => 'string', 'description' => 'YYYY-MM'],
                'periode2' => ['type' => 'string', 'description' => 'YYYY-MM'],
            ],
            'required' => ['periode1', 'periode2'],
        ];
    }

    public function keywords(): array
    {
        return ['banding', 'bandingkan', 'compare', 'perbandingan', 'vs', 'dibanding'];
    }

    public function examples(): array
    {
        return ['Bandingkan produksi bulan ini dengan bulan lalu.'];
    }

    public function handle(array $args): array
    {
        return $this->tools->bandingkanProduksi(
            $args['periode1'] ?? '',
            $args['periode2'] ?? '',
        );
    }
}
