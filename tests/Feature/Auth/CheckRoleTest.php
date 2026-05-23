<?php
namespace Tests\Feature\Auth;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CheckRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_master_data(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/master/karyawan')->assertStatus(200);
    }

    public function test_staff_produksi_cannot_access_master_data(): void
    {
        $user = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($user)->get('/master/karyawan')->assertStatus(403);
    }

    public function test_manager_cannot_access_input_produksi(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $this->actingAs($user)->get('/input/produksi')->assertStatus(403);
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/master/karyawan')->assertRedirect('/login');
    }
}
