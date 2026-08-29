<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function kodeUntuk(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }

    public function test_admin_bisa_buka_halaman_setup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('profile.two-factor'))
            ->assertOk()
            ->assertSee('Autentikasi Dua Faktor');
    }

    public function test_non_admin_ditolak(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('profile.two-factor'))
            ->assertForbidden();
    }

    public function test_konfirmasi_kode_salah_ditolak(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('profile.two-factor')); // generate secret ke session

        $this->post(route('profile.two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }

    public function test_konfirmasi_kode_benar_mengaktifkan_2fa_dan_tampilkan_recovery_code(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('profile.two-factor'));
        $secret = session('2fa.setup.secret');

        $response = $this->post(route('profile.two-factor.confirm'), ['code' => $this->kodeUntuk($secret)]);

        $response->assertRedirect(route('profile.two-factor'));
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, session('recovery_codes'));
    }

    public function test_login_dengan_2fa_aktif_diarahkan_ke_challenge(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);
        $secret = (new TwoFactorAuthService)->generateSecretKey();
        (new TwoFactorAuthService)->confirm($admin, $secret, ['x']);

        $response = $this->post(route('login'), ['email' => $admin->email, 'password' => 'rahasia123']);

        $response->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();
    }

    public function test_challenge_kode_benar_menyelesaikan_login(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);
        $secret = (new TwoFactorAuthService)->generateSecretKey();
        (new TwoFactorAuthService)->confirm($admin, $secret, ['x']);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'rahasia123']);

        $this->post(route('two-factor.challenge'), ['code' => $this->kodeUntuk($secret)])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_challenge_kode_salah_tetap_guest(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);
        $secret = (new TwoFactorAuthService)->generateSecretKey();
        (new TwoFactorAuthService)->confirm($admin, $secret, ['x']);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'rahasia123']);

        $this->post(route('two-factor.challenge'), ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_recovery_code_bisa_login_dan_sekali_pakai(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);
        $secret = (new TwoFactorAuthService)->generateSecretKey();
        (new TwoFactorAuthService)->confirm($admin, $secret, ['kode-cadangan-1']);

        $this->post(route('login'), ['email' => $admin->email, 'password' => 'rahasia123']);
        $this->post(route('two-factor.challenge'), ['code' => 'kode-cadangan-1'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);

        auth()->logout();
        $this->post(route('login'), ['email' => $admin->email, 'password' => 'rahasia123']);
        $this->post(route('two-factor.challenge'), ['code' => 'kode-cadangan-1'])
            ->assertSessionHasErrors('code');
    }

    public function test_nonaktifkan_2fa_butuh_password_benar(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'password' => bcrypt('rahasia123')]);
        $secret = (new TwoFactorAuthService)->generateSecretKey();
        (new TwoFactorAuthService)->confirm($admin, $secret, ['x']);

        $this->actingAs($admin)->delete(route('profile.two-factor.destroy'), ['password' => 'salah'])
            ->assertSessionHasErrors();
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());

        $this->actingAs($admin)->delete(route('profile.two-factor.destroy'), ['password' => 'rahasia123'])
            ->assertRedirect(route('profile.two-factor'));
        $this->assertFalse($admin->fresh()->hasTwoFactorEnabled());
    }
}
