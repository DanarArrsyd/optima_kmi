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

    /** Halaman yang punya dua grafik: Mode 1 (semua orang) + Mode 2 (per orang). */
    public static function halamanVcb(): array
    {
        return [
            'produksi' => ['vcb.produksi'],
            'quality' => ['vcb.quality'],
        ];
    }

    /**
     * SEMUA halaman bergrafik. Cacat atribut `height` menular lewat salin-tempel
     * markup — sekali satu halaman kena, kembarannya hampir pasti kena juga.
     * Empat grafik VCB dan tujuh grafik MTC lolos berbulan-bulan karena tidak
     * ada yang memeriksa selain mata.
     */
    public static function halamanBergrafik(): array
    {
        return [
            'vcb produksi' => ['vcb.produksi'],
            'vcb quality' => ['vcb.quality'],
            'vcb dandori' => ['vcb.dandori'],
            'vcb mesin' => ['vcb.mesin'],
            'mtc mttr' => ['maintenance.mttr'],
            'mtc mtbf' => ['maintenance.mtbf'],
            'mtc availability' => ['maintenance.availability'],
            'mtc mttr dies' => ['maintenance.mttr-dies'],
            'mtc mtbf dies' => ['maintenance.mtbf-dies'],
            'mtc availability dies' => ['maintenance.availability-dies'],
        ];
    }

    private function halaman(string $route): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
    }

    #[DataProvider('halamanBergrafik')]
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

    #[DataProvider('halamanBergrafik')]
    public function test_setiap_canvas_punya_pembungkus_bertinggi_tetap(string $route): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman($route));

        preg_match_all('/<canvas id="(\w+)">/', $html, $semua);
        $this->assertNotEmpty($semua[1], "nol <canvas> di {$route}");

        // Pembungkus wajib position:relative — tanpa itu Chart.js mengukur
        // induk yang salah dan tinggi tetapnya tidak berpengaruh.
        preg_match_all(
            '/<div [^>]*style="position:relative;height:\d+px"> <canvas id="(\w+)">/',
            $html,
            $terbungkus
        );

        $this->assertEqualsCanonicalizing(
            $semua[1],
            $terbungkus[1],
            "setiap canvas di {$route} harus berada di dalam pembungkus position:relative bertinggi tetap"
        );
    }

    #[DataProvider('halamanBergrafik')]
    public function test_setiap_halaman_bergrafik_mematikan_maintain_aspect_ratio(string $route): void
    {
        $html = preg_replace('/\s+/', ' ', $this->halaman($route));

        // Sengaja "minimal satu", bukan hitungan persis: sebagian halaman
        // membangun beberapa grafik dari SATU pabrik (VCB Dandori punya tiga
        // grafik dari satu buildBar()), jadi jumlah kemunculannya tidak sama
        // dengan jumlah grafiknya. Yang dijaga di sini kelalaian total.
        $this->assertStringContainsString(
            'maintainAspectRatio: false',
            $html,
            "grafik di {$route} harus menyetel maintainAspectRatio: false"
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

    public function test_kolom_tengah_dandori_menggulung_sendiri(): void
    {
        // VCB Dandori satu-satunya halaman yang memakai susunan tiga kolom
        // sendiri, bukan .erp-scroll. Sidebar kiri dan panel kanan sudah punya
        // overflow-y; kolom tengah sempat tidak, sehingga grafik paling bawah
        // meluber keluar .erp-ws-body dan lewat DI BELAKANG .erp-status —
        // terbaca seperti footer yang menimpa grafik.
        //
        // min-height:0 ikut dijaga: tanpa itu anak flex memakai min-height:auto
        // dan menolak menyusut di bawah tinggi kontennya, jadi overflow-y tidak
        // pernah aktif dan perbaikannya tidak berpengaruh sama sekali.
        $html = $this->halaman('vcb.dandori');

        $awal = strpos($html, '.vcbd-main {');
        $this->assertNotFalse($awal, 'aturan .vcbd-main tidak ditemukan');
        $blok = substr($html, $awal, strpos($html, '}', $awal) - $awal);

        $this->assertStringContainsString('overflow-y: auto;', $blok);
        $this->assertStringContainsString('min-height: 0;', $blok);
    }
}
