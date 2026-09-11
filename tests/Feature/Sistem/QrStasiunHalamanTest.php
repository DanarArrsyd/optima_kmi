<?php

namespace Tests\Feature\Sistem;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrStasiunHalamanTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_buka_halaman_generate_qr_stasiun(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('sistem.qr-stasiun.index'));

        $response->assertOk();
        $response->assertSee('LHR Regular');
        $response->assertSee('<svg', false);
    }

    public static function peranBukanAdmin(): array
    {
        return [
            'manager' => ['manager'],
            'staff_produksi' => ['staff_produksi'],
            'staff_repair' => ['staff_repair'],
            'guest' => ['guest'],
        ];
    }

    #[DataProvider('peranBukanAdmin')]
    public function test_non_admin_tidak_bisa_buka_halaman_generate_qr_stasiun(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('sistem.qr-stasiun.index'))->assertForbidden();
    }

    public function test_guest_belum_login_dilempar_ke_halaman_login(): void
    {
        $this->get(route('sistem.qr-stasiun.index'))->assertRedirect(route('login'));
    }
}
