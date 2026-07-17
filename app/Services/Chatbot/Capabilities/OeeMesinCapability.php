<?php
namespace App\Services\Chatbot\Capabilities;

/** getOeeMesin — OEE (Availability × Performance × Quality) satu mesin, satu bulan. */
class OeeMesinCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getOeeMesin';
    }

    public function description(): string
    {
        return 'OEE mesin (Availability × Performance × Quality) satu bulan. Balikin tiap komponen + OEE %. Komponen yang belum bisa dihitung diberi catatan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'idMesin' => ['type' => 'string', 'description' => 'Kode mesin, mis. M-01'],
                'bulan'   => ['type' => 'string', 'description' => 'YYYY-MM'],
            ],
            'required' => ['idMesin', 'bulan'],
        ];
    }

    public function keywords(): array
    {
        return ['oee', 'overall equipment', 'efektivitas', 'availability', 'performance', 'quality rate'];
    }

    public function examples(): array
    {
        return ['Berapa OEE mesin M-01 bulan ini?', 'Breakdown OEE mesin M-02 Juli 2026.'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getOeeMesin(
            $args['idMesin'] ?? '',
            $args['bulan'] ?? '',
        );
    }
}
