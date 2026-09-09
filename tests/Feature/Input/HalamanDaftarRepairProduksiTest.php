<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanDaftarRepairProduksiTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_daftar_menampilkan_baris_repair_produksi(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);

        TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair-produksi.index'));

        $response->assertOk();
        $response->assertSee('PART SATU');
        $response->assertSee('OPERATOR SATU');
        $response->assertSee(route('input.repair-produksi.create'), false);
    }

    public function test_staff_produksi_tetap_lihat_tombol_export_tapi_bukan_edit_hapus(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);
        $user = User::factory()->create(['role' => 'staff_produksi']);
        $repair = TransaksiRepairProduksi::create([
            'tanggal_repair_produksi' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair_produksi' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair-produksi.index'));

        $response->assertSee('exportRepairProduksiPanel', false);
        $response->assertSee('Export Excel');
        $response->assertDontSee(route('input.repair-produksi.edit', $repair), false);
    }
}
