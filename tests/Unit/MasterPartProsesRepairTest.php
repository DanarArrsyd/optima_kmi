<?php

namespace Tests\Unit;

use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPartProsesRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_bisa_dibuat_dan_terhubung_ke_part(): void
    {
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);

        $proses = MasterPartProsesRepair::create([
            'id_part' => $part->id,
            'id_proses' => 'RP1',
            'nama_proses' => 'REPAIR LAS',
            'sph_standar_repair' => 300,
        ]);

        $this->assertTrue($proses->part->is($part));
        $this->assertTrue($part->prosesRepair->first()->is($proses));
    }

    public function test_soft_delete(): void
    {
        $part = MasterPart::create(['part_no' => 'P-2', 'part_name' => 'PART DUA']);
        $proses = MasterPartProsesRepair::create([
            'id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100,
        ]);

        $proses->delete();

        $this->assertSoftDeleted('master_part_proses_repair', ['id' => $proses->id]);
    }
}
