<?php

namespace App\Services\Chatbot\Capabilities;

/**
 * describeSchema — introspeksi struktur DB untuk LLM. Keyword sengaja kosong:
 * ini alat pendukung queryDatabase, harus selalu tersedia lepas dari topik.
 */
class DescribeSchemaCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'describeSchema';
    }

    public function description(): string
    {
        return 'Lihat struktur DB. Tanpa arg: daftar tabel. Dengan table: kolom+tipe. Panggil sebelum queryDatabase.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'table' => ['type' => 'string', 'description' => 'Opsional nama tabel, kosong=daftar semua'],
            ],
            'required' => [],
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->describeSchema($args['table'] ?? null);
    }
}
