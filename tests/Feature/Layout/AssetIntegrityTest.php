<?php
namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kunci: CDN pihak-ketiga di layout wajib bawa Subresource Integrity + crossorigin.
 * Kalau ada yang menambah/mengganti tag CDN tanpa SRI, test ini break berisik.
 */
class AssetIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_layout_ships_cdn_with_sri(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        // Bootstrap CSS + JS di layouts.auth harus ber-SRI.
        $this->assertStringContainsString(
            'integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH"',
            $html
        );
        $this->assertStringContainsString('crossorigin="anonymous"', $html);
        // Tiap tag CDN jsdelivr wajib punya integrity.
        $this->assertCdnTagsHaveIntegrity($html);
    }

    public function test_app_layout_ships_cdn_with_sri(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('master.mesin.index'))->assertOk()->getContent();

        // Chart.js + Alpine + Bootstrap di layouts.app harus ber-SRI.
        $this->assertStringContainsString(
            'integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt"',
            $html
        );
        $this->assertCdnTagsHaveIntegrity($html);
    }

    private function assertCdnTagsHaveIntegrity(string $html): void
    {
        preg_match_all('/<(?:script|link)\b[^>]*cdn\.jsdelivr\.net[^>]*>/i', $html, $tags);
        $this->assertNotEmpty($tags[0], 'Layout harus memuat minimal satu asset CDN.');
        foreach ($tags[0] as $tag) {
            $this->assertMatchesRegularExpression('/integrity="sha384-/', $tag,
                "Tag CDN tanpa SRI: {$tag}");
            $this->assertStringContainsString('crossorigin=', $tag,
                "Tag CDN tanpa crossorigin: {$tag}");
        }
    }
}
