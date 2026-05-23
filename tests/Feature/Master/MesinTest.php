<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterMesin;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MesinTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function mesinData(array $overrides = []): array
    {
        return array_merge(['id_mesin' => 'M-001', 'nama_mesin' => 'Mesin A', 'line' => 'L1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 1200], $overrides);
    }

    public function test_admin_can_view_mesin_list(): void
    {
        MasterMesin::create($this->mesinData());
        $this->actingAs($this->admin)->get('/master/mesin')->assertStatus(200)->assertSee('Mesin A');
    }

    public function test_admin_can_create_mesin(): void
    {
        $this->actingAs($this->admin)->post('/master/mesin', $this->mesinData())->assertRedirect('/master/mesin');
        $this->assertDatabaseHas('master_mesin', ['id_mesin' => 'M-001']);
    }

    public function test_admin_can_update_mesin(): void
    {
        $m = MasterMesin::create($this->mesinData());
        $this->actingAs($this->admin)->put("/master/mesin/{$m->id}", $this->mesinData(['nama_mesin' => 'Mesin A Updated']))->assertRedirect('/master/mesin');
        $this->assertDatabaseHas('master_mesin', ['id' => $m->id, 'nama_mesin' => 'Mesin A Updated']);
    }

    public function test_admin_can_soft_delete_mesin(): void
    {
        $m = MasterMesin::create($this->mesinData());
        $this->actingAs($this->admin)->delete("/master/mesin/{$m->id}")->assertRedirect('/master/mesin');
        $this->assertSoftDeleted('master_mesin', ['id' => $m->id]);
    }

    public function test_non_admin_cannot_access_mesin(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staff)->get('/master/mesin')->assertStatus(403);
    }
}
