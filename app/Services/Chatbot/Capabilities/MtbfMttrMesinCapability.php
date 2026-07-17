<?php
namespace App\Services\Chatbot\Capabilities;

/** getMtbfMttrMesin — MTBF/MTTR satu mesin, satu bulan. */
class MtbfMttrMesinCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getMtbfMttrMesin';
    }

    public function description(): string
    {
        return 'MTBF/MTTR satu mesin, satu bulan.';
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
        return ['mtbf', 'mttr', 'mesin', 'availability', 'downtime', 'repair'];
    }

    public function examples(): array
    {
        return ['MTBF mesin M-01 bulan lalu berapa?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getMtbfMttrMesin(
            $args['idMesin'] ?? '',
            $args['bulan'] ?? '',
        );
    }
}
