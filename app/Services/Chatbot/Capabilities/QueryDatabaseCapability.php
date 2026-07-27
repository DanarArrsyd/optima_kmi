<?php

namespace App\Services\Chatbot\Capabilities;

/**
 * queryDatabase — guarded read-only SELECT (escape hatch lintas-tabel). Keyword
 * kosong: selalu tersedia karena dipakai untuk pertanyaan yang tak tercakup tool
 * lain. Eksekusi tetap lewat ChatbotTools → SqlGuard (boundary keamanan utuh).
 */
class QueryDatabaseCapability extends AbstractCapability
{
    public function name(): string
    {
        return 'queryDatabase';
    }

    public function description(): string
    {
        return 'SQL SELECT read-only untuk pertanyaan di luar tool lain (master data, per-operator/shift, jenis NG, lintas-tabel). Hanya SELECT tabel bisnis. Cek describeSchema dulu. Maks 25 baris.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'sql' => ['type' => 'string', 'description' => 'Satu SELECT MySQL valid. Mis: SELECT nama_karyawan,departemen FROM master_karyawan WHERE is_aktif=1'],
            ],
            'required' => ['sql'],
        ];
    }

    public function handle(array $args): array
    {
        return $this->tools->queryDatabase($args['sql'] ?? '');
    }
}
