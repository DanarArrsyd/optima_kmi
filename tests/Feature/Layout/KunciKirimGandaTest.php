<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Kunci kirim-ganda di layout. Perilakunya JS (diverifikasi di browser);
 * di sini dikunci bahwa BLOK-nya utuh di setiap halaman berform POST —
 * bukan sekadar kata kuncinya (lihat memori "asersi yang tak bisa gagal").
 */
class KunciKirimGandaTest extends TestCase
{
    use RefreshDatabase;

    public static function halaman(): array
    {
        return [
            'batch LHP Produksi' => ['input.produksi.create'],
            'batch LHR Repair' => ['input.repair.create'],
            'master mesin create' => ['master.mesin.create'],
            'daftar master shift (impor)' => ['master.shift.index'],
        ];
    }

    #[DataProvider('halaman')]
    public function test_blok_kunci_kirim_ganda_utuh(string $route): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route($route))->assertOk()->getContent();

        // Lapis 1: CAPTURE memblok form yang sudah terkirim, sebelum handler form jalan.
        $this->assertMatchesRegularExpression(
            '/if \(kirimPost\(e\.target\) && e\.target\.dataset\.terkirim\) \{\s*e\.preventDefault\(\);\s*e\.stopImmediatePropagation\(\);\s*\}\s*\}, true\);/',
            $html
        );
        // Lapis 2: BUBBLE menandai hanya kalau tak ada yang membatalkan.
        $this->assertMatchesRegularExpression(
            "/if \\(e\\.defaultPrevented \\|\\| ! kirimPost\\(f\\)\\) return;\\s*f\\.dataset\\.terkirim = '1';/",
            $html
        );
        // Tombol dikunci SETELAH event (nilai tombol submit tetap terkirim) & dilepas lagi.
        $this->assertStringContainsString('setTimeout(() => tombolForm(f).forEach(b => {', $html);
        $this->assertStringContainsString("window.addEventListener('pageshow', () => document.querySelectorAll('form[data-terkirim]').forEach(lepas));", $html);
    }
}
