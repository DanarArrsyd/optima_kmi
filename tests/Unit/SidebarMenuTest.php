<?php

namespace Tests\Unit;

use App\Support\SidebarMenu;
use Tests\TestCase;

/**
 * Penyaringan menu sidebar per role dan penentuan grup aktif.
 *
 * Test ini tidak merender HTML — kalau gagal, penyebabnya ada di data
 * (config/sidebar.php) atau logika (SidebarMenu), bukan di Blade.
 */
class SidebarMenuTest extends TestCase
{
    /** Batas yang membuat sidebar tidak pernah bisa di-scroll. */
    private const PLAFON_BARIS = 8;

    /**
     * Plafon gabungan baris tingkat atas + anak grup yang paling banyak
     * dibuka sekaligus. Accordion cuma boleh satu grup terbuka (single-open),
     * jadi paling banyak yang pernah tampak bersamaan adalah semua baris
     * tingkat atas ditambah isi SATU grup — bukan jumlah semua anak grup.
     * Angka 15 ≈ 15 baris × 40px ≈ 600px, muat di viewport pabrik ~700px.
     */
    private const PLAFON_TERBUKA = 15;

    private function keys(string $role): array
    {
        return array_column(SidebarMenu::forRole($role), 'key');
    }

    public function test_admin_melihat_delapan_baris_tingkat_atas(): void
    {
        $this->assertSame(
            ['chatbot', 'anomali', 'input', 'produksi', 'quality', 'mtcmesin', 'mtcdies', 'master'],
            $this->keys('admin')
        );
    }

    public function test_tiap_role_tidak_melewati_plafon_baris(): void
    {
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
            $this->assertLessThanOrEqual(
                self::PLAFON_BARIS,
                count(SidebarMenu::forRole($role)),
                "Sidebar role {$role} melewati plafon baris."
            );
        }
    }

    public function test_tiap_role_tidak_melewati_plafon_saat_satu_grup_terbuka(): void
    {
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
            $entries = SidebarMenu::forRole($role);

            $ukuranGrup = array_map(
                fn (array $e) => ($e['type'] ?? 'link') === 'group' ? count($e['items']) : 0,
                $entries
            );

            // max() meledak kalau array kosong — role tanpa grup sama sekali
            // berarti tidak ada anak yang bisa ikut terbuka.
            $maks = $ukuranGrup === [] ? 0 : max($ukuranGrup);

            $this->assertLessThanOrEqual(
                self::PLAFON_TERBUKA,
                count($entries) + $maks,
                "Sidebar role {$role} melewati plafon {baris tingkat atas + grup terbesar terbuka}."
            );
        }
    }

    public function test_grup_kosong_tidak_ikut_dirender(): void
    {
        $this->assertNotContains('mtcdies', $this->keys('staff_produksi'));
        $this->assertNotContains('master', $this->keys('staff_qc'));
        $this->assertNotContains('chatbot', $this->keys('guest'));
    }

    public function test_grup_input_menyusut_sesuai_role(): void
    {
        $grup = collect(SidebarMenu::forRole('staff_qc'))->firstWhere('key', 'input');

        $this->assertSame(['LHP Quality'], array_column($grup['items'], 'label'));
    }

    public function test_manager_melihat_breakdown_tapi_bukan_lhp(): void
    {
        $grup = collect(SidebarMenu::forRole('manager'))->firstWhere('key', 'input');

        $this->assertSame(['Breakdown Mesin', 'Breakdown Dies'], array_column($grup['items'], 'label'));
    }

    public function test_guest_melihat_lhp_tapi_bukan_breakdown(): void
    {
        $grup = collect(SidebarMenu::forRole('guest'))->firstWhere('key', 'input');

        $this->assertSame(['LHP Produksi', 'Dandori', 'LHP Quality'], array_column($grup['items'], 'label'));
    }

    public function test_tiap_item_punya_pola_match_yang_terisi(): void
    {
        // Baca config MENTAH (bukan forRole('admin')) supaya entri yang cuma
        // kelihatan role selain admin (mis. manager-only) tetap tercakup.
        foreach (config('sidebar', []) as $entri) {
            $daftar = ($entri['type'] ?? 'link') === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $this->assertNotEmpty($item['match'], "Item {$item['label']} tidak punya pola match.");
                $this->assertNotEmpty($item['route'], "Item {$item['label']} tidak punya route.");
            }
        }
    }

    public function test_route_tiap_item_benar_benar_terdaftar(): void
    {
        $terdaftar = collect(app('router')->getRoutes())->map->getName()->filter()->all();

        // Idem: config mentah, bukan forRole('admin'), supaya entri yang
        // hanya terlihat role non-admin tidak lolos tanpa dicek.
        foreach (config('sidebar', []) as $entri) {
            $daftar = ($entri['type'] ?? 'link') === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $this->assertContains($item['route'], $terdaftar, "Route {$item['route']} tidak terdaftar.");
            }
        }
    }

    public function test_grup_aktif_diturunkan_dari_halaman_sekarang(): void
    {
        $this->get(route('vcb.ng-total.daftar'));

        $this->assertSame('quality', SidebarMenu::activeGroup('admin'));
    }

    public function test_grup_aktif_null_kalau_halaman_di_luar_menu(): void
    {
        $this->get('/profile');

        $this->assertNull(SidebarMenu::activeGroup('admin'));
    }
}
