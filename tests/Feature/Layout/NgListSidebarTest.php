<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Entri sidebar Daftar NG, dan penanda `active` yang menyertainya.
 *
 * Entri menu VCB memakai `str_starts_with($uri, 'vcb/ng-proses')`. Prefiks itu
 * juga cocok dengan `vcb/ng-proses/daftar`, jadi menambahkan halaman daftar tanpa
 * mengubah cek tersebut membuat DUA entri menyala bersamaan. Test ini menguncinya.
 */
class NgListSidebarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Jumlah kemunculan `class="nav-item-link active"` pada satu href. */
    private function aktifUntuk(string $html, string $href): bool
    {
        $pola = '/<a\s+href="'.preg_quote($href, '/').'"[^>]*class="[^"]*\bactive\b/';

        return preg_match($pola, $html) === 1;
    }

    public function test_sidebar_menampilkan_kedua_entri_daftar(): void
    {
        $response = $this->actingAs($this->admin())->get(route('vcb.ng-proses'))->assertOk();

        $response->assertSee(route('vcb.ng-proses.daftar'));
        $response->assertSee(route('vcb.ng-total.daftar'));
        $response->assertSee('Daftar NG Proses');
        $response->assertSee('Daftar NG Total');
    }

    public function test_membuka_dashboard_ng_proses_tidak_menyalakan_entri_daftar(): void
    {
        $html = $this->actingAs($this->admin())->get(route('vcb.ng-proses'))->assertOk()->getContent();

        $this->assertTrue($this->aktifUntuk($html, route('vcb.ng-proses')), 'Entri dashboard harus aktif.');
        $this->assertFalse($this->aktifUntuk($html, route('vcb.ng-proses.daftar')), 'Entri daftar tidak boleh ikut aktif.');
    }

    /** Inti temuan: prefiks `vcb/ng-proses` juga cocok dengan `/daftar`. */
    public function test_membuka_daftar_ng_proses_tidak_menyalakan_entri_dashboard(): void
    {
        $html = $this->actingAs($this->admin())->get(route('vcb.ng-proses.daftar'))->assertOk()->getContent();

        $this->assertTrue($this->aktifUntuk($html, route('vcb.ng-proses.daftar')), 'Entri daftar harus aktif.');
        $this->assertFalse($this->aktifUntuk($html, route('vcb.ng-proses')), 'Entri dashboard tidak boleh ikut aktif.');
    }

    public function test_membuka_daftar_ng_total_tidak_menyalakan_entri_dashboard(): void
    {
        $html = $this->actingAs($this->admin())->get(route('vcb.ng-total.daftar'))->assertOk()->getContent();

        $this->assertTrue($this->aktifUntuk($html, route('vcb.ng-total.daftar')), 'Entri daftar harus aktif.');
        $this->assertFalse($this->aktifUntuk($html, route('vcb.ng-total')), 'Entri dashboard tidak boleh ikut aktif.');
    }

    /** Accordion grup harus tetap terbuka di grup yang benar saat daftar dibuka. */
    public function test_grup_sidebar_tetap_benar_saat_daftar_dibuka(): void
    {
        $this->actingAs($this->admin())
            ->get(route('vcb.ng-proses.daftar'))
            ->assertOk()
            ->assertSee("vcbGroups('produksi')", false);

        $this->actingAs($this->admin())
            ->get(route('vcb.ng-total.daftar'))
            ->assertOk()
            ->assertSee("vcbGroups('quality')", false);
    }

    public function test_staff_qc_tidak_melihat_entri_daftar_produksi(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $response = $this->actingAs($qc)->get(route('vcb.ng-total'))->assertOk();

        $response->assertDontSee(route('vcb.ng-proses.daftar'));
        $response->assertSee(route('vcb.ng-total.daftar'));
    }
}
