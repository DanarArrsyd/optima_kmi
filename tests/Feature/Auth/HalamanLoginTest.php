<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman login memakai satu gambar desain utuh (login_page.png) sebagai
 * kanvas; form ditempatkan di dalam panel abu yang sudah ada di gambar itu.
 *
 * Halaman login satu-satunya halaman yang kalau rusak bikin SEMUA orang tidak
 * bisa masuk sistem — termasuk admin yang mau memperbaikinya. Yang dijaga di
 * sini hal-hal yang membuatnya tetap bisa dipakai, bukan selera tampilannya.
 */
class HalamanLoginTest extends TestCase
{
    use RefreshDatabase;

    private function halaman(): string
    {
        return $this->get(route('login'))->assertOk()->getContent();
    }

    public function test_tombol_login_tidak_pernah_dinonaktifkan_server(): void
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->halaman());
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $tombol = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' btn-login ')]");
        $this->assertGreaterThan(0, $tombol->length, 'Tombol login tidak ada.');

        $el = $tombol->item(0);
        $this->assertFalse($el->hasAttribute('disabled'),
            'Tombol login tidak boleh dinonaktifkan dari server — itu mengunci semua orang di luar sistem.');
        $this->assertSame('submit', $el->getAttribute('type'));
    }

    public function test_form_login_lengkap_dan_menuju_route_login(): void
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->halaman());
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $form = $xpath->query("//form[@id='formLogin']");
        $this->assertGreaterThan(0, $form->length, 'Form login tidak ada.');
        $this->assertSame(route('login'), $form->item(0)->getAttribute('action'));

        foreach (['email', 'password'] as $field) {
            $this->assertGreaterThan(
                0,
                $xpath->query("//form[@id='formLogin']//input[@name='{$field}']")->length,
                "Field {$field} hilang dari form login."
            );
        }

        // Tanpa token CSRF, setiap percobaan login ditolak 419.
        $this->assertGreaterThan(0, $xpath->query("//form[@id='formLogin']//input[@name='_token']")->length);
    }

    public function test_form_berada_di_dalam_panel_desain(): void
    {
        // Panel abunya bagian dari gambar desain. Kalau form keluar dari
        // .auth-panel, ia melayang di atas gambar dan tidak lagi duduk di
        // kotaknya — rusaknya cuma kelihatan mata, tidak bikin test lain merah.
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$this->halaman());
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $cocok = $xpath->query(
            "//form[@id='formLogin']/ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' auth-panel ')]"
        );
        $this->assertGreaterThan(0, $cocok->length, 'Form login berada di luar panel desain.');
    }

    public function test_gambar_desain_terpasang_dan_ada_berkasnya(): void
    {
        $this->assertStringContainsString('img/login_page.png', $this->halaman());
        $this->assertFileExists(public_path('img/login_page.png'));
    }

    public function test_tidak_ada_lagi_awan_penutup_tombol(): void
    {
        // Awan sempat menutupi tombol sampai kedua field terisi, lalu dicabut
        // atas permintaan pemilik. Dijaga supaya tidak diam-diam kembali:
        // apa pun yang menutupi tombol login adalah risiko terkunci.
        $html = $this->halaman();

        $this->assertStringNotContainsString('id="awan"', $html);
        $this->assertStringNotContainsString('awan-aktif', $html);
    }
}
