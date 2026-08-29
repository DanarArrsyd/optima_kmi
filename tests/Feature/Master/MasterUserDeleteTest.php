<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `users.created_by`/`updated_by` di transaksi_produksi/qc/dandori/breakdown
 * (dan app_notifications) adalah foreignId RESTRICT — MySQL menolak
 * menghapus baris users yang masih dirujuk. Sebelumnya QueryException 23000
 * itu tidak ditangkap sama sekali, jadi admin yang menghapus akun operator
 * yang sudah pernah input data melihat halaman 500 polos.
 */
class MasterUserDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_hapus_user_yang_belum_pernah_menulis_data_berhasil(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'staff_produksi']);

        $response = $this->actingAs($admin)->delete(route('master.users.destroy', $target));

        $response->assertRedirect(route('master.users.index'));
        $response->assertSessionHas('success');
        $this->assertDatabaseMissing('users', ['id' => $target->id]);
    }

    public function test_hapus_user_yang_sudah_menulis_lhp_tidak_500(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'staff_produksi']);

        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Hapus User', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'HU-01', 'nama_mesin' => 'Press HU', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'HU-P1', 'part_name' => 'Hapus User Part']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500,
        ]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
            'created_by' => $target->id,
        ]);

        // Tanpa penanganan, baris ini melempar QueryException 23000 yang
        // tak tertangkap dan Laravel merender halaman 500 — assertRedirect
        // di bawah gagal dengan status 500, bukan 302, kalau catch-nya hilang.
        $response = $this->actingAs($admin)->delete(route('master.users.destroy', $target));

        $response->assertRedirect(route('master.users.index'));
        $response->assertSessionHas('error');
        $this->assertStringContainsString(
            $target->name,
            session('error'),
            'Pesan galat harus menyebut nama user yang gagal dihapus, bukan pesan generik.'
        );

        // User dan baris LHP-nya tetap utuh — bukti constraint DB yang
        // menolak, bukan penghapusan sebagian yang gagal di tengah jalan.
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseHas('transaksi_produksi', ['created_by' => $target->id]);
    }

    public function test_hapus_akun_sendiri_tetap_ditolak(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->delete(route('master.users.destroy', $admin));

        $response->assertRedirect(route('master.users.index'));
        $response->assertSessionHas('error', 'Tidak bisa menghapus akun sendiri.');
        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }
}
