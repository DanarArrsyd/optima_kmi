<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_view_users_list(): void
    {
        User::factory()->create(['name' => 'Budi Santoso', 'role' => 'staff_produksi']);
        $this->actingAs($this->admin)->get('/master/users')->assertStatus(200)->assertSee('Budi Santoso');
    }

    public function test_admin_can_create_user(): void
    {
        $this->actingAs($this->admin)->post('/master/users', [
            'name'     => 'Siti QC',
            'email'    => 'siti@optima.local',
            'password' => 'password123',
            'role'     => 'staff_qc',
        ])->assertRedirect('/master/users');
        $this->assertDatabaseHas('users', ['email' => 'siti@optima.local', 'role' => 'staff_qc']);
    }

    public function test_admin_can_update_user_without_changing_password(): void
    {
        $user = User::factory()->create(['role' => 'staff_produksi', 'email' => 'test@optima.local']);
        $this->actingAs($this->admin)->put("/master/users/{$user->id}", [
            'name'     => 'Updated Name',
            'email'    => 'test@optima.local',
            'password' => '',    // empty — should not change password
            'role'     => 'manager',
        ])->assertRedirect('/master/users');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'manager', 'name' => 'Updated Name']);
    }

    public function test_admin_cannot_delete_own_account(): void
    {
        $this->actingAs($this->admin)->delete("/master/users/{$this->admin->id}")->assertRedirect('/master/users');
        $this->assertDatabaseHas('users', ['id' => $this->admin->id]); // still exists
    }

    public function test_admin_can_delete_other_user(): void
    {
        $other = User::factory()->create(['role' => 'staff_qc']);
        $this->actingAs($this->admin)->delete("/master/users/{$other->id}")->assertRedirect('/master/users');
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_non_admin_cannot_access_users(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staff)->get('/master/users')->assertStatus(403);
    }
}
