<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@optima.local'],
            [
                'name'              => 'Administrator',
                'email_verified_at' => now(),
                'password'          => 'optima@2026',
                'role'              => 'admin',
            ]
        );
    }
}
