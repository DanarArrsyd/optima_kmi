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
}
