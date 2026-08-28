<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Grafik VCB pernah menyusut jadi ±300px di dalam panel yang lebarnya penuh,
 * menyisakan dua pertiga kanvas kosong.
 *
 * Sebabnya pasangan setelan bawaan: canvas memakai atribut `height`, dan
 * `maintainAspectRatio` dibiarkan bernilai bawaan `true`. Chart.js lalu
 * menghitung rasio dari ATRIBUT canvas (lebar bawaan 300 × height 70) dan
 * memakai rasio itu saat lebar induk belum terukur — hasilnya grafik terkunci
 * kecil dan tidak pernah memulihkan diri.
 *
 * Perbaikannya memindahkan tinggi ke pembungkus ber-`position:relative` dan
 * menyetel `maintainAspectRatio: false`, pola yang sudah dipakai VCB Dandori
 * sejak awal dan tidak bergantung pada kapan pengukuran terjadi.
 *
 * Kenapa perlu dijaga di test: mode gagalnya MURNI VISUAL. Halaman tetap
 * 200 OK, datanya tetap benar, nol galat di konsol — tidak ada satu pun sinyal
 * otomatis yang menyala selain asersi ini.
 */
class VcbGrafikUkuranTest extends TestCase
{
    use RefreshDatabase;

    /** Dua halaman VCB yang memakai pola dua-grafik + nama orang di sumbu-X. */
    public static function halamanVcb(): array
    {
        return [
            'produksi' => ['vcb.produksi'],
            'quality' => ['vcb.quality'],
        ];
    }

    private function halaman(string $route): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
    }

    #[DataProvider('halamanVcb')]
    public function test_canvas_tidak_memakai_atribut_height(string $route): void
    {
        // Dijangkarkan ke tag canvas-nya sendiri: kata "height" muncul di
        // banyak tempat lain di halaman ini (CSS inline, opsi Chart.js).
        preg_match_all('/<canvas[^>]*>/', $this->halaman($route), $cocok);

        $this->assertNotEmpty($cocok[0], "nol <canvas> di halaman {$route}");

        foreach ($cocok[0] as $tag) {
            $this->assertStringNotContainsString(
                'height=',
                $tag,
                "canvas masih memakai atribut height — tinggi harus dikunci di pembungkusnya: {$tag}"
            );
        }
    }

    #[DataProvider('halamanVcb')]
    public function test_kedua_grafik_mematikan_maintain_aspect_ratio(string $route): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman($route));

        // Dua grafik per halaman: Mode 1 (semua orang) dan Mode 2 (per orang).
        // Satu saja yang lupa membuat separuh halaman kembali ke bug yang sama.
        $this->assertSame(
            2,
            substr_count($html, 'maintainAspectRatio: false'),
            "kedua grafik di {$route} harus menyetel maintainAspectRatio: false"
        );
    }

    #[DataProvider('halamanVcb')]
    public function test_setiap_canvas_punya_pembungkus_bertinggi_tetap(string $route): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman($route));

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
            "allChart dan oneChart di {$route} harus berada di dalam pembungkus position:relative bertinggi tetap"
        );
    }

    #[DataProvider('halamanVcb')]
    public function test_nama_orang_digambar_di_dalam_batang_bukan_di_sumbu(string $route): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman($route));

        // Dengan puluhan nama, tick sumbu-X saling tabrakan lalu Chart.js
        // memiringkannya — memakan tinggi plot dan menyisakan satu nama
        // menggantung di kiri bawah. Nama sekarang digambar tegak di dalam
        // batangnya sendiri oleh plugin bersama di layout.
        //
        // Dijangkarkan ke PERKABELANNYA ke dalam array plugins Chart.js, bukan
        // ke nama plugin di mana pun. Dua sumber salah-jangkar, dua-duanya
        // sudah sempat membuat test ini hijau padahal plugin-nya dicabut:
        // definisinya hidup di layout yang ikut terender di SETIAP halaman,
        // dan komentar sumbu-X di bawah menyebut nama pemanggilannya persis.
        $this->assertStringContainsString('}, pluginNamaDalamBatang(), {', $html);

        // Tick sumbu-X harus mati; kalau hidup lagi, nama tergambar dua kali
        // dan yang di sumbu kembali miring-miring.
        $this->assertStringContainsString('ticks: { display: false },', $html);
    }
}
