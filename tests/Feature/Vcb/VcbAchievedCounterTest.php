<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kotak "Total Operator"/"Total Inspector" pernah selalu menampilkan "0 achieved":
 * penghitungnya mengambil traffic_light === 'success', padahal getTrafficLight()
 * memeriksa > 100 LEBIH DULU sehingga 'success' hanya keluar saat achievement tepat
 * 100,00% — praktis mustahil di lantai produksi.
 *
 * traffic_light itu field TAMPILAN. Menghitung dari situ berarti tiap penambahan
 * keadaan baru memecahkan penghitungnya lagi, jadi penjaga ini mengunci penghitungan
 * ke achievement_pct.
 */
class VcbAchievedCounterTest extends TestCase
{
    use RefreshDatabase;

    private function html(string $route): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();

        return preg_replace('/\s+/', ' ', $html);
    }

    /** Ambil badan satu fungsi JS supaya asersi tidak menyentuh sisa halaman. */
    private function fungsiJs(string $html, string $nama): string
    {
        $awal = strpos($html, 'function '.$nama.'(');
        $this->assertNotFalse($awal, "Fungsi {$nama}() tidak ditemukan di halaman.");

        return substr($html, $awal, strpos($html, 'function ', $awal + 1) - $awal);
    }

    public function test_penghitung_achieved_produksi_memakai_achievement_pct(): void
    {
        $kpi = $this->fungsiJs($this->html('vcb.produksi'), 'renderAllKpi');

        $this->assertStringContainsString('r.achievement_pct >= 100', $kpi);
        $this->assertStringNotContainsString("traffic_light === 'success'", $kpi);
    }

    public function test_penghitung_achieved_quality_memakai_achievement_pct(): void
    {
        $kpi = $this->fungsiJs($this->html('vcb.quality'), 'renderAllKpi');

        $this->assertStringContainsString('r.achievement_pct >= 100', $kpi);
        $this->assertStringNotContainsString("traffic_light === 'success'", $kpi);
    }

    public function test_kolom_status_quality_mengenali_keadaan_info(): void
    {
        $label = $this->fungsiJs($this->html('vcb.quality'), 'statusLabel');

        // Tanpa cabang 'info', tiap baris di atas 100% mencetak "—".
        $this->assertStringContainsString('info', $label);
    }

    public function test_kolom_status_produksi_mengenali_keadaan_info(): void
    {
        $label = $this->fungsiJs($this->html('vcb.produksi'), 'statusLabel');

        $this->assertStringContainsString('info', $label);
    }

    public function test_badge_mesin_memakai_achievement_pct(): void
    {
        $html = $this->html('vcb.mesin');

        // Mesin di atas target dulu memakai cabang terakhir 'bg-danger' — merah,
        // padahal justru melampaui target.
        $this->assertStringContainsString("row.achievement_pct >= 100 ? 'bg-success'", $html);
        $this->assertStringNotContainsString("row.traffic_light === 'success' ? 'bg-success'", $html);
    }
}
