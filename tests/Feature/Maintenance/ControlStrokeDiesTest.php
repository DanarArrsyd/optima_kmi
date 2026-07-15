<?php
namespace Tests\Feature\Maintenance;

use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlStrokeDiesTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create(['id_mesin' => 'CD-01'.uniqid(), 'nama_mesin' => 'CD Mesin', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part     = MasterPart::create(['part_no' => 'CD-P'.uniqid(), 'part_name' => 'CD Part', 'customer' => 'Toyota', 'tanggal_instalasi' => '2026-01-01']);
        $proses   = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);

        return compact('admin', 'shift', 'karyawan', 'mesin', 'part', 'proses');
    }

    private function produksi(array $f, string $tanggal, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $f['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty,
            'created_by' => $f['admin']->id,
        ]);
    }

    public function test_admin_can_view_control_stroke_dies_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('maintenance.control-stroke-dies'));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-dies');
    }

    public function test_admin_can_view_preventive_form_page(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 25000);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-dies.preventive.create', $f['proses']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-dies-preventive');
        $response->assertViewHas('currentStroke', 25000);
        $response->assertViewHas('threshold', 30000);
        $response->assertViewHas('karyawanList');
    }

    public function test_manager_cannot_view_preventive_form_page(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->get(route('maintenance.control-stroke-dies.preventive.create', $f['proses']->id));

        $response->assertForbidden();
    }

    public function test_staff_produksi_cannot_access_control_stroke_dies(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($staff)->get(route('maintenance.control-stroke-dies'));

        $response->assertForbidden();
    }

    public function test_data_endpoint_returns_current_cycle_rows_and_summary(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 31000); // over threshold -> overdue

        $response = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.data'));

        $response->assertOk();
        $response->assertJsonPath('summary.total_dies', 1);
        $response->assertJsonPath('summary.overdue', 1);
        $response->assertJsonPath('summary.warning', 0);
        $response->assertJsonFragment(['kode_mesin' => $f['part']->part_no, 'current_stroke' => 31000, 'status' => 'overdue', 'threshold' => 30000]);
    }

    public function test_history_endpoint_returns_logs_for_requested_dies_only(): void
    {
        $f = $this->fixtures();
        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30500,
            'catatan' => 'Ganti dies', 'created_by' => $f['admin']->id,
        ]);

        $otherPart   = MasterPart::create(['part_no' => 'OTHER'.uniqid(), 'part_name' => 'Other', 'customer' => 'Honda']);
        $otherProses = MasterPartProses::create(['id_part' => $otherPart->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        DiesPreventiveLog::create([
            'id_part_proses' => $otherProses->id, 'tanggal_preventive' => '2026-05-02',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 12000, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.history', $f['proses']->id));

        $response->assertOk();
        $response->assertJsonCount(1, 'logs');
        $response->assertJsonFragment(['tanggal_preventive' => '2026-05-01', 'pic' => 'Teknisi A', 'stroke_at_preventive' => 30500, 'catatan' => 'Ganti dies']);
    }

    public function test_admin_can_store_preventive_and_it_resets_the_running_cycle(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 30500);

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'catatan'            => 'Preventive rutin',
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-dies'));
        $response->assertSessionHas('success');

        $log = DiesPreventiveLog::first();
        $this->assertSame(30500, $log->stroke_at_preventive);
        $this->assertSame($f['admin']->id, $log->created_by);

        $dataResponse = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-dies.data'));
        $dataResponse->assertJsonFragment(['current_stroke' => 0, 'status' => 'ok']);
    }

    public function test_staff_maintenance_can_store_preventive(): void
    {
        $f     = $this->fixtures();
        $staff = User::factory()->create(['role' => 'staff_maintenance']);

        $response = $this->actingAs($staff)->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-dies'));
        $this->assertDatabaseCount('dies_preventive_log', 1);
    }

    public function test_manager_cannot_store_preventive(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('dies_preventive_log', 0);
    }

    public function test_store_preventive_rejects_future_date(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => now()->addDay()->toDateString(),
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertSessionHasErrors('tanggal_preventive');
        $this->assertDatabaseCount('dies_preventive_log', 0);
    }

    public function test_store_preventive_rejects_unknown_karyawan(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-dies.preventive', $f['proses']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => 999999,
        ]);

        $response->assertSessionHasErrors('id_karyawan');
        $this->assertDatabaseCount('dies_preventive_log', 0);
    }
}
