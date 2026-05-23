<?php
namespace Tests\Feature\Vcb;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class VcbProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_vcb_produksi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/vcb/produksi')->assertStatus(200);
    }

    public function test_data_endpoint_returns_json(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
             ->get('/vcb/produksi/data?date_from=2026-05-01&date_to=2026-05-31')
             ->assertStatus(200)
             ->assertJsonStructure(['labels', 'datasets', 'table']);
    }

    public function test_staff_qc_cannot_view_vcb_produksi(): void
    {
        $staffQc = User::factory()->create(['role' => 'staff_qc']);
        $this->actingAs($staffQc)->get('/vcb/produksi')->assertStatus(403);
    }

    public function test_manager_can_view_vcb_produksi(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);
        $this->actingAs($manager)->get('/vcb/produksi')->assertStatus(200);
    }
}
