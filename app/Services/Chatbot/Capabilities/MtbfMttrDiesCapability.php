<?php
namespace App\Services\Chatbot\Capabilities;

/** getMtbfMttrDies — MTBF/MTTR part/dies per proses, satu bulan. */
class MtbfMttrDiesCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getMtbfMttrDies';
    }

    public function description(): string
    {
        return 'MTBF/MTTR part/dies per proses, satu bulan.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'partNo' => ['type' => 'string', 'description' => 'No part/dies, mis. BT-091A-3'],
                'bulan'  => ['type' => 'string', 'description' => 'YYYY-MM'],
            ],
            'required' => ['partNo', 'bulan'],
        ];
    }

    public function keywords(): array
    {
        return ['mtbf', 'mttr', 'dies', 'availability', 'downtime', 'repair'];
    }

    public function examples(): array
    {
        return ['Berapa MTBF dies BT-091A-3 bulan ini?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getMtbfMttrDies(
            $args['partNo'] ?? '',
            $args['bulan'] ?? '',
        );
    }
}
