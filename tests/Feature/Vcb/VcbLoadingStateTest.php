<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spinner overlay + error banner pas fetch data di VCB Mesin, VCB Produksi
 * (Mode 1 & 2), VCB Quality (Mode 1 & 2) — 3 halaman paling rame yang
 * sebelumnya NOL feedback visual pas loading (UI diem/data basi).
 */
class VcbLoadingStateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function html(string $route): string
    {
        $html = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();

        return preg_replace('/\s+/', ' ', $html);
    }

    /** Ambil badan satu fungsi JS supaya asersi tidak menyentuh sisa halaman. */
    private function fungsiJs(string $html, string $nama): string
    {
        $awal = strpos($html, 'function '.$nama.'(');
        $this->assertNotFalse($awal, "Fungsi {$nama}() tidak ditemukan di halaman.");

        // Fungsi ini bisa jadi "function " TERAKHIR di halaman (mis. loadOneOperator()
        // di VCB Produksi) — strpos berikutnya lalu false, jadi ambil sampai akhir string.
        $akhir = strpos($html, 'function ', $awal + 1);
        if ($akhir === false) {
            $akhir = strlen($html);
        }

        return substr($html, $awal, $akhir - $awal);
    }

    public function test_vcb_mesin_markup_overlay_dan_banner_ada(): void
    {
        $html = $this->html('vcb.mesin');

        $this->assertStringContainsString('id="mesinLoadingOverlay"', $html);
        $this->assertStringContainsString('erp-loading-overlay', $html);
        $this->assertStringContainsString('spinner-border', $html);
        $this->assertStringContainsString('id="mesinLoadError"', $html);
        $this->assertStringContainsString('Gagal memuat data — coba lagi.', $html);
    }

    public function test_vcb_mesin_load_data_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.mesin'), 'loadData');

        $this->assertStringContainsString('mesinLoadingOverlay', $fn);
        $this->assertStringContainsString("overlay.style.display = 'flex'", $fn);
        $this->assertStringContainsString('submitBtn.disabled = true', $fn);
        $this->assertStringContainsString('catch (e)', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
        $this->assertStringContainsString("overlay.style.display = 'none'", $fn);
        $this->assertStringContainsString('submitBtn.disabled = false', $fn);
    }

    public function test_vcb_produksi_markup_overlay_dan_banner_ada_di_kedua_mode(): void
    {
        $html = $this->html('vcb.produksi');

        $this->assertStringContainsString('id="allLoadingOverlay"', $html);
        $this->assertStringContainsString('id="allLoadError"', $html);
        $this->assertStringContainsString('id="oneLoadingOverlay"', $html);
        $this->assertStringContainsString('id="oneLoadError"', $html);
        $this->assertSame(2, substr_count($html, 'Gagal memuat data — coba lagi.'));
    }

    public function test_vcb_produksi_load_all_operators_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.produksi'), 'loadAllOperators');

        $this->assertStringContainsString('allLoadingOverlay', $fn);
        $this->assertStringContainsString("overlay.style.display = 'flex'", $fn);
        $this->assertStringContainsString('#formAll button[type="submit"]', $fn);
        $this->assertStringContainsString('submitBtn.disabled = true', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }

    public function test_vcb_produksi_load_one_operator_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.produksi'), 'loadOneOperator');

        $this->assertStringContainsString('oneLoadingOverlay', $fn);
        $this->assertStringContainsString('#formOne button[type="submit"]', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }

    public function test_vcb_quality_markup_overlay_dan_banner_ada_di_kedua_mode(): void
    {
        $html = $this->html('vcb.quality');

        $this->assertStringContainsString('id="allLoadingOverlay"', $html);
        $this->assertStringContainsString('id="allLoadError"', $html);
        $this->assertStringContainsString('id="oneLoadingOverlay"', $html);
        $this->assertStringContainsString('id="oneLoadError"', $html);
        $this->assertSame(2, substr_count($html, 'Gagal memuat data — coba lagi.'));
    }

    public function test_vcb_quality_load_all_inspectors_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.quality'), 'loadAllInspectors');

        $this->assertStringContainsString('allLoadingOverlay', $fn);
        $this->assertStringContainsString('#formAll button[type="submit"]', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }

    public function test_vcb_quality_load_one_inspector_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.quality'), 'loadOneInspector');

        $this->assertStringContainsString('oneLoadingOverlay', $fn);
        $this->assertStringContainsString('#formOne button[type="submit"]', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }
}
