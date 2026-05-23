<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterLosstime;
use Illuminate\Foundation\Testing\RefreshDatabase;

class LosstimeTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_view_losstime_list(): void
    {
        MasterLosstime::create(['kode' => 'X1', 'jenis_losstime' => 'Test Loss', 'std_menit_default' => null, 'std_menit_progressive' => null]);
        $this->actingAs($this->admin)->get('/master/losstime')->assertStatus(200)->assertSee('Test Loss');
    }

    public function test_admin_can_create_losstime(): void
    {
        $this->actingAs($this->admin)->post('/master/losstime', [
            'kode' => 'X2', 'jenis_losstime' => 'New Loss', 'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ])->assertRedirect('/master/losstime');
        $this->assertDatabaseHas('master_losstime', ['kode' => 'X2']);
    }

    public function test_admin_can_update_losstime(): void
    {
        $lt = MasterLosstime::create(['kode' => 'X3', 'jenis_losstime' => 'Old Name', 'std_menit_default' => null, 'std_menit_progressive' => null]);
        $this->actingAs($this->admin)->put("/master/losstime/{$lt->id}", [
            'kode' => 'X3', 'jenis_losstime' => 'Updated Name', 'std_menit_default' => 5, 'std_menit_progressive' => 5,
        ])->assertRedirect('/master/losstime');
        $this->assertDatabaseHas('master_losstime', ['id' => $lt->id, 'jenis_losstime' => 'Updated Name']);
    }

    public function test_admin_can_soft_delete_losstime(): void
    {
        $lt = MasterLosstime::create(['kode' => 'X4', 'jenis_losstime' => 'Del Loss', 'std_menit_default' => null, 'std_menit_progressive' => null]);
        $this->actingAs($this->admin)->delete("/master/losstime/{$lt->id}")->assertRedirect('/master/losstime');
        $this->assertSoftDeleted('master_losstime', ['id' => $lt->id]);
    }
}
