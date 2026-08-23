<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Awan menutupi tombol GAS LOGIN selama email dan password belum terisi, lalu
 * melorot turun begitu keduanya diisi.
 *
 * Halaman login satu-satunya halaman yang kalau salah bikin SEMUA orang tidak
 * bisa masuk sistem — termasuk admin yang mau memperbaikinya. Karena itu yang
 * dijaga di sini bukan efeknya, tapi bahwa efeknya tidak pernah bisa mengunci.
 */
class HalamanLoginAwanTest extends TestCase
{
    use RefreshDatabase;

    private function halaman(): string
    {
        return $this->get(route('login'))->assertOk()->getContent();
    }

    public function test_tombol_login_tidak_pernah_dinonaktifkan_server(): void
    {
        $html = $this->halaman();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new \DOMXPath($dom);

        $tombol = $xpath->query("//button[contains(concat(' ', normalize-space(@class), ' '), ' btn-login ')]");
        $this->assertGreaterThan(0, $tombol->length, 'Tombol GAS LOGIN tidak ada.');

        $el = $tombol->item(0);
        $this->assertFalse($el->hasAttribute('disabled'),
            'Tombol login tidak boleh dinonaktifkan dari server — satu galat JS mengunci semua orang.');
        $this->assertSame('submit', $el->getAttribute('type'));
    }

    public function test_awan_menutup_hanya_setelah_js_hidup(): void
    {
        // Kalau kelas penutup ini ikut dirender server, peramban yang JS-nya
        // mati akan melihat tombol tertutup awan selamanya. Penutupnya HARUS
        // dipasang JS, supaya tanpa JS tombolnya kelihatan seperti biasa.
        $html = $this->halaman();

        $this->assertStringContainsString('id="awan"', $html, 'Awan tidak dirender.');
        $this->assertStringNotContainsString('awan awan-aktif', $html,
            'Kelas penutup awan tidak boleh dirender server — itu mengunci peramban tanpa JS.');
        $this->assertStringContainsString("classList.add('awan-aktif')", $html,
            'Awan tidak pernah dipasang oleh JS.');
    }

    public function test_awan_tidak_bisa_menelan_klik(): void
    {
        // Awan menumpuk di atas tombol. Tanpa pointer-events:none ia menelan
        // klik operator dan tombolnya jadi mati rasa walau terlihat.
        $html = $this->halaman();

        $awal = strpos($html, '.awan {');
        $this->assertNotFalse($awal, 'Aturan .awan tidak ditemukan.');

        $blok = substr($html, $awal, 600);
        $this->assertStringContainsString('pointer-events: none', $blok);
    }

    public function test_awan_turun_saat_kedua_field_terisi(): void
    {
        // Aturannya: turun begitu KEDUA field terisi, naik lagi begitu salah
        // satunya dikosongkan. Diperiksa lewat syarat di skripnya.
        $html = $this->halaman();

        $this->assertStringContainsString("email.value.trim() !== '' && sandi.value !== ''", $html);
        $this->assertStringContainsString("classList.toggle('awan-turun', lengkap)", $html);
    }

    public function test_aset_maskot_dan_lockup_terpasang(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('img/kmi-lockup.png', $html);
        $this->assertStringContainsString('img/kmi-maskot.png', $html);

        $this->assertFileExists(public_path('img/kmi-lockup.png'));
        $this->assertFileExists(public_path('img/kmi-maskot.png'));
    }
}
