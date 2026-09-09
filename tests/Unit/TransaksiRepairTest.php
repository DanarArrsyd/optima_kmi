<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransaksiRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_durasi_menit_terisi_otomatis_saat_disimpan(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);
        $user = User::factory()->create(['role' => 'staff_repair']);

        $repair = TransaksiRepair::create([
            'tanggal_repair' => '2026-09-01',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_part_proses_repair' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
            'actual_cycle_time' => 72.0,
            'created_by' => $user->id,
        ]);

        $this->assertSame(120, $repair->durasi_menit);
        $this->assertSame(2.0, $repair->waktu_kerja_jam);
        $this->assertSame(50, $repair->sph_aktual);
    }

    public function test_durasi_menit_lewat_tengah_malam(): void
    {
        $this->assertSame(120, TransaksiRepair::hitungDurasiMenit('23:00', '01:00'));
    }
}
