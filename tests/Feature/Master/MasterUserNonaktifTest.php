<?php

namespace Tests\Feature\Master;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Akun user TANPA SoftDeletes dan created_by di semua tabel transaksi adalah
 * FK RESTRICT (PR #133) — akun yang sudah menulis data tidak bisa dihapus.
 * is_aktif adalah satu-satunya cara mencabut akses mantan pegawai: dijaga
 * dua lapis — LoginRequest menolak login baru, DenyNonaktifSession memutus
 * sesi yang sedang terbuka di request berikutnya.
 */
class MasterUserNonaktifTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_ditolak_untuk_akun_nonaktif(): void
    {
        $user = User::factory()->create(['is_aktif' => false, 'password' => 'rahasia123']);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'rahasia123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_tetap_berhasil_untuk_akun_aktif(): void
    {
        $user = User::factory()->create(['is_aktif' => true, 'password' => 'rahasia123']);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'rahasia123',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_salah_tidak_membocorkan_status_nonaktif(): void
    {
        // Password diperiksa Auth::attempt() SEBELUM is_aktif — orang yang
        // salah password tidak boleh tahu apakah akunnya dinonaktifkan atau
        // memang sekadar salah ketik.
        $user = User::factory()->create(['is_aktif' => false, 'password' => 'rahasia123']);

        $response = $this->post(route('login'), [
            'email' => $user->email,
            'password' => 'salah-total',
        ]);

        // session('errors') di sini array mentah, bukan objek ViewErrorBag:
        // config/session.php menyetel 'serialization' => 'json'.
        $pesan = session('errors')['default']['messages']['email'][0] ?? '';
        $this->assertNotEquals('Akun ini sudah dinonaktifkan. Hubungi admin.', $pesan);
    }

    public function test_sesi_yang_sedang_terbuka_terputus_setelah_dinonaktifkan(): void
    {
        // Skenario nyata: admin menonaktifkan akun operator yang masih login
        // di komputer lantai produksi. Sesinya tidak otomatis kedaluwarsa —
        // DenyNonaktifSession yang memutusnya di request BERIKUTNYA.
        $user = User::factory()->create(['is_aktif' => true, 'role' => 'staff_produksi']);

        $this->actingAs($user)->get(route('vcb.produksi'))->assertOk();

        $user->is_aktif = false;
        $user->save();

        $response = $this->actingAs($user)->get(route('vcb.produksi'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_toggle_menonaktifkan_lalu_mengaktifkan_kembali(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'staff_produksi', 'is_aktif' => true]);

        $this->actingAs($admin)->patch(route('master.users.toggle-aktif', $target))
            ->assertRedirect(route('master.users.index'));
        $this->assertFalse($target->fresh()->is_aktif);

        $this->actingAs($admin)->patch(route('master.users.toggle-aktif', $target))
            ->assertRedirect(route('master.users.index'));
        $this->assertTrue($target->fresh()->is_aktif);
    }

    public function test_tidak_bisa_menonaktifkan_akun_sendiri(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'is_aktif' => true]);

        $response = $this->actingAs($admin)->patch(route('master.users.toggle-aktif', $admin));

        $response->assertRedirect(route('master.users.index'));
        $response->assertSessionHas('error', 'Tidak bisa menonaktifkan akun sendiri.');
        $this->assertTrue($admin->fresh()->is_aktif);
    }

    public function test_update_user_tidak_ikut_mengubah_status_aktif(): void
    {
        // is_aktif fillable (dibutuhkan factory/toggleAktif), tapi
        // UserController::update() sengaja tidak menyertakannya di array
        // yang dikirim ke $user->update() — form Edit User tidak boleh
        // diam-diam mengaktifkan kembali akun yang dinonaktifkan.
        $admin = User::factory()->create(['role' => 'admin']);
        $target = User::factory()->create(['role' => 'staff_produksi', 'is_aktif' => false]);

        $this->actingAs($admin)->put(route('master.users.update', $target), [
            'name' => 'Nama Baru',
            'email' => $target->email,
            'roles' => ['staff_produksi'],
        ])->assertRedirect(route('master.users.index'));

        $this->assertFalse($target->fresh()->is_aktif);
    }
}
