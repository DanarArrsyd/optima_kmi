<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Halaman AJAX wajib memberi tahu saat memuat dan saat GAGAL. Dulu 8 halaman
 * maintenance diam saja (grafik lama tetap tampil) dan VCB Dandori menelan
 * galat ke console. PHPUnit tak menjalankan JS — asersi menunjuk ke kode yang
 * benar-benar menjalankan mekanismenya, bukan sekadar kata kuncinya.
 */
class StatusMuatTest extends TestCase
{
    use RefreshDatabase;

    private function html(string $route): string
    {
        return $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route($route))->assertOk()->getContent();
    }

    public static function maintenance(): array
    {
        return [
            'mttr' => ['maintenance.mttr', true], 'mtbf' => ['maintenance.mtbf', true],
            'availability' => ['maintenance.availability', true], 'mttr dies' => ['maintenance.mttr-dies', true],
            'mtbf dies' => ['maintenance.mtbf-dies', true], 'availability dies' => ['maintenance.availability-dies', true],
            'control stroke mesin' => ['maintenance.control-stroke-mesin', false],
            'control stroke dies' => ['maintenance.control-stroke-dies', false],
        ];
    }

    #[DataProvider('maintenance')]
    public function test_pemuat_dibungkus_status_muat(string $route, bool $cekOk): void
    {
        $html = $this->html($route);

        $this->assertStringContainsString('id="anOverlay"', $html);
        $this->assertStringContainsString('id="anGalat"', $html);
        $this->assertStringContainsString("function loadData() { return muatDenganStatus('an', loadDataInti); }", $html);
        // Respons 500 harus jadi galat, bukan grafik kosong yang diam.
        $this->assertMatchesRegularExpression('/const res\s+= await fetch\([^\n]*\n\s*if \(!res\.ok\) throw new Error/', $html);
    }

    public function test_helper_menjalankan_semua_langkah_dalam_try_dan_menutup_overlay_di_finally(): void
    {
        $html = $this->html('maintenance.mttr');

        $this->assertMatchesRegularExpression(
            "/try \{\s*if \(ov\) ov\.style\.display = 'flex';\s*if \(galat\) galat\.style\.display = 'none';\s*tombol\.forEach\(b => \{ b\.disabled = true; \}\);\s*await pemuat\(\);\s*\} catch \(e\) \{\s*console\.error\('Gagal memuat:', e\);\s*if \(galat\) galat\.style\.display = '';\s*\} finally \{\s*if \(ov\) ov\.style\.display = 'none';\s*tombol\.forEach\(b => \{ b\.disabled = false; \}\);\s*\}/",
            $html
        );
    }

    public function test_vcb_dandori_menampilkan_galat_bukan_menelannya(): void
    {
        $html = $this->html('vcb.dandori');

        $this->assertMatchesRegularExpression("/catch \(e\) \{\s*console\.error\('VCB Dandori:', e\);\s*this\.galat = true;/", $html);
        $this->assertStringContainsString('x-show="galat"', $html);
        $this->assertStringContainsString("if (!res.ok) throw new Error('HTTP ' + res.status);", $html);
    }

    public static function vcbOrang(): array
    {
        return ['produksi' => ['vcb.produksi'], 'quality' => ['vcb.quality']];
    }

    #[DataProvider('vcbOrang')]
    public function test_vcb_tanpa_alert_bawaan_browser(string $route): void
    {
        $html = $this->html($route);

        $this->assertDoesNotMatchRegularExpression("/\balert\(['a-z]/", $html);
        $this->assertStringContainsString("mintaPilihOrang('Pilih ", $html);
    }
}
