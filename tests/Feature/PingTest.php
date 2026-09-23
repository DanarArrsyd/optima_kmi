<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Endpoint /ping — thin, nol query DB, dipakai widget latency topbar buat
 * ukur round-trip. Default-deny sama kayak seluruh route app ini.
 */
class PingTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_tanpa_login_dialihkan_ke_login(): void
    {
        $this->get(route('ping'))->assertRedirect(route('login'));
    }

    public function test_ping_dengan_login_mengembalikan_204(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('ping'))->assertNoContent();
    }

    public function test_ping_bisa_diakses_role_guest(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->get(route('ping'))->assertNoContent();
    }

    public function test_widget_ping_muncul_di_layout(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString('x-data="pingIndicator()"', $html);
        $this->assertStringContainsString('pingOnce()', $html);
    }

    public function test_ambang_warna_ping_sesuai_spec(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString(
            "if (this.ms < 150) return 'is-good';", $html,
            'Ambang hijau berubah tanpa update spec.'
        );
        $this->assertStringContainsString(
            "if (this.ms <= 400) return 'is-fair';", $html,
            'Batas kuning harus INKLUSIF 400 (<=, bukan <) — pernah salah sekali.'
        );
    }
}
