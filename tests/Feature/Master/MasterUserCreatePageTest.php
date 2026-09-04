<?php

namespace Tests\Feature\Master;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi: create.blade.php masih pakai <select name="role"> tunggal — sisa
 * UI SEBELUM migrasi multi-role (PR #135, users.role -> roles JSON array).
 * StoreMasterUserRequest sudah lama mewajibkan `roles` array (min:1); field
 * `role` lama itu tidak dikenali validator sama sekali, jadi SETIAP submit
 * lewat halaman ini pasti gagal validasi — halaman penuh ini nol pernah
 * bisa dipakai bikin user sejak migrasi itu. Ketahuan pas drawer (yang
 * sudah benar pakai partial roles-fields) dicabut dan halaman ini jadi
 * satu-satunya jalur.
 */
class MasterUserCreatePageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_halaman_tambah_user_render_checkbox_roles_bukan_select_tunggal(): void
    {
        $html = $this->actingAs($this->admin)->get(route('master.users.create'))
            ->assertOk()->getContent();

        $this->assertStringContainsString('name="roles[]"', $html);
        $this->assertStringNotContainsString('name="role"', $html);
    }

    public function test_submit_dengan_roles_array_berhasil_bikin_user(): void
    {
        $response = $this->actingAs($this->admin)->post(route('master.users.store'), [
            'name' => 'Staff Baru',
            'email' => 'staffbaru@test.local',
            'password' => 'rahasia123',
            'roles' => ['staff_produksi'],
        ]);

        $response->assertRedirect(route('master.users.index'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('users', ['email' => 'staffbaru@test.local']);

        $user = User::where('email', 'staffbaru@test.local')->firstOrFail();
        $this->assertTrue($user->hasRole('staff_produksi'));
    }
}
