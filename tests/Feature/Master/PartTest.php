<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PartTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_view_part_list(): void
    {
        MasterPart::create(['part_no' => 'BT-001', 'part_name' => 'Bracket A']);
        $this->actingAs($this->admin)->get('/master/part')->assertStatus(200)->assertSee('Bracket A');
    }

    public function test_admin_can_create_part(): void
    {
        $this->actingAs($this->admin)->post('/master/part', [
            'part_no' => 'BT-002', 'part_name' => 'Bracket B',
        ])->assertRedirect('/master/part');
        $this->assertDatabaseHas('master_part', ['part_no' => 'BT-002']);
    }

    public function test_admin_can_update_part(): void
    {
        $p = MasterPart::create(['part_no' => 'BT-003', 'part_name' => 'Old Name']);
        $this->actingAs($this->admin)->put("/master/part/{$p->id}", [
            'part_no' => 'BT-003', 'part_name' => 'New Name',
        ])->assertRedirect("/master/part/{$p->id}/edit");
        $this->assertDatabaseHas('master_part', ['id' => $p->id, 'part_name' => 'New Name']);
    }

    public function test_admin_can_soft_delete_part(): void
    {
        $p = MasterPart::create(['part_no' => 'BT-004', 'part_name' => 'Del Part']);
        $this->actingAs($this->admin)->delete("/master/part/{$p->id}")->assertRedirect('/master/part');
        $this->assertSoftDeleted('master_part', ['id' => $p->id]);
    }

    public function test_admin_can_add_proses_to_part(): void
    {
        $p = MasterPart::create(['part_no' => 'BT-005', 'part_name' => 'Part X']);
        $this->actingAs($this->admin)->post("/master/part/{$p->id}/proses", [
            'id_proses'                   => '1',
            'nama_proses'                 => 'PROGRESSIVE',
            'sph_standar_produksi'        => 1200,
            'cycle_time_standar_produksi' => 3.0,
            'sph_standar_qc'              => 800,
            'cycle_time_standar_qc'       => 4.5,
            'std_dandori_menit'           => 15,
        ])->assertRedirect("/master/part/{$p->id}/edit");
        $this->assertDatabaseHas('master_part_proses', ['id_part' => $p->id, 'id_proses' => '1']);
    }

    public function test_admin_can_delete_proses(): void
    {
        $p = MasterPart::create(['part_no' => 'BT-006', 'part_name' => 'Part Y']);
        $pr = $p->proses()->create([
            'id_proses' => '1', 'nama_proses' => 'PROG',
            'sph_standar_produksi' => 1000, 'cycle_time_standar_produksi' => 3.6,
            'sph_standar_qc' => 800, 'cycle_time_standar_qc' => 4.5,
            'std_dandori_menit' => 15,
        ]);
        $this->actingAs($this->admin)->delete("/master/part/{$p->id}/proses/{$pr->id}")->assertRedirect("/master/part/{$p->id}/edit");
        $this->assertSoftDeleted('master_part_proses', ['id' => $pr->id]);
    }

    public function test_non_admin_cannot_access_part(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staff)->get('/master/part')->assertStatus(403);
    }
}
