<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest tidak boleh melihat kontrol yang tidak bisa dia pakai:
 * link master data, link breakdown, tombol input/import/export.
 */
class GuestUiTest extends TestCase
{
    use RefreshDatabase;

    private function guest(): User
    {
        return User::factory()->create(['role' => 'guest']);
    }

    public function test_sidebar_hides_master_and_breakdown_links(): void
    {
        $response = $this->actingAs($this->guest())->get(route('vcb.produksi'))->assertOk();

        $response->assertDontSee(route('master.karyawan.index'));
        $response->assertDontSee(route('master.users.index'));
        $response->assertDontSee(route('maintenance.breakdown.index'));
        $response->assertDontSee(route('maintenance.breakdown-dies.index'));
        $response->assertDontSee(route('chatbot.index'));
    }

    public function test_sidebar_keeps_allowed_links(): void
    {
        $response = $this->actingAs($this->guest())->get(route('vcb.produksi'))->assertOk();

        $response->assertSee(route('vcb.quality'));
        $response->assertSee(route('maintenance.mttr'));
        $response->assertSee(route('input.produksi.index'));
    }

    public function test_topbar_shows_read_only_badge(): void
    {
        $this->actingAs($this->guest())->get(route('vcb.produksi'))
            ->assertOk()
            ->assertSee('MODE TAMU');
    }

    public function test_admin_does_not_see_read_only_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.produksi'))
            ->assertOk()
            ->assertDontSee('MODE TAMU');
    }

    public function test_lhp_pages_hide_write_buttons_for_guest(): void
    {
        $guest = $this->guest();

        foreach (['input.produksi.index', 'input.qc.index', 'input.dandori.index'] as $routeName) {
            $response = $this->actingAs($guest)->get(route($routeName))->assertOk();

            $response->assertDontSee('Input Baru');
            $response->assertDontSee('Import Excel');
            $response->assertDontSee('Export Excel');
        }
    }

    /**
     * Assertion menyasar penanda elemen tombolnya, bukan kata "Export" —
     * kata itu masih muncul di identifier JS (mis. `oneExportBtn`) yang tetap dirender.
     */
    public function test_vcb_pages_hide_export_for_guest(): void
    {
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('vcb.produksi'))->assertOk()->assertDontSee('id="exportExcel"', false);
        $this->actingAs($guest)->get(route('vcb.mesin'))->assertOk()->assertDontSee('id="exportExcel"', false);
        $this->actingAs($guest)->get(route('vcb.quality'))->assertOk()->assertDontSee('id="exportExcel"', false);
        $this->actingAs($guest)->get(route('vcb.dandori'))->assertOk()->assertDontSee(':href="exportUrl"', false);
    }

    /** Kontrol pembanding: non-guest tetap dapat tombol export-nya. */
    public function test_vcb_pages_keep_export_for_manager(): void
    {
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)->get(route('vcb.produksi'))->assertOk()->assertSee('id="exportExcel"', false);
        $this->actingAs($manager)->get(route('vcb.mesin'))->assertOk()->assertSee('id="exportExcel"', false);
        $this->actingAs($manager)->get(route('vcb.quality'))->assertOk()->assertSee('id="exportExcel"', false);
        $this->actingAs($manager)->get(route('vcb.dandori'))->assertOk()->assertSee(':href="exportUrl"', false);
    }

    public function test_staff_still_sees_write_buttons(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('input.produksi.index'))
            ->assertOk()
            ->assertSee('Input Baru');

        // Kontrol pembanding untuk halaman QC: test guest di atas
        // (test_lhp_pages_hide_write_buttons_for_guest) hanya lulus kalau
        // "Input Baru" DISEMBUNYIKAN dari guest. Tanpa pasangan positif ini,
        // kalau gerbang @if(! isGuest()) atau jangkarnya sendiri kebobol
        // hilang dari input.qc.index, test guest tetap lolos (staf QC pun
        // tidak akan melihat tombolnya) — kegagalannya tidak akan pernah
        // ketahuan.
        $staffQc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($staffQc)->get(route('input.qc.index'))
            ->assertOk()
            ->assertSee('Input Baru');
    }
}
