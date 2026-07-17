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
        return 'OEE mesin (Availability × Performance × Quality) satu bulan. Balikin tiap komponen + OEE % + field "metodologi" (rumus & angka yang dipakai) supaya bisa dijelaskan. Komponen yang belum bisa dihitung diberi catatan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'idMesin' => ['type' => 'string', 'description' => 'Kode mesin (mis. A4, B2, C10) ATAU nama mesin (mis. AMADA 80T). Kirim apa adanya yang user sebut.'],
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
        return ['Berapa OEE mesin A4 bulan ini?', 'Breakdown OEE mesin AMADA 80T Juli 2026.', 'Rumus performance OEE mesin A4 gimana?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getOeeMesin(
            $args['idMesin'] ?? '',
            $args['bulan'] ?? '',
        );
    }
}
