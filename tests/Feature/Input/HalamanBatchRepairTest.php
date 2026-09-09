<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_create_menampilkan_form_batch(): void
    {
        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);

        $response = $this->actingAs(User::factory()->create(['role' => 'staff_repair']))
            ->get(route('input.repair.create'));

        $response->assertOk();
        $response->assertSee('formBaris', false);
        $response->assertSee('konfigRepair', false);
        $response->assertSee(route('input.repair.batch'), false);
    }
}
