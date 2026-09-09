<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use App\Services\RepairWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simpan_menulis_satu_baris_dengan_actual_cycle_time_terhitung(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $this->actingAs(User::factory()->create(['role' => 'staff_repair']));

        $service = app(RepairWriteService::class);

        $repair = $service->simpan([
            'tanggal_repair' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ]);

        $this->assertInstanceOf(TransaksiRepair::class, $repair);
        $this->assertSame(72.0, $repair->actual_cycle_time);
        $this->assertNotNull($repair->created_by);
    }
}
