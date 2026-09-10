<?php

namespace Tests\Feature\Master;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProsesRepairProduksiCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_crud_proses_repair_produksi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->get(route('master.proses-repair-produksi.index'))->assertOk();

        $this->actingAs($admin)->post(route('master.proses-repair-produksi.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS PRODUKSI',
            'sph_standar_repair_produksi' => 200,
        ])->assertRedirect(route('master.proses-repair-produksi.index'));

        $this->assertDatabaseHas('master_part_proses_repair_produksi', ['id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS PRODUKSI']);

        $proses = MasterPartProsesRepairProduksi::first();

        $this->actingAs($admin)->put(route('master.proses-repair-produksi.update', $proses), [
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS UPDATE',
            'sph_standar_repair_produksi' => 250,
        ])->assertRedirect(route('master.proses-repair-produksi.index'));

        $this->assertDatabaseHas('master_part_proses_repair_produksi', ['id' => $proses->id, 'nama_proses' => 'REPAIR LAS UPDATE']);

        $this->actingAs($admin)->delete(route('master.proses-repair-produksi.destroy', $proses))
            ->assertRedirect(route('master.proses-repair-produksi.index'));

        $this->assertSoftDeleted('master_part_proses_repair_produksi', ['id' => $proses->id]);
    }

    public function test_sph_standar_repair_produksi_kosong_gagal_validasi_bukan_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);

        $this->actingAs($admin)->post(route('master.proses-repair-produksi.store'), [
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS PRODUKSI',
            'sph_standar_repair_produksi' => '',
        ])->assertSessionHasErrors('sph_standar_repair_produksi');

        $this->assertDatabaseMissing('master_part_proses_repair_produksi', ['id_proses' => 'RPP1']);
    }

    public function test_staff_produksi_tidak_bisa_akses_master_proses_repair_produksi(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('master.proses-repair-produksi.index'))->assertForbidden();
    }
}
