<?php

namespace Tests\Feature\Master;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesRepairCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_crud_proses_repair(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->get(route('master.proses-repair.index'))->assertOk();

        $this->actingAs($admin)->post(route('master.proses-repair.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS',
            'sph_standar_repair' => 200,
        ])->assertRedirect(route('master.proses-repair.index'));

        $this->assertDatabaseHas('master_part_proses_repair', ['id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS']);

        $proses = MasterPartProsesRepair::first();

        $this->actingAs($admin)->put(route('master.proses-repair.update', $proses), [
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS UPDATE',
            'sph_standar_repair' => 250,
        ])->assertRedirect(route('master.proses-repair.index'));

        $this->assertDatabaseHas('master_part_proses_repair', ['id' => $proses->id, 'nama_proses' => 'REPAIR LAS UPDATE']);

        $this->actingAs($admin)->delete(route('master.proses-repair.destroy', $proses))
            ->assertRedirect(route('master.proses-repair.index'));

        $this->assertSoftDeleted('master_part_proses_repair', ['id' => $proses->id]);
    }

    public function test_staff_repair_tidak_bisa_akses_master_proses_repair(): void
    {
        $staff = User::factory()->create(['role' => 'staff_repair']);

        $this->actingAs($staff)->get(route('master.proses-repair.index'))->assertForbidden();
    }

    public function test_sph_standar_repair_kosong_gagal_validasi_bukan_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->post(route('master.proses-repair.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS',
            'sph_standar_repair' => '',
        ])->assertSessionHasErrors('sph_standar_repair');

        $this->assertDatabaseMissing('master_part_proses_repair', ['id_proses' => 'RP1']);
    }
}
