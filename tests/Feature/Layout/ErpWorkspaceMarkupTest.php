<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga susunan ruang kerja ERP (.erp-ws).
 *
 * Lahir dari kejadian nyata: migrasi VCB NG Proses meninggalkan dua </div>
 * sisa dari panel filter lama. Halaman tetap membalas 200 dan tidak ada test
 * yang jatuh — tapi .erp-scroll dan .erp-ws-main tertutup kepagian, sehingga
 * KPI, grafik, dan tabel jatuh jadi anak .erp-ws-body yang flex-row lalu
 * berjajar setinggi layar. Rusaknya SENYAP, hanya terlihat oleh mata.
 *
 * GuestMarkupBalanceTest hanya mengawal tiga halaman LHP, jadi VCB lolos.
 *
 * Yang diperiksa di sini adalah susunan DOM yang benar-benar dirender, bukan
 * penanda komentar — komentar Blade dibuang saat kompilasi dan tidak pernah
 * sampai ke HTML.
 */
class ErpWorkspaceMarkupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Semua halaman ber-.erp-ws: [nama rute, peran, memakai rantai baku?].
     *
     * Rantai baku = .erp-ws-body > .erp-ws-main > .erp-scroll. VCB Dandori
     * SENGAJA tidak memakainya: dia mempertahankan tata tiga kolom sendiri
     * (.vcbd-wrap) langsung di dalam .erp-ws-body. Itu varian yang disetujui,
     * bukan kelalaian — kalau suatu saat diseragamkan, ubah bendera ini.
     */
    public static function workspacePages(): array
    {
        return [
            'LHP Produksi' => ['input.produksi.index', 'staff_produksi', true],
            'LHP Quality' => ['input.qc.index', 'staff_qc', true],
            'LHP Dandori' => ['input.dandori.index', 'staff_produksi', true],
            'Breakdown Mesin' => ['maintenance.breakdown.index', 'staff_maintenance', true],
            'Breakdown Dies' => ['maintenance.breakdown-dies.index', 'staff_maintenance', true],
            'VCB Produksi' => ['vcb.produksi', 'staff_produksi', true],
            'VCB Quality' => ['vcb.quality', 'staff_qc', true],
            'VCB Dandori' => ['vcb.dandori', 'staff_produksi', false],
            'VCB Mesin' => ['vcb.mesin', 'staff_produksi', true],
            'VCB NG Proses' => ['vcb.ng-proses', 'staff_produksi', true],
            'Daftar NG Proses' => ['vcb.ng-proses.daftar', 'staff_produksi', true],
        ];
    }

    #[DataProvider('workspacePages')]
    public function test_div_seimbang(string $routeName, string $role, bool $rantaiBaku): void
    {
        $html = $this->render($routeName, $role);

        $this->assertSame(
            preg_match_all('/<\/div>/i', $html),
            preg_match_all('/<div\b/i', $html),
            "Div tidak seimbang di {$routeName}"
        );
    }

    #[DataProvider('workspacePages')]
    public function test_susunan_ruang_kerja_utuh(string $routeName, string $role, bool $rantaiBaku): void
    {
        $xpath = $this->xpath($this->render($routeName, $role));

        $ws = $this->satu($xpath, 'erp-ws', $routeName);
        $body = $this->satu($xpath, 'erp-ws-body', $routeName);
        $status = $this->satu($xpath, 'erp-status', $routeName);

        $this->assertTrue($body->parentNode->isSameNode($ws), ".erp-ws-body harus anak langsung .erp-ws di {$routeName}");

        /* Inilah yang menangkap </div> nyasar: begitu ada satu saja penutup
         * berlebih, status bar terlempar keluar .erp-ws dan induknya berubah. */
        $this->assertTrue($status->parentNode->isSameNode($ws), ".erp-status harus anak langsung .erp-ws di {$routeName} — kalau bukan, ada </div> nyasar");

        if (! $rantaiBaku) {
            return;
        }

        $main = $this->satu($xpath, 'erp-ws-main', $routeName);
        $scroll = $this->satu($xpath, 'erp-scroll', $routeName);

        $this->assertTrue($main->parentNode->isSameNode($body), ".erp-ws-main harus anak langsung .erp-ws-body di {$routeName}");
        $this->assertTrue($scroll->parentNode->isSameNode($main), ".erp-scroll harus anak langsung .erp-ws-main di {$routeName}");
    }

    private function satu(DOMXPath $xpath, string $kelas, string $routeName): \DOMElement
    {
        $found = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$kelas} ')]");
        $this->assertGreaterThan(0, $found->length, "Elemen .{$kelas} tidak ada di {$routeName}");

        return $found->item(0);
    }

    private function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return new DOMXPath($doc);
    }

    private function render(string $routeName, string $role): string
    {
        return $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route($routeName))->assertOk()->getContent();
    }
}
