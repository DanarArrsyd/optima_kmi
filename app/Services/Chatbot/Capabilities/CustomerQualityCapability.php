<?php

namespace App\Services\Chatbot\Capabilities;

/** getCustomerQuality — kualitas (NG rate) per customer dari data QC. */
class CustomerQualityCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'getCustomerQuality';
    }

    public function description(): string
    {
        return 'Kualitas per customer: total inspeksi, NG, & rate NG dari QC, per customer. Diurut NG terbanyak. Rentang bulan & filter customer opsional. Kosong=all-time.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'bulanMulai' => ['type' => 'string', 'description' => 'Opsional YYYY-MM, kosong=all-time'],
                'bulanAkhir' => ['type' => 'string', 'description' => 'Opsional YYYY-MM inklusif, kosong=all-time'],
                'customer' => ['type' => 'string', 'description' => 'Opsional nama customer (boleh sebagian), kosong=semua'],
            ],
            'required' => [],
        ];
    }

    public function keywords(): array
    {
        return ['customer', 'pelanggan', 'ng customer', 'reject customer', 'kualitas customer', 'cacat customer', 'quality customer'];
    }

    public function examples(): array
    {
        return [
            'Customer mana yang paling banyak NG?',
            'NG rate customer Toyota berapa?',
            'Ranking kualitas per customer bulan ini.',
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->getCustomerQuality(
            $args['bulanMulai'] ?? null,
            $args['bulanAkhir'] ?? null,
            $args['customer'] ?? null,
        );
    }
}
