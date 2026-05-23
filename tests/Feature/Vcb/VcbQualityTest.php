<?php
namespace Tests\Feature\Vcb;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VcbQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_vcb_quality(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/vcb/quality')->assertStatus(200);
    }

    public function test_data_endpoint_returns_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
             ->get('/vcb/quality/data?date_from=2026-05-01&date_to=2026-05-31')
             ->assertStatus(200)
             ->assertJsonStructure(['labels', 'datasets', 'table']);
    }

    public function test_staff_produksi_cannot_view_vcb_quality(): void
    {
        $staffProd = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staffProd)->get('/vcb/quality')->assertStatus(403);
    }

    public function test_manager_can_view_vcb_quality(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/vcb/quality')->assertStatus(200);
    }
}
