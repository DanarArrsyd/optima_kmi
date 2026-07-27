<?php

namespace Tests\Feature\Master;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role 'guest' harus valid di kolom users.role, tervalidasi di form request,
 * dan terdeteksi lewat helper model.
 */
class GuestRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_flags_guest_role(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($guest->isGuest());
        $this->assertFalse($admin->isGuest());
    }

    public function test_admin_can_create_guest_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('master.users.store'), [
            'name' => 'Tamu Eksternal',
            'email' => 'tamu@example.com',
            'password' => 'rahasia-tamu-123',
            'role' => 'guest',
        ])->assertRedirect(route('master.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'tamu@example.com',
            'role' => 'guest',
        ]);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('master.users.store'), [
            'name' => 'Peran Ngawur',
            'email' => 'ngawur@example.com',
            'password' => 'rahasia-tamu-123',
            'role' => 'superuser',
        ])->assertSessionHasErrors('role');
    }

    public function test_role_dropdown_offers_guest_option(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('master.users.create'))
            ->assertOk()
            ->assertSee('value="guest"', false);
    }
}
