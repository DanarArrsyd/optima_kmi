<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanDaftarRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_daftar_menampilkan_baris_repair(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);

        TransaksiRepair::create([
            'tanggal_repair' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair.index'));

        $response->assertOk();
        $response->assertSee('PART SATU');
        $response->assertSee('REPAIRMAN SATU');
        $response->assertSee(route('input.repair.create'), false);
    }

    public function test_staff_repair_tidak_lihat_tombol_edit_hapus(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);
        TransaksiRepair::create([
            'tanggal_repair' => now()->format('Y-m-d'), 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id, 'jam_start' => '07:00', 'jam_finish' => '09:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 72, 'created_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('input.repair.index'));

        $response->assertDontSee(route('input.repair.edit', TransaksiRepair::first()), false);
    }
}
