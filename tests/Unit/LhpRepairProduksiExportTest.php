<?php

namespace Tests\Unit;

use App\Exports\LhpRepairProduksiExport;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LhpRepairProduksiExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_collection_berisi_baris_repair_produksi_dengan_kolom_lengkap(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU', 'customer' => 'CUST A']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);

        TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $export = new LhpRepairProduksiExport('2026-09-01', '2026-09-01', null, null, null);
        $rows = $export->collection();

        $this->assertCount(1, $rows);
        $this->assertSame('CUST A', $rows->first()[3]);
        $this->assertCount(16, $export->headings());
    }
}
