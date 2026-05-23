<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@optima.local'],
            [
                'name'     => 'Administrator',
                'password' => Hash::make('optima@2026'),
                'role'     => 'admin',
            ]
        );
    }
}
