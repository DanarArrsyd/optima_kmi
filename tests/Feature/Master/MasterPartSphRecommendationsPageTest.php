<?php

namespace Tests\Feature\Master;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rekomendasi Upgrade SPH dulu numpang di index Master Part — bisa ratusan
 * baris dan menenggelamkan grid part. Sekarang halaman sendiri, dibuka
 * lewat tombol toolbar di index.
 */
class MasterPartSphRecommendationsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_buka_halaman_rekomendasi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('master.part.sph-recommendations'))
            ->assertOk()
            ->assertSee('Rekomendasi Upgrade SPH');
    }

    public function test_non_admin_ditolak_403(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)
            ->get(route('master.part.sph-recommendations'))
            ->assertForbidden();
    }

    public function test_index_nol_lagi_menampilkan_tabel_rekomendasi_tapi_ada_tombol_dan_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('master.part.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Rekomendasi Upgrade SPH Standard Produksi', $html);
        $this->assertStringNotContainsString('Rekomendasi Upgrade SPH Standard Quality', $html);
        $this->assertStringContainsString(route('master.part.sph-recommendations'), $html);
        $this->assertStringContainsString('Rekomendasi SPH', $html);
    }
}
