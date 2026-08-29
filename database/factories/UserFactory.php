<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'roles' => ['staff_produksi'],
            'is_aktif' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * User punya banyak role sekarang, tapi `'role' => 'admin'` masih
     * ditulis di ratusan test yang sudah ada — role tunggal untuk role
     * tunggal cukup lazim untuk tidak diganti satu per satu. Diterjemahkan
     * jadi `roles => ['admin']` DI SINI SAJA, satu tempat, alih-alih
     * menyunting setiap pemanggil.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (is_array($attributes) && isset($attributes['role']) && ! isset($attributes['roles'])) {
            $attributes['roles'] = [$attributes['role']];
            unset($attributes['role']);
        }

        return parent::create($attributes, $parent);
    }
}
