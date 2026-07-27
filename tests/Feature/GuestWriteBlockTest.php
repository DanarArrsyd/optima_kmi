<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lapis kedua proteksi guest: middleware DenyGuestWrite.
 * Route yang diuji sengaja TIDAK punya middleware role, jadi 403 di sini
 * membuktikan middleware bekerja berdiri sendiri.
 */
class GuestWriteBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_post_to_route_without_role_middleware(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post('/notifications/read-all')->assertForbidden();
    }

    public function test_guest_cannot_change_own_password(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'password-baru-123',
            'password_confirmation' => 'password-baru-123',
        ])->assertForbidden();
    }

    public function test_guest_can_still_logout(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_non_guest_roles_are_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/notifications/read-all')->assertOk();
    }
}
