<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use App\Services\RepairProduksiWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairProduksiWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_simpan_menulis_satu_baris_dengan_actual_cycle_time_terhitung(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));

        $service = app(RepairProduksiWriteService::class);

        $repair = $service->simpan([
            'tanggal_repair_produksi' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ]);

        $this->assertInstanceOf(TransaksiRepairProduksi::class, $repair);
        $this->assertSame(72.0, $repair->actual_cycle_time);
        $this->assertNotNull($repair->created_by);
    }

    public function test_hitung_cycle_time_public_dan_bisa_dipanggil_ulang(): void
    {
        $service = app(RepairProduksiWriteService::class);

        $this->assertSame(24.0, $service->hitungCycleTime('08:00:00', '10:00:00', 300));
        $this->assertSame(0.0, $service->hitungCycleTime('08:00:00', '10:00:00', 0));
    }
}
