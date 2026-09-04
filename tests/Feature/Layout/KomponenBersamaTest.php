<?php

namespace Tests\Feature\Layout;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Komponen ketik-cari hanya boleh punya SATU definisi, di layout.
 *
 * taField() pernah ada di 18 berkas view dalam lima varian teks yang sudah
 * menyimpang satu sama lain — tiap perbaikan bug harus dikerjakan lima kali,
 * dan yang begitu selalu ketinggalan di satu tempat. Pola yang sama sudah
 * terjadi pada .fbar-* dan toggleDock().
 *
 * Test ini menjaga supaya salinan lokal tidak pelan-pelan tumbuh lagi.
 */
class KomponenBersamaTest extends TestCase
{
    /** Komponen yang definisinya dikunci di layouts/app.blade.php. */
    private const KOMPONEN = [
        'function taField(',
        'function taFieldFiltered(',
        'function filterSearchComp(',
        'function partGroupComp(',
        'function erpToggleDock(',
        'function saringOrangNonaktif(',
        'function partPicker(',
        'function batchDraft(',
        'function pluginNamaDalamBatang(',
        'function jamAntara(',
        'function majukanNomorUrut(',
        'const ROMAN_BULAN =',
        'function scheduleItemEdit(',
    ];

    public static function komponen(): array
    {
        return array_map(fn ($k) => [$k], self::KOMPONEN);
    }

    #[DataProvider('komponen')]
    public function test_hanya_satu_definisi_dan_ada_di_layout(string $tanda): void
    {
        $layout = base_path('resources/views/layouts/app.blade.php');
        $this->assertStringContainsString(
            $tanda,
            file_get_contents($layout),
            "{$tanda} harus didefinisikan di layouts/app.blade.php"
        );

        $penyalin = [];
        foreach ($this->berkasView() as $p) {
            if ($p === $layout) {
                continue;
            }
            if (str_contains(file_get_contents($p), $tanda)) {
                $penyalin[] = str_replace(base_path('resources/views/'), '', $p);
            }
        }

        $this->assertSame([], $penyalin, sprintf(
            "%s disalin ulang di %d berkas: %s\n".
            'Pakai definisi bersama di layout — salinan pasti menyimpang.',
            $tanda, count($penyalin), implode(', ', $penyalin)
        ));
    }

    /** @return list<string> */
    private function berkasView(): array
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('resources/views'))
        );
        $out = [];
        foreach ($it as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                $out[] = $f->getPathname();
            }
        }

        return $out;
    }
}
