<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Simulasi: staff scan QR ke input.repair.create sebelum login. Laravel
 * bawaan (redirect()->intended()) harus melempar ke /login dulu lalu
 * membalikkan persis ke URL yang di-scan setelah autentikasi sukses —
 * nol kode tambahan dibutuhkan, ini murni mengunci perilaku itu.
 */
class ScanQrRedirectTest extends TestCase
{
    use RefreshDatabase;

    public function test_scan_qr_sebelum_login_diarahkan_balik_ke_url_yang_diminta(): void
    {
        $staff = User::factory()->create(['role' => 'staff_repair', 'password' => bcrypt('rahasia123')]);

        // 1. Scan QR sebelum login → dilempar ke halaman login, intended URL tersimpan di session
        $this->get(route('input.repair.create'))->assertRedirect(route('login'));

        // 2. Login
        $response = $this->post(route('login'), [
            'email' => $staff->email,
            'password' => 'rahasia123',
        ]);

        // 3. Balik persis ke input.repair.create, bukan ke dashboard
        $response->assertRedirect(route('input.repair.create'));
        $this->assertAuthenticatedAs($staff);
    }

    public function test_scan_qr_role_salah_setelah_login_kena_403_bukan_500(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc', 'password' => bcrypt('rahasia123')]);

        $this->get(route('input.repair.create'))->assertRedirect(route('login'));

        $this->post(route('login'), ['email' => $qc->email, 'password' => 'rahasia123']);

        $this->get(route('input.repair.create'))->assertForbidden();
    }
}
