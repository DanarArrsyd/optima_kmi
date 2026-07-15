<?php
namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlStrokeMesinTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_control_stroke_mesin_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('maintenance.control-stroke-mesin'));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin');
    }

    public function test_staff_produksi_cannot_access_control_stroke_mesin(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->get(route('maintenance.control-stroke-mesin'));

        $response->assertForbidden();
    }

    public function test_data_endpoint_returns_total_stroke_summary(): void
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create(['id_mesin' => 'CS-01', 'nama_mesin' => 'CS Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part     = MasterPart::create(['part_no' => 'CS-P1', 'part_name' => 'CS Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => 250,
            'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->getJson(route('maintenance.control-stroke-mesin.data', [
            'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
        ]));

        $response->assertOk();
        $response->assertJsonPath('summary.total_stroke', 250);
        $response->assertJsonFragment(['kode_mesin' => 'CS-01', 'total_stroke' => 250]);
    }
}
