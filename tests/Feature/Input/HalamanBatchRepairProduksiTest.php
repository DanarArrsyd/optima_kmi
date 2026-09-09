<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_create_menampilkan_form_batch(): void
    {
        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);

        $response = $this->actingAs(User::factory()->create(['role' => 'staff_produksi']))
            ->get(route('input.repair-produksi.create'));

        $response->assertOk();
        $response->assertSee('formBaris', false);
        $response->assertSee('konfigRepairProduksi', false);
        $response->assertSee(route('input.repair-produksi.batch'), false);
    }

    public function test_admin_juga_bisa_buka_halaman_create(): void
    {
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('input.repair-produksi.create'));

        $response->assertOk();
    }
}
