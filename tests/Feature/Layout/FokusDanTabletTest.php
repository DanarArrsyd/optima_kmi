<?php

namespace Tests\Feature\Layout;

use Tests\TestCase;

/**
 * CSS penentu aksesibilitas yang dulu rusak senyap. Murni visual — PHPUnit
 * tidak bisa melihat cincin fokus — jadi yang dikunci aturan CSS-nya utuh.
 */
class FokusDanTabletTest extends TestCase
{
    private function layout(): string
    {
        return file_get_contents(resource_path('views/layouts/app.blade.php'));
    }

    public function test_cincin_fokus_keyboard_tidak_dimatikan(): void
    {
        $css = $this->layout();

        // Dulu: .btn:focus-visible { outline: none !important; } — fokus hilang total.
        $this->assertDoesNotMatchRegularExpression('/\.btn:focus-visible\s*[,{][^}]*outline:\s*none/', $css);
        $this->assertMatchesRegularExpression('/:is\(\.btn, \.erp-btn, \.erp-tb-btn, \.erp-chip, \.erp-dock-x, \.erp-clr\):focus-visible \{\s*outline: 2px solid #111827 !important;/', $css);
    }

    public function test_tabel_tablet_dua_kolom_beku_dan_aksi_menempel_kanan(): void
    {
        $css = $this->layout();

        $this->assertMatchesRegularExpression('/@media \(max-width: 1099\.98px\) \{\s*\.tbl-freeze \.sc-3, \.tbl-freeze \.sc-4, \.tbl-freeze \.sc-5 \{ position: static;/', $css);
        $this->assertMatchesRegularExpression('/\.tbl-freeze\.ada-aksi tr > :last-child \{\s*position: sticky; right: 0;/', $css);
    }

    public function test_judul_halaman_tetap_tampil_di_hp(): void
    {
        $css = $this->layout();
        $blokHp = substr($css, strpos($css, 'Topbar penuh sesak di layar sempit'), 900);

        $this->assertStringContainsString('.topbar-page strong { overflow: hidden; text-overflow: ellipsis;', $blokHp);
        $this->assertDoesNotMatchRegularExpression('/\.topbar-page,\s*\n\s*\.tb-tanggal/', $blokHp);
    }
}
