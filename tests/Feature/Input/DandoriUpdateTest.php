<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DandoriUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_dandori_record(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'DU-01', 'nama_mesin' => 'Press DU', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DU-P1', 'part_name' => 'Update Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-DU', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);
        $dandori = TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 40, 'std_menit' => 30, 'over_menit' => 10, 'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->put(route('input.dandori.update', $dandori), [
            'tanggal' => '2026-07-06',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 50,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('input.dandori.index'));
        $this->assertSame('2026-07-06', $dandori->fresh()->tanggal->format('Y-m-d'));
        $this->assertEqualsWithDelta(50.0, $dandori->fresh()->actual_menit, 0.01);
    }
}
