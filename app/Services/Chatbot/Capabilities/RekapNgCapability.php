<?php
namespace App\Services\Chatbot\Capabilities;

/** getRekapNg — total & rate NG per nama proses, periode opsional. */
class RekapNgCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getRekapNg';
    }

    public function description(): string
    {
        return 'Total & rate NG per nama proses. periode opsional.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'namaProses' => ['type' => 'string', 'description' => 'Nama proses, mis. BENDING'],
                'periode'    => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=semua'],
            ],
            'required' => ['namaProses'],
        ];
    }

    public function keywords(): array
    {
        return ['ng', 'cacat', 'defect', 'reject', 'rekap ng', 'quality', 'qc', 'rate'];
    }

    public function examples(): array
    {
        return ['Berapa rate NG proses bending?'];
    }

    public function handle(array $args): array
    {
        return $this->tools->getRekapNg(
            $args['namaProses'] ?? '',
            $args['periode'] ?? null,
        );
    }
}
