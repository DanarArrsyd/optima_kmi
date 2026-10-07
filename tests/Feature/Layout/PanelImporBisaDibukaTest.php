<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tiap panel impor yang dirender WAJIB punya tombol yang membukanya.
 *
 * Ditemukan Okt 2026: Master Shift, Loss Time, dan Jenis NG merender panel
 * impor ber-d-none tanpa tombol apa pun; tombol Breakdown Dies membuka id yang
 * salah ('import-dies-panel'). Empat halaman yang impornya mustahil dipakai,
 * dan seluruh test tetap hijau karena panel & rutenya ada.
 */
class PanelImporBisaDibukaTest extends TestCase
{
    use RefreshDatabase;

    public static function halaman(): array
    {
        return [
            'LHP Produksi' => ['input.produksi.index'],
            'LHP Quality' => ['input.qc.index'],
            'LHP Dandori' => ['input.dandori.index'],
            'LHR Repair' => ['input.repair.index'],
            'LHR Repair Produksi' => ['input.repair-produksi.index'],
            'Breakdown Mesin' => ['maintenance.breakdown.index'],
            'Breakdown Dies' => ['maintenance.breakdown-dies.index'],
            'Master Mesin' => ['master.mesin.index'],
            'Master Karyawan' => ['master.karyawan.index'],
            'Master Part' => ['master.part.index'],
            'Master Shift' => ['master.shift.index'],
            'Master Loss Time' => ['master.losstime.index'],
            'Master Jenis NG' => ['master.jenis-ng.index'],
        ];
    }

    #[DataProvider('halaman')]
    public function test_panel_impor_punya_tombol_pembuka(string $route): void
    {
        $html = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route($route))->assertOk()->getContent();

        preg_match_all('/<div id="(import[A-Za-z-]*)"/', $html, $m);
        $this->assertNotEmpty($m[1], 'halaman berimpor tanpa panel impor');

        foreach ($m[1] as $id) {
            $this->assertStringContainsString("getElementById('{$id}').classList.toggle", $html, "panel #{$id} tak bisa dibuka");
        }
    }
}
