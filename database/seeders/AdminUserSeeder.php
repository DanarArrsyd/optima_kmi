<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Repo ini PUBLIC — password admin TIDAK BOLEH literal di sini.
 * Sumbernya ADMIN_SEED_PASSWORD (via config('app.admin_seed_password')).
 * Production tanpa env = exception; local/testing = password acak dicetak sekali.
 */
class AdminUserSeeder extends Seeder
{
    public const EMAIL = 'admin@optima.local';

    public function run(): void
    {
        // Akun yang sudah ada tidak disentuh — password-nya tidak ditimpa.
        if (User::where('email', self::EMAIL)->exists()) {
            return;
        }

        (new User)->forceFill([
            'name' => 'Administrator',
            'email' => self::EMAIL,
            'email_verified_at' => now(),
            'password' => $this->password(),
            'roles' => ['admin'],
        ])->save();
    }

    private function password(): string
    {
        $password = (string) config('app.admin_seed_password');

        if ($password !== '') {
            return $password;
        }

        if (app()->environment('production')) {
            throw new RuntimeException(
                'ADMIN_SEED_PASSWORD kosong — AdminUserSeeder menolak membuat akun admin di production tanpa password dari env.'
            );
        }

        $password = Str::random(24);
        $this->command?->warn('ADMIN_SEED_PASSWORD kosong — password acak untuk '.self::EMAIL.': '.$password);

        return $password;
    }
}
