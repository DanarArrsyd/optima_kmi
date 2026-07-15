<?php
namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\MesinPreventiveLog;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlStrokeMesinTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $admin    = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create([
            'id_mesin' => 'CS-01', 'nama_mesin' => 'CS Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01',
        ]);
        $part     = MasterPart::create(['part_no' => 'CS-P1', 'part_name' => 'CS Part', 'customer' => 'Toyota']);
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

    public function test_data_endpoint_returns_current_cycle_rows_and_summary(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 310000); // di atas threshold -> overdue

        $response = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-mesin.data'));

        $response->assertOk();
        $response->assertJsonPath('summary.total_mesin', 1);
        $response->assertJsonPath('summary.overdue', 1);
        $response->assertJsonPath('summary.warning', 0);
        $response->assertJsonFragment(['kode_mesin' => 'CS-01', 'current_stroke' => 310000, 'status' => 'overdue', 'threshold' => 300000]);
    }

    public function test_admin_can_view_preventive_form_page(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 250000);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-mesin.preventive.create', $f['mesin']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin-preventive');
        $response->assertViewHas('currentStroke', 250000);
        $response->assertViewHas('threshold', 300000);
        $response->assertViewHas('karyawanList');
    }

    public function test_manager_cannot_view_preventive_form_page(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->get(route('maintenance.control-stroke-mesin.preventive.create', $f['mesin']->id));

        $response->assertForbidden();
    }

    public function test_history_page_shows_logs_for_requested_mesin_only(): void
    {
        $f = $this->fixtures();
        MesinPreventiveLog::create([
            'id_mesin' => $f['mesin']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 300500,
            'catatan' => 'Ganti bearing', 'created_by' => $f['admin']->id,
        ]);

        $otherMesin = MasterMesin::create([
            'id_mesin' => 'OTHER-01', 'nama_mesin' => 'Other', 'line' => '2',
            'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100,
        ]);
        MesinPreventiveLog::create([
            'id_mesin' => $otherMesin->id, 'tanggal_preventive' => '2026-05-02',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 12000, 'created_by' => $f['admin']->id,
        ]);

        $response = $this->actingAs($f['admin'])->get(route('maintenance.control-stroke-mesin.history', $f['mesin']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin-history');
        $response->assertViewHas('logs', fn ($logs) => $logs->count() === 1 && $logs->first()->stroke_at_preventive === 300500);
        $response->assertSee('Ganti bearing');
        $response->assertSee('Teknisi A');
        $response->assertDontSee('12000'); // log mesin lain tidak ikut terbawa
    }

    public function test_manager_can_view_history_page(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->get(route('maintenance.control-stroke-mesin.history', $f['mesin']->id));

        $response->assertOk();
        $response->assertViewIs('maintenance.control-stroke-mesin-history');
    }

    public function test_admin_can_store_preventive_and_it_resets_the_running_cycle(): void
    {
        $f = $this->fixtures();
        $this->produksi($f, '2026-06-01', 300500);

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
            'catatan'            => 'Preventive rutin',
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-mesin'));
        $response->assertSessionHas('success');

        $log = MesinPreventiveLog::first();
        $this->assertSame(300500, $log->stroke_at_preventive);
        $this->assertSame($f['admin']->id, $log->created_by);

        $dataResponse = $this->actingAs($f['admin'])->getJson(route('maintenance.control-stroke-mesin.data'));
        $dataResponse->assertJsonFragment(['current_stroke' => 0, 'status' => 'ok']);
    }

    public function test_staff_maintenance_can_store_preventive(): void
    {
        $f     = $this->fixtures();
        $staff = User::factory()->create(['role' => 'staff_maintenance']);

        $response = $this->actingAs($staff)->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertRedirect(route('maintenance.control-stroke-mesin'));
        $this->assertDatabaseCount('mesin_preventive_log', 1);
    }

    public function test_manager_cannot_store_preventive(): void
    {
        $f       = $this->fixtures();
        $manager = User::factory()->create(['role' => 'manager']);

        $response = $this->actingAs($manager)->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('mesin_preventive_log', 0);
    }

    public function test_store_preventive_rejects_future_date(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => now()->addDay()->toDateString(),
            'id_karyawan'        => $f['karyawan']->id,
        ]);

        $response->assertSessionHasErrors('tanggal_preventive');
        $this->assertDatabaseCount('mesin_preventive_log', 0);
    }

    public function test_store_preventive_rejects_unknown_karyawan(): void
    {
        $f = $this->fixtures();

        $response = $this->actingAs($f['admin'])->post(route('maintenance.control-stroke-mesin.preventive', $f['mesin']->id), [
            'tanggal_preventive' => '2026-06-15',
            'id_karyawan'        => 999999,
        ]);

        $response->assertSessionHasErrors('id_karyawan');
        $this->assertDatabaseCount('mesin_preventive_log', 0);
    }
}
