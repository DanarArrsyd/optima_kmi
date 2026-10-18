<?php

namespace Tests\Feature\Sistem;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

/**
 * Repo PUBLIC sejak 2026-10-19 — password admin seeder dulu literal di file
 * dan terbaca siapa pun. Penjaga: password wajib dari ADMIN_SEED_PASSWORD.
 */
class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_seeder_tidak_memuat_password_literal(): void
    {
        $sumber = file_get_contents(database_path('seeders/AdminUserSeeder.php'));

        $this->assertStringNotContainsString('optima@2026', $sumber);
        $this->assertDoesNotMatchRegularExpression("/'password'\\s*=>\\s*['\"]/", $sumber);
    }

    public function test_password_diambil_dari_env(): void
    {
        config(['app.admin_seed_password' => 'rahasia-dari-env-123']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', AdminUserSeeder::EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('rahasia-dari-env-123', $admin->password));
        $this->assertSame(['admin'], $admin->roles);
        $this->assertTrue($admin->hasRole('admin'));
        $this->assertNotNull($admin->email_verified_at);
    }

    public function test_production_tanpa_env_menolak_jalan(): void
    {
        config(['app.admin_seed_password' => null]);
        $this->app['env'] = 'production';

        try {
            // Langsung run(), bukan db:seed — db:seed di production minta konfirmasi interaktif.
            $this->app->make(AdminUserSeeder::class)->run();
            $gagal = null;
        } catch (RuntimeException $e) {
            $gagal = $e;
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertNotNull($gagal, 'Seeder harus melempar exception di production tanpa ADMIN_SEED_PASSWORD.');
        $this->assertStringContainsString('ADMIN_SEED_PASSWORD', $gagal->getMessage());
        $this->assertFalse(User::where('email', AdminUserSeeder::EMAIL)->exists());
    }

    public function test_non_production_tanpa_env_memakai_password_acak(): void
    {
        config(['app.admin_seed_password' => '']);

        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', AdminUserSeeder::EMAIL)->firstOrFail();
        $this->assertFalse(Hash::check('optima@2026', $admin->password));
        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_akun_yang_sudah_ada_tidak_ditimpa(): void
    {
        config(['app.admin_seed_password' => 'pertama-123456']);
        $this->seed(AdminUserSeeder::class);

        config(['app.admin_seed_password' => 'kedua-123456']);
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', AdminUserSeeder::EMAIL)->sole();
        $this->assertTrue(Hash::check('pertama-123456', $admin->password));
    }
}
