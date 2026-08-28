<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Grafik VCB Produksi pernah menyusut jadi ±300px di dalam panel yang lebarnya
 * penuh, menyisakan dua pertiga kanvas kosong.
 *
 * Sebabnya pasangan setelan bawaan: canvas memakai atribut `height`, dan
 * `maintainAspectRatio` dibiarkan bernilai bawaan `true`. Chart.js lalu
 * menghitung rasio dari ATRIBUT canvas (lebar bawaan 300 × height 70) dan
 * memakai rasio itu saat lebar induk belum terukur — hasilnya grafik terkunci
 * kecil dan tidak pernah memulihkan diri.
 *
 * Perbaikannya memindahkan tinggi ke pembungkus ber-`position:relative` dan
 * menyetel `maintainAspectRatio: false`, pola yang sudah dipakai VCB Dandori.
 * Penjaga ini mengunci ketiganya sekaligus: tinggi tidak boleh kembali ke
 * atribut canvas, dan `maintainAspectRatio` tidak boleh hilang lagi.
 *
 * Kenapa perlu dijaga di test: mode gagalnya MURNI VISUAL. Halaman tetap
 * 200 OK, datanya tetap benar, nol galat di konsol — tidak ada satu pun sinyal
 * otomatis yang menyala selain asersi ini.
 */
class VcbGrafikUkuranTest extends TestCase
{
    use RefreshDatabase;

    private function halaman(): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent();
    }

    public function test_canvas_tidak_memakai_atribut_height(): void
    {
        $html = $this->halaman();

        // Dijangkarkan ke tag canvas-nya sendiri: kata "height" muncul di
        // banyak tempat lain di halaman ini (CSS inline, opsi Chart.js).
        preg_match_all('/<canvas[^>]*>/', $html, $cocok);

        $this->assertNotEmpty($cocok[0], 'nol <canvas> di halaman VCB Produksi');

        foreach ($cocok[0] as $tag) {
            $this->assertStringNotContainsString(
                'height=',
                $tag,
                "canvas masih memakai atribut height — tinggi harus dikunci di pembungkusnya: {$tag}"
            );
        }
    }

    public function test_kedua_grafik_mematikan_maintain_aspect_ratio(): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman());

        // Dua grafik: Mode 1 (semua operator) dan Mode 2 (per operator).
        // Keduanya harus mematikannya; satu saja yang lupa membuat separuh
        // halaman kembali ke bug yang sama.
        $this->assertSame(
            2,
            substr_count($html, 'maintainAspectRatio: false'),
            'kedua grafik VCB Produksi harus menyetel maintainAspectRatio: false'
        );
    }

    public function test_setiap_canvas_punya_pembungkus_bertinggi_tetap(): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman());

        // Pembungkus wajib position:relative — tanpa itu Chart.js mengukur
        // induk yang salah dan tinggi tetapnya tidak berpengaruh.
        preg_match_all(
            '/<div style="position:relative;height:\d+px"> <canvas id="(\w+)">/',
            $html,
            $cocok
        );

        $this->assertEqualsCanonicalizing(
            ['allChart', 'oneChart'],
            $cocok[1],
            'allChart dan oneChart harus berada di dalam pembungkus position:relative bertinggi tetap'
        );
    }

    public function test_nama_operator_digambar_di_dalam_batang_bukan_di_sumbu(): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman());

        // Dengan 30 operator, tick sumbu-X saling tabrakan lalu Chart.js
        // memiringkannya — memakan tinggi plot dan menyisakan satu nama
        // menggantung di kiri bawah. Nama sekarang digambar tegak di dalam
        // batangnya sendiri.
        // Dijangkarkan ke DEKLARASI plugin-nya, bukan ke namanya di mana pun:
        // nama yang sama juga disebut di komentar sumbu-X, jadi pencarian
        // telanjang tetap hijau walau plugin-nya diganti nama atau dicabut.
        $this->assertStringContainsString("id: 'namaDalamBatang',", $html);
        $this->assertStringContainsString('ctx.rotate(-Math.PI / 2)', $html);

        // Tick sumbu-X harus mati; kalau hidup lagi, nama tergambar dua kali
        // dan yang di sumbu kembali miring-miring.
        $this->assertStringContainsString('ticks: { display: false },', $html);
    }
}
