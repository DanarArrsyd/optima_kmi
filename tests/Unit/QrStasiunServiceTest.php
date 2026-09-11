<?php

namespace Tests\Unit;

use App\Services\QrStasiunService;
use Tests\TestCase;

class QrStasiunServiceTest extends TestCase
{
    public function test_svg_for_menghasilkan_markup_svg_yang_memuat_url(): void
    {
        $svg = (new QrStasiunService)->svgFor('https://optima.kencomanufactur.co.id/input/repair/create');

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
    }

    public function test_svg_for_url_berbeda_menghasilkan_svg_berbeda(): void
    {
        $qr = new QrStasiunService;

        $svgA = $qr->svgFor('https://optima.kencomanufactur.co.id/input/repair/create');
        $svgB = $qr->svgFor('https://optima.kencomanufactur.co.id/input/repair-produksi/create');

        $this->assertNotSame($svgA, $svgB);
    }
}
