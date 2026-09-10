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
    /**
     * Batas yang membuat sidebar tidak pernah perlu di-scroll.
     *
     * Naik 8 → 9 saat Overview jadi menu berdiri sendiri (bukan sub-menu AI
     * Assistant). Lihat hitungan di PLAFON_TERBUKA: 9 masih muat. Yang KESEPULUH
     * tidak — di situ sidebar beneran ke-scroll di layar pabrik, bukan cuma
     * test merah. Jangan naikkan angka ini lagi tanpa menghitung ulang.
     */
    private const PLAFON_BARIS = 9;

    /**
     * Plafon gabungan baris tingkat atas + anak grup yang paling banyak
     * dibuka sekaligus, untuk grup SELAIN Master Data. Accordion cuma boleh
     * satu grup terbuka (single-open), jadi paling banyak yang pernah
     * tampak bersamaan adalah semua baris tingkat atas ditambah isi SATU
     * grup — bukan jumlah semua anak grup.
     *
     * Angka dasar dihitung dari tinggi NYATA, bukan taksiran. Versi lama
     * menulis "15 baris × 40px ≈ 600px" dan itu keliru: anak grup tingginya
     * 36px (layouts/app.blade.php: .nav-group-body .nav-item-link), bukan
     * 40px. Rationale ini berlaku untuk grup layar sentuh pabrik (produksi,
     * quality, mtcmesin, mtcdies, repair, dst) — sidebar TIDAK BOLEH scroll
     * di sana, karena itu plafon ini dijaga (test ini), bukan sekadar
     * dicatat.
     *
     * Grup Master Data TIDAK dijaga oleh konstanta ini — dia admin-only,
     * dibuka lewat mouse/keyboard di kantor, dan punya plafonnya sendiri:
     * lihat PLAFON_TERBUKA_MASTER. Test WAJIB mengecualikan grup master
     * saat menghitung $maks di sini, supaya pengecualian Master Data tidak
     * ikut mengendurkan plafon grup layar sentuh yang lain.
     */
    private const PLAFON_TERBUKA = 16;

    /**
     * Plafon gabungan baris tingkat atas + anak grup Master Data SAJA.
     *
     * PENGECUALIAN (naik dari 16 ke 17, Task 3 — Proses Repair Produksi):
     * grup Master Data admin-only, dibuka lewat mouse/keyboard di kantor —
     * BUKAN alur layar sentuh pabrik yang jadi alasan PLAFON_TERBUKA ada.
     * Anak kedelapan Master Data (Proses Repair Produksi) sengaja diizinkan
     * scroll sedikit di grup INI SAJA, sebagai tukar-tambah yang disetujui
     * pemilik proyek. Grup lain tetap wajib muat tanpa scroll di bawah
     * PLAFON_TERBUKA (16) — konstanta ini TIDAK berlaku untuk grup lain.
     *
     * Kasus terburuk sekarang = 9 baris atas + 8 anak Master Data:
     *   9 × 40px + 8 × 36px + 14px padding .nav-section = 662px
     * Ruang tersedia di viewport pabrik ~700px = 700 − 46px topbar = 654px.
     * 662px > 654px — MELEWATI budget no-scroll SECARA SENGAJA: Master Data
     * akan scroll sedikit, semua grup lain masih muat tanpa scroll.
     *
     * JANGAN naikkan angka ini lagi tanpa menghitung ulang pixel di atas.
     * Kenaikan ini SATU KALI, dijustifikasi eksplisit untuk kasus Master
     * Data — bukan preseden buat menambah anak grup lain dengan santai.
     */
    private const PLAFON_TERBUKA_MASTER = 17;

    private function keys(string $role): array
    {
        return array_column(SidebarMenu::forRoles([$role]), 'key');
    }

    public function test_urutan_dan_kunci_tingkat_atas_admin(): void
    {
        // Urutan disengaja: pekerjaan harian di atas, administrasi di bawah.
        $this->assertSame(
            ['overview', 'chatbot', 'produksi', 'quality', 'mtcmesin', 'mtcdies', 'repair', 'master', 'sistem'],
            $this->keys('admin')
        );
    }

    public function test_tiap_role_tidak_melewati_plafon_baris(): void
    {
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'] as $role) {
            $this->assertLessThanOrEqual(
                self::PLAFON_BARIS,
                count(SidebarMenu::forRoles([$role])),
                "Sidebar role {$role} melewati plafon baris."
            );
        }
    }

    public function test_tiap_role_tidak_melewati_plafon_saat_satu_grup_terbuka(): void
    {
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'] as $role) {
            $entries = SidebarMenu::forRoles([$role]);

            // Grup master dikecualikan dari perhitungan umum ini — dia
            // punya plafon sendiri (PLAFON_TERBUKA_MASTER) yang dicek
            // terpisah di bawah, supaya pengecualian Master Data tidak
            // ikut mengendurkan plafon grup layar sentuh pabrik yang lain.
            $ukuranGrup = array_map(
                fn (array $e) => ($e['type'] ?? 'link') === 'group' && $e['key'] !== 'master' ? count($e['items']) : 0,
                $entries
            );

            // max() meledak kalau array kosong — role tanpa grup sama sekali
            // berarti tidak ada anak yang bisa ikut terbuka.
            $maks = $ukuranGrup === [] ? 0 : max($ukuranGrup);

            $this->assertLessThanOrEqual(
                self::PLAFON_TERBUKA,
                count($entries) + $maks,
                "Sidebar role {$role} melewati plafon {baris tingkat atas + grup terbesar terbuka} (selain master)."
            );

            $master = collect($entries)->firstWhere('key', 'master');

            if ($master !== null) {
                $this->assertLessThanOrEqual(
                    self::PLAFON_TERBUKA_MASTER,
                    count($entries) + count($master['items']),
                    "Sidebar role {$role} melewati plafon khusus grup Master Data."
                );
            }
        }
    }

    public function test_grup_kosong_tidak_ikut_dirender(): void
    {
        $this->assertNotContains('mtcdies', $this->keys('staff_produksi'));
        $this->assertNotContains('master', $this->keys('staff_qc'));
        $this->assertNotContains('chatbot', $this->keys('guest'));
    }

    public function test_grup_quality_menyusut_untuk_manager(): void
    {
        // staff_qc dan guest sama-sama melihat keempat anak grup quality, jadi
        // keduanya tidak membuktikan grup ini bisa menyusut. manager satu-
        // satunya role yang benar-benar kehilangan anak: dia memantau VCB
        // tapi tidak menginput LHP Quality.
        $grup = collect(SidebarMenu::forRoles(['manager']))->firstWhere('key', 'quality');

        $this->assertSame(
            ['VCB Quality', 'VCB NG Total', 'Daftar NG Total'],
            array_column($grup['items'], 'label')
        );
        $this->assertNotContains('LHP Quality', array_column($grup['items'], 'label'));
    }

    public function test_manager_melihat_breakdown_tapi_bukan_lhp(): void
    {
        // manager tidak menginput LHP, tapi memantau breakdown. Sesudah penataan
        // ulang keduanya ada di grup domain yang sama, jadi penyaringan per-anak
        // inilah yang menjaga manager tidak kebagian halaman input.
        $grup = collect(SidebarMenu::forRoles(['manager']))->firstWhere('key', 'mtcmesin');

        $this->assertSame(
            ['Breakdown Mesin', 'MTTR', 'MTBF', 'Availability', 'Control Stroke'],
            array_column($grup['items'], 'label')
        );

        $produksi = collect(SidebarMenu::forRoles(['manager']))->firstWhere('key', 'produksi');

        $this->assertNotContains('LHP Produksi', array_column($produksi['items'], 'label'));
    }

    public function test_guest_melihat_lhp_tapi_bukan_breakdown(): void
    {
        $produksi = collect(SidebarMenu::forRoles(['guest']))->firstWhere('key', 'produksi');
        $mtc = collect(SidebarMenu::forRoles(['guest']))->firstWhere('key', 'mtcmesin');

        $this->assertContains('LHP Produksi', array_column($produksi['items'], 'label'));
        $this->assertNotContains('Breakdown Mesin', array_column($mtc['items'], 'label'));
    }

    public function test_grup_sistem_hanya_untuk_admin(): void
    {
        // Satu arsip backup memuat seluruh database; grup ini tidak boleh bocor
        // ke peran lain lewat menu, sekalipun route-nya sudah dijaga role:admin.
        $this->assertContains('sistem', $this->keys('admin'));

        foreach (['manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'] as $role) {
            $this->assertNotContains('sistem', $this->keys($role), "Grup sistem bocor ke {$role}.");
        }
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

        $this->assertSame('quality', SidebarMenu::activeGroup(['admin']));
    }

    public function test_grup_aktif_null_kalau_halaman_di_luar_menu(): void
    {
        $this->get('/profile');

        $this->assertNull(SidebarMenu::activeGroup(['admin']));
    }

    public function test_tidak_ada_route_yang_muncul_dua_kali(): void
    {
        // Dibaca dari config MENTAH, bukan forRole('admin'), supaya entri yang
        // hanya terlihat peran lain ikut tercakup.
        $routes = [];

        foreach (config('sidebar') as $entri) {
            if (($entri['type'] ?? 'link') === 'link') {
                $routes[] = $entri['route'];

                continue;
            }
            foreach ($entri['items'] as $anak) {
                $routes[] = $anak['route'];
            }
        }

        $ganda = array_keys(array_filter(array_count_values($routes), fn ($n) => $n > 1));

        $this->assertSame([], $ganda, 'Route muncul lebih dari sekali di sidebar: '.implode(', ', $ganda));
    }
}
