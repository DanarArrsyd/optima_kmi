<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use App\Support\SidebarMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar terender: tepat satu entri menyala, per halaman, per role.
 *
 * Ini yang menutup kelas bug "dua menu nyala bersamaan" untuk SELURUH
 * menu — bukan cuma empat entri NG yang kebetulan pernah ketahuan
 * (lihat NgListSidebarTest).
 */
class SidebarNavTest extends TestCase
{
    use RefreshDatabase;

    /** Role nyata sistem — dipakai untuk iterasi, bukan role 'group' semu apa pun. */
    private const ROLES = ['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'];

    /**
     * Substitusi route: nama route di config/sidebar.php → nama route lain
     * yang dites SEBAGAI GANTINYA. Dipakai kalau route asli tidak bisa
     * dibuka di lingkungan test. Sengaja berupa peta eksplisit (bukan
     * di-skip diam-diam) supaya tiap pengecualian kelihatan di kode.
     * Catatan: entry yang menunjuk ke route **berparameter** juga WAJIB
     * di-substitusi di sini, atau route($namaRoute) akan throw UrlGenerationException.
     */
    private const SUBSTITUSI_ROUTE = [
        // Kosong. Dulu 'master.part.index' => 'master.part.create' di sini
        // karena SphRecommendationService memakai fungsi MySQL-only dan 500
        // di SQLite. Sudah diperbaiki (lihat SphRecommendationServiceTest),
        // jadi halaman aslinya dites langsung lagi.
    ];

    /**
     * Halaman tanpa parameter yang bisa dibuka sebuah role, DITURUNKAN dari
     * config/sidebar.php lewat SidebarMenu::forRole() — bukan ditranskrip
     * tangan. Entri baru di config otomatis ikut tercakup di sini.
     */
    private function halaman(string $role): array
    {
        $routes = [];

        foreach (SidebarMenu::forRoles([$role]) as $entri) {
            $daftar = ($entri['type'] ?? 'link') === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $namaRoute = self::SUBSTITUSI_ROUTE[$item['route']] ?? $item['route'];
                $routes[] = $namaRoute;
            }
        }

        return $routes;
    }

    /** Potong hanya bagian <nav id="sidebar"> supaya tidak ikut menghitung isi halaman. */
    private function sidebar(string $html): string
    {
        preg_match('/<nav id="sidebar">(.*?)<\/nav>/s', $html, $cocok);

        return $cocok[1] ?? '';
    }

    private function jumlahAktif(string $html): int
    {
        return preg_match_all('/class="nav-item-link\s+active\s*"/', $this->sidebar($html));
    }

    public function test_tepat_satu_entri_menyala_di_tiap_halaman(): void
    {
        foreach (self::ROLES as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($this->halaman($role) as $namaRoute) {
                $html = $this->actingAs($user)->get(route($namaRoute))->assertOk()->getContent();

                $this->assertSame(
                    1,
                    $this->jumlahAktif($html),
                    "Role {$role} di {$namaRoute}: jumlah entri aktif harus tepat 1."
                );
            }
        }
    }

    public function test_grup_yang_memuat_halaman_ikut_terbuka(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.ng-total.daftar'))
            ->assertOk()
            ->assertSee("navGroups('quality')", false);

        $this->actingAs($admin)->get(route('maintenance.mttr-dies'))
            ->assertOk()
            ->assertSee("navGroups('mtcdies')", false);

        $this->actingAs($admin)->get(route('master.users.index'))
            ->assertOk()
            ->assertSee("navGroups('sistem')", false);
    }

    public function test_header_grup_yang_memuat_halaman_ditandai(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('vcb.quality'))->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match_all('/class="nav-group-header\s+has-active"/', $this->sidebar($html)),
            'Tepat satu header grup boleh ditandai has-active.'
        );
    }

    public function test_jumlah_baris_tingkat_atas_tidak_melewati_plafon(): void
    {
        foreach (self::ROLES as $role) {
            $user = User::factory()->create(['role' => $role]);
            $rute = $this->halaman($role)[0];

            $html = $this->sidebar(
                $this->actingAs($user)->get(route($rute))->assertOk()->getContent()
            );

            // Baris yang SELALU tampak = header grup + tautan tunggal.
            // Tautan tunggal berada di luar .nav-group-body, jadi dihitung
            // sebagai selisih total tautan dikurangi tautan di dalam grup.
            $header = preg_match_all('/class="nav-group-header/', $html);
            $isiGrup = 0;
            preg_match_all('/<div class="nav-group-body"(.*?)<\/div>/s', $html, $badan);
            foreach ($badan[1] as $potongan) {
                $isiGrup += preg_match_all('/class="nav-item-link/', $potongan);
            }
            $semuaTautan = preg_match_all('/class="nav-item-link/', $html);

            // Angka ini kembaran PLAFON_BARIS di SidebarMenuTest — di sana ada
            // hitungan tingginya. Naik 8 → 9 saat Overview jadi menu sendiri.
            $this->assertLessThanOrEqual(
                9,
                $header + ($semuaTautan - $isiGrup),
                "Sidebar role {$role} melewati plafon 9 baris tingkat atas."
            );
        }
    }

    public function test_saringan_role_tetap_utuh(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);
        // Scope ke sidebar saja — di awal, komentar CSS di layout.app berisi
        // string 'MTC Dies' dan meledakkan test ini, padahal tidak berhubungan
        // dengan role filtering. Sekarang scope terbatas di sidebar untuk
        // hindari false positive dari isi halaman lain.
        $html = $this->sidebar(
            $this->actingAs($qc)->get(route('vcb.quality'))->assertOk()->getContent()
        );

        $this->assertStringNotContainsString('href="'.route('master.users.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('maintenance.breakdown.index').'"', $html);
        $this->assertStringNotContainsString('href="'.route('chatbot.index').'"', $html);
        $this->assertStringNotContainsString('MTC Dies', $html);
    }

    public function test_label_section_lama_sudah_tidak_dirender(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->sidebar(
            $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent()
        );

        $this->assertStringNotContainsString('nav-section-label', $html);
    }

    public function test_semua_href_menu_benar_benar_dirender(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->sidebar(
            $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent()
        );

        foreach (SidebarMenu::forRoles(['admin']) as $entri) {
            $daftar = ($entri['type'] ?? 'link') === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                // Tanda kutip penutup WAJIB ikut dicocokkan — tanpa itu
                // 'vcb/ng-proses' lolos hanya karena jadi prefiks
                // 'vcb/ng-proses/daftar' (begitu juga vcb.ng-total,
                // maintenance.mttr/mttr-dies, mtbf/mtbf-dies,
                // availability/availability-dies, breakdown/breakdown-dies).
                $this->assertStringContainsString(
                    'href="'.route($item['route']).'"',
                    $html,
                    "Tautan {$item['label']} hilang dari sidebar."
                );
            }
        }
    }
}
