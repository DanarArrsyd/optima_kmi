<?php

namespace Tests\Unit;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPartProsesRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bisa_dibuat_dan_terhubung_ke_part(): void
    {
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);

        $proses = MasterPartProsesRepairProduksi::create([
            'id_part' => $part->id,
            'id_proses' => 'RPP1',
            'nama_proses' => 'REPAIR LAS PRODUKSI',
            'sph_standar_repair_produksi' => 300,
        ]);

        $this->assertTrue($proses->part->is($part));
        $this->assertTrue($part->prosesRepairProduksi->first()->is($proses));
    }

    public function test_soft_delete(): void
    {
        $part = MasterPart::create(['part_no' => 'P-2', 'part_name' => 'PART DUA']);
        $proses = MasterPartProsesRepairProduksi::create([
            'id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100,
        ]);

        $proses->delete();

        $this->assertSoftDeleted('master_part_proses_repair_produksi', ['id' => $proses->id]);
    }
}
