<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanAksesDitolakTest extends TestCase
{
    use RefreshDatabase;

    public function test_403_menampilkan_halaman_kustom_bukan_bawaan_laravel(): void
    {
        $staffProduksi = User::factory()->create(['role' => 'staff_produksi']);

        // staff_produksi tidak boleh akses input.repair.create (role:admin,staff_repair)
        $response = $this->actingAs($staffProduksi)->get(route('input.repair.create'));

        $response->assertForbidden();
        $response->assertSee('Akses Ditolak');
    }
}
