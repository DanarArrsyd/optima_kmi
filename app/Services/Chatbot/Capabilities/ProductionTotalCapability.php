<?php
namespace App\Services\Chatbot\Capabilities;

use App\Contracts\ChatCapability;
use App\Services\ChatbotTools;

/**
 * CONTOH REFERENSI pola self-registering (Phase-1).
 *
 * Membungkus tool "getTotalProduksi" yang sudah ada & teruji di ChatbotTools —
 * SENGAJA mendelegasi, bukan menyalin ulang logika domain. Yang berpindah cuma
 * METADATA (schema/keyword/role/contoh), yang tadinya tersebar di ToolSchemaCatalog
 * + EXPOSED_TOOLS + hint prompt, kini hidup di SATU tempat bersama handler-nya.
 *
 * Untuk memigrasi tool lain: buat class serupa, delegasikan ke method ChatbotTools
 * yang relevan, lalu tag di AppServiceProvider. Tak ada file inti yang perlu diedit.
 */
class ProductionTotalCapability implements ChatCapability
{
    public function __construct(private ChatbotTools $tools = new ChatbotTools()) {}

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
        return ['produksi', 'total produksi', 'qty', 'pcs', 'sph', 'output'];
    }

    public function roles(): array
    {
        // Kosong = semua role ber-akses chatbot. Contoh pembatasan future:
        // return ['admin', 'manager', 'staff_produksi'];
        return [];
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
