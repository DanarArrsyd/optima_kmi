<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role-based access (CheckRole middleware) + redirect dashboard per role.
 * Boundary otorisasi tak boleh diam-diam melebar.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
        $this->get(route('master.mesin.index'))->assertRedirect(route('login'));
    }

    public function test_master_data_is_admin_only(): void
    {
        $this->actingAs($this->user('admin'))->get(route('master.mesin.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('master.mesin.index'))->assertForbidden();
        $this->actingAs($this->user('staff_qc'))->get(route('master.mesin.index'))->assertForbidden();
        $this->actingAs($this->user('manager'))->get(route('master.mesin.index'))->assertForbidden();
    }

    public function test_input_produksi_limited_to_admin_and_staff_produksi(): void
    {
        $this->actingAs($this->user('staff_produksi'))->get(route('input.produksi.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('input.produksi.index'))->assertOk();
        $this->actingAs($this->user('staff_qc'))->get(route('input.produksi.index'))->assertForbidden();
    }

    public function test_input_qc_limited_to_admin_and_staff_qc(): void
    {
        $this->actingAs($this->user('staff_qc'))->get(route('input.qc.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('input.qc.index'))->assertForbidden();
    }

    public function test_chatbot_limited_to_admin_and_manager(): void
    {
        $this->actingAs($this->user('manager'))->get(route('chatbot.index'))->assertOk();
        $this->actingAs($this->user('admin'))->get(route('chatbot.index'))->assertOk();
        $this->actingAs($this->user('staff_produksi'))->get(route('chatbot.index'))->assertForbidden();
        $this->actingAs($this->user('staff_qc'))->get(route('chatbot.index'))->assertForbidden();
    }

    public function test_maintenance_blocks_non_maintenance_roles(): void
    {
        $this->actingAs($this->user('staff_maintenance'))->get(route('maintenance.mttr'))->assertOk();
        $this->actingAs($this->user('staff_qc'))->get(route('maintenance.mttr'))->assertForbidden();
    }

    public function test_dashboard_redirects_by_role(): void
    {
        $this->actingAs($this->user('staff_maintenance'))->get('/dashboard')->assertRedirect('/maintenance/mttr');
        $this->actingAs($this->user('staff_qc'))->get('/dashboard')->assertRedirect('/vcb/quality');
        $this->actingAs($this->user('staff_produksi'))->get('/dashboard')->assertRedirect('/vcb/produksi');
        $this->actingAs($this->user('manager'))->get('/dashboard')->assertRedirect('/vcb/produksi');
    }
}
