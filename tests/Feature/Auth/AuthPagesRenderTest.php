<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Semua halaman auth harus render lewat layouts.auth (Bootstrap CDN) — TANPA @vite.
 * Dulu register/forgot/reset/confirm/verify pakai x-guest-layout + @vite; karena
 * public/build gitignored & tak ada build pipeline, itu berisiko 500 di produksi.
 * Test ini mengunci: tak ada referensi asset Vite (/build/) & layout auth terpakai.
 */
class AuthPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    private function assertAuthLayoutNoVite(string $html): void
    {
        $this->assertStringContainsString('login-wrapper', $html, 'Halaman harus pakai layouts.auth.');
        $this->assertStringNotContainsString('/build/', $html, 'Tidak boleh ada asset Vite hasil build.');
        $this->assertStringNotContainsString('@vite', $html);
    }

    /**
     * OPTIMA adalah MES internal — akun dibuat admin, bukan daftar sendiri.
     * Registrasi publik dulu bikin user role default 'staff_produksi' lalu
     * auto-login, jadi siapa pun dari internet bisa menulis LHP/Dandori/VCB.
     */
    public function test_public_registration_is_not_reachable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [
            'name' => 'Orang Luar',
            'email' => 'orangluar@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['email' => 'orangluar@example.com']);
        $this->assertGuest();
    }

    public function test_forgot_password_page_renders_on_auth_layout(): void
    {
        $html = $this->get(route('password.request'))->assertOk()->getContent();
        $this->assertAuthLayoutNoVite($html);
    }

    public function test_reset_password_page_renders_on_auth_layout(): void
    {
        $html = $this->get(route('password.reset', ['token' => 'test-token']).'?email=a@b.com')
            ->assertOk()->getContent();
        $this->assertAuthLayoutNoVite($html);
        $this->assertStringContainsString('value="test-token"', $html);
    }

    public function test_confirm_password_page_renders_on_auth_layout(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('password.confirm'))->assertOk()->getContent();
        $this->assertAuthLayoutNoVite($html);
    }

    public function test_verify_email_view_renders_on_auth_layout(): void
    {
        // Controller-nya bercabang tergantung status verifikasi; render view langsung
        // supaya yang diuji murni migrasi layout view ini.
        $html = View::make('auth.verify-email')->render();
        $this->assertAuthLayoutNoVite($html);
        $this->assertStringContainsString(route('verification.send'), $html);
    }
}
