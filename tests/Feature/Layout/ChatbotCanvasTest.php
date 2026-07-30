<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman AI Assistant adalah KANVAS PENUH, bukan kartu.
 *
 * Test ini mengunci keputusan strukturalnya, bukan seleranya. Gaya visual
 * (warna, jarak) sengaja tidak diuji — itu ranah mata, dan test yang mengunci
 * angka piksel cuma bikin perubahan tampilan jadi mahal tanpa menangkap bug.
 */
class ChatbotCanvasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function halaman(): string
    {
        return $this->actingAs($this->admin())->get(route('chatbot.index'))->assertOk()->getContent();
    }

    /**
     * Header panel gelap dibuang: topbar sudah menyebut "AI Assistant", jadi bar
     * berisi judul yang sama hanya memakan ruang vertikal untuk nol informasi.
     */
    public function test_header_panel_yang_menduplikasi_topbar_tidak_dirender(): void
    {
        $html = $this->halaman();

        $this->assertStringNotContainsString('cb-header', $html);
        $this->assertStringNotContainsString('OPTIMA AI Assistant', $html);
    }

    /**
     * Avatar per baris dulu mencuri 44px indent dari SETIAP baris untuk mengulang
     * gambar yang sama.
     */
    public function test_avatar_per_pesan_tidak_dirender(): void
    {
        $this->assertStringNotContainsString('cb-av', $this->halaman());
    }

    /** Keadaan kosong adalah perabot halaman, menggantikan pesan sapaan palsu. */
    public function test_keadaan_kosong_ada_dan_menyebut_yang_bisa_ditanya(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('cb-empty', $html);
        $this->assertStringContainsString('Tanya data OPTIMA', $html);
    }

    public function test_kolom_ketik_dan_saran_tetap_ada(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('cb-input', $html);
        $this->assertStringContainsString('cb-chip', $html);
        $this->assertStringContainsString('cb-foot', $html);
    }

    /**
     * Aset di-cache-bust lewat filemtime. Kalau berkasnya hilang, filemtime()
     * melempar dan halaman 500 — jadi keberadaannya ikut dijaga di sini.
     */
    public function test_aset_css_dan_js_chatbot_ada_dan_ter_cache_bust(): void
    {
        $html = $this->halaman();

        $this->assertFileExists(public_path('css/chatbot.css'));
        $this->assertFileExists(public_path('js/chatbot.js'));
        $this->assertMatchesRegularExpression('#css/chatbot\.css\?v=\d+#', $html);
        $this->assertMatchesRegularExpression('#js/chatbot\.js\?v=\d+#', $html);
    }

    /**
     * Penyeimbang penting: init() sekarang memulai percakapan KOSONG, dan
     * buildHistory() karenanya TIDAK boleh lagi memakai .slice(1). Kalau slice itu
     * kembali sementara sapaan tetap tiada, giliran pertama user hilang senyap
     * dari konteks yang dikirim ke model — bug yang tak terlihat di layar.
     */
    public function test_renderer_tidak_membuang_giliran_pertama_dari_history(): void
    {
        $js = file_get_contents(public_path('js/chatbot.js'));

        $this->assertStringContainsString('this.messages = [];', $js,
            'init() harus memulai dengan percakapan kosong.');

        // Diperiksa pada RANTAI KODE-nya, bukan sekadar ada-tidaknya string
        // ".slice(1)" di berkas: string itu memang disebut di komentar penjelas,
        // dan mencocokkannya mentah-mentah membuat test berbunyi palsu.
        $this->assertMatchesRegularExpression(
            '/return this\.messages\s*\.filter\(/',
            $js,
            'buildHistory() harus langsung memfilter: menyisipkan .slice(1) di sini membuang giliran pertama user.'
        );
    }

    /**
     * Tabel jawaban memakai .erp-table — kelas yang sama dengan seluruh halaman
     * lain, supaya jawaban chatbot terbaca sebagai bagian sistem.
     */
    public function test_renderer_memberi_tabel_kelas_erp_table(): void
    {
        $js = file_get_contents(public_path('js/chatbot.js'));

        $this->assertStringContainsString('table class="erp-table"', $js);
        $this->assertStringContainsString('cb-tablewrap', $js);
    }

    /**
     * Heading, garis, dan kutipan dulu TIDAK ditangani sama sekali, sehingga
     * '### Judul', '---', dan '> catatan' tampil mentah di layar.
     */
    public function test_renderer_menangani_heading_garis_dan_kutipan(): void
    {
        $js = file_get_contents(public_path('js/chatbot.js'));

        $this->assertStringContainsString('<hr>', $js, 'Garis horizontal tidak ditangani.');
        $this->assertStringContainsString('<blockquote>', $js, 'Kutipan tidak ditangani.');
        $this->assertMatchesRegularExpression('/#\{1,6\}/', $js, 'Heading markdown tidak ditangani.');
        // esc() sudah mengubah '>' jadi '&gt;' sebelum loop blok dijalankan,
        // jadi pola kutipan WAJIB mencari bentuk ter-escape itu.
        $this->assertStringContainsString('&gt;', $js,
            'Pola kutipan harus mencari &gt;, bukan >, karena esc() jalan lebih dulu.');
    }
}
