<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dock filter seragam: SEMUA halaman merender dock dari komponen x-filter.*
 * (acuan LHP Produksi), bukan markup bespoke.
 *
 * Sebelum diseragamkan ada empat gaya dock (form GET + Alpine, form AJAX
 * erpToggleDock + <select>, <select> polos, panel cs-* Bootstrap) dan masing-
 * masing pernah menyimpang sendiri. Kalau satu halaman baru menulis
 * <aside class="erp-dock"> sendiri lagi, test di sini merah.
 *
 * VCB Dandori SENGAJA tidak ada di daftar — tata tiga kolomnya pengecualian
 * yang tercatat (panel mesin selalu terlihat), bukan dock.
 */
class DockFilterSeragamTest extends TestCase
{
    use RefreshDatabase;

    /** [route, role, punya centang Filter by Date?] — null = halaman tanpa tanggal. */
    public static function halaman(): array
    {
        return [
            'LHP Produksi' => ['input.produksi.index', 'admin', true],
            'LHP Quality' => ['input.qc.index', 'admin', true],
            'LHP Dandori' => ['input.dandori.index', 'admin', true],
            'LHR Repair' => ['input.repair.index', 'admin', true],
            'LHR Repair Produksi' => ['input.repair-produksi.index', 'admin', true],
            'Breakdown Mesin' => ['maintenance.breakdown.index', 'admin', true],
            'Breakdown Dies' => ['maintenance.breakdown-dies.index', 'admin', true],
            'Log Aktivitas' => ['sistem.log-aktivitas.index', 'admin', true],
            'Anomali' => ['admin.anomali', 'admin', false],
            'VCB Produksi' => ['vcb.produksi', 'admin', false],
            'VCB Quality' => ['vcb.quality', 'admin', false],
            'VCB Mesin' => ['vcb.mesin', 'admin', false],
            'VCB NG Proses' => ['vcb.ng-proses', 'admin', false],
            'VCB NG Total' => ['vcb.ng-total', 'admin', false],
            'Daftar NG Proses' => ['vcb.ng-proses.daftar', 'admin', false],
            'Daftar NG Total' => ['vcb.ng-total.daftar', 'admin', false],
            'MTTR Mesin' => ['maintenance.mttr', 'admin', false],
            'MTBF Mesin' => ['maintenance.mtbf', 'admin', false],
            'Availability Mesin' => ['maintenance.availability', 'admin', false],
            'MTTR Dies' => ['maintenance.mttr-dies', 'admin', false],
            'MTBF Dies' => ['maintenance.mtbf-dies', 'admin', false],
            'Availability Dies' => ['maintenance.availability-dies', 'admin', false],
            'Control Stroke Mesin' => ['maintenance.control-stroke-mesin', 'admin', null],
            'Control Stroke Dies' => ['maintenance.control-stroke-dies', 'admin', null],
            'Master Mesin' => ['master.mesin.index', 'admin', null],
            'Master Karyawan' => ['master.karyawan.index', 'admin', null],
            'Master Part' => ['master.part.index', 'admin', null],
            'Master Shift' => ['master.shift.index', 'admin', null],
            'Master Loss Time' => ['master.losstime.index', 'admin', null],
            'Master Jenis NG' => ['master.jenis-ng.index', 'admin', null],
            'Master Users' => ['master.users.index', 'admin', null],
            'Master Proses Repair' => ['master.proses-repair.index', 'admin', null],
            'Master Proses Repair Produksi' => ['master.proses-repair-produksi.index', 'admin', null],
        ];
    }

    private function html(string $route, string $role): string
    {
        return $this->actingAs(User::factory()->create(['role' => $role]))
            ->get(route($route))->assertOk()->getContent();
    }

    #[DataProvider('halaman')]
    public function test_dock_dari_komponen_baku(string $route, string $role, ?bool $centang): void
    {
        $html = $this->html($route, $role);

        // Penanda cangkang komponen: tombol tutup ber-aria-label + Reset ke URL
        // halaman tanpa query. Dock bespoke lama tidak punya keduanya.
        $this->assertGreaterThanOrEqual(1, substr_count($html, 'aria-label="Tutup filter"'), 'dock bukan dari x-filter.dock');
        $this->assertStringContainsString('href="'.route($route).'" class="erp-btn">Reset</a>', $html);
        $this->assertStringNotContainsString('onclick="erpToggleDock(', $html, 'masih memakai toggle dock lama');
        $this->assertStringNotContainsString('<select name="filter_', $html, 'filter masih <select> polos, bukan typeahead');
    }

    #[DataProvider('halaman')]
    public function test_centang_tanggal_hanya_di_halaman_daftar(string $route, string $role, ?bool $centang): void
    {
        $html = $this->html($route, $role);

        if ($centang === null) {
            $this->assertStringNotContainsString('Filter by Date', $html);

            return;
        }

        $this->assertStringContainsString('Filter by Date', $html);
        $this->assertSame($centang, str_contains($html, 'name="pakai_tanggal"'));
        // Pintasan Bulan ada di setiap kelompok tanggal.
        $this->assertStringContainsString('aria-label="Pintasan satu bulan penuh"', $html);
    }

    /** Data JS komponen dipasang SEKALI per halaman walau komponennya dua (VCB Mode 1 & 2). */
    #[DataProvider('halaman')]
    public function test_data_js_komponen_dipasang_tepat_sekali(string $route, string $role, ?bool $centang): void
    {
        $html = $this->html($route, $role);

        foreach (['window._mesinForJs =', 'window._partsTA ='] as $tanda) {
            $this->assertLessThanOrEqual(1, substr_count($html, $tanda), $tanda);
        }
        $this->assertStringNotContainsString('const _mesinForJs', $html);
        $this->assertStringNotContainsString('const _partsTA', $html);
    }

    public function test_tidak_ada_aside_dock_mentah_di_luar_komponen(): void
    {
        $mentah = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));
        foreach ($it as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php') || str_contains($f->getPathname(), 'components/filter')) {
                continue;
            }
            if (str_contains(file_get_contents($f->getPathname()), '<aside class="erp-dock"')) {
                $mentah[] = str_replace(resource_path('views').'/', '', $f->getPathname());
            }
        }

        $this->assertSame([], $mentah, 'dock ditulis tangan — pakai x-filter.dock');
    }
}
