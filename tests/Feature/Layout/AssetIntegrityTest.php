<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dulu (sebelum 2026-08-30) menjaga SRI di tag CDN — sekarang Bootstrap/
 * Chart.js/Alpine di-self-host lewat public/vendor/, jadi yang dijaga
 * dibalik: asset lokal harus kepakai dan CDN eksternal jangan nyelinap balik
 * tanpa sengaja.
 */
class AssetIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_login_layout_pakai_vendor_lokal(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('vendor/bootstrap/css/bootstrap.min.css', $html);
        $this->assertStringContainsString('vendor/bootstrap/js/bootstrap.bundle.min.js', $html);
        $this->assertStringContainsString('vendor/bootstrap-icons/bootstrap-icons.css', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }

    public function test_app_layout_pakai_vendor_lokal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('master.mesin.index'))->assertOk()->getContent();

        $this->assertStringContainsString('vendor/chartjs/chart.umd.js', $html);
        $this->assertStringContainsString('vendor/chartjs-plugin-datalabels/chartjs-plugin-datalabels.min.js', $html);
        $this->assertStringContainsString('vendor/alpinejs/cdn.min.js', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);
    }
}
