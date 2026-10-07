<?php

namespace Tests\Feature\Sistem;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class LogAktivitasTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_buka_halaman(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('sistem.log-aktivitas.index'))
            ->assertOk()
            ->assertSee('Log Aktivitas');
    }

    public function test_non_admin_ditolak(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('sistem.log-aktivitas.index'))
            ->assertForbidden();
    }

    public function test_perubahan_master_karyawan_tercatat(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);
        $karyawan = MasterKaryawan::create([
            'nik' => '12345',
            'nama_karyawan' => 'Budi',
            'departemen' => 'Produksi',
            'is_aktif' => true,
        ]);

        $log = Activity::where('log_name', 'master_karyawan')
            ->where('event', 'created')
            ->where('subject_id', $karyawan->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertEquals($admin->id, $log->causer_id);
    }

    public function test_login_tercatat(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);

        $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'rahasia123',
        ])->assertRedirect();

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'auth',
            'event' => 'login',
            'causer_id' => $user->id,
        ]);
    }

    public function test_login_dobel_klik_cuma_satu_baris(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);

        $creds = ['email' => $user->email, 'password' => 'rahasia123'];
        $this->post(route('login'), $creds);
        $this->post(route('logout'));
        $this->post(route('login'), $creds);

        $this->assertSame(
            1,
            Activity::where('log_name', 'auth')->where('event', 'login')->where('causer_id', $user->id)->count()
        );
    }

    public function test_perubahan_lhp_produksi_tercatat(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);

        $this->actingAs($admin);
        $lhp = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '15:00',
            'qty_aktual' => 4000,
            'created_by' => $admin->id,
        ]);

        $this->assertTrue(
            Activity::where('log_name', 'lhp_produksi')
                ->where('event', 'created')
                ->where('subject_id', $lhp->id)
                ->exists()
        );
    }

    public function test_filter_modul_menyaring(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        MasterKaryawan::create(['nik' => '1', 'nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]);

        $this->get(route('sistem.log-aktivitas.index', ['modul' => 'master_mesin']))
            ->assertOk()
            ->assertSee('Tidak ada aktivitas');
    }
}
