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

    /** Halaman tanpa parameter yang bisa dibuka tiap role. */
    private const HALAMAN = [
        'admin' => [
            'chatbot.index', 'admin.anomali',
            'input.produksi.index', 'input.dandori.index', 'input.qc.index',
            'maintenance.breakdown.index', 'maintenance.breakdown-dies.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
            'master.karyawan.index', 'master.mesin.index',
            // 'master.part.index' sengaja TIDAK dipakai: controllernya manggil
            // SphRecommendationService, yang isi query mentahnya pakai fungsi
            // MySQL-only (GREATEST dkk) dan 500 di SQLite (driver test suite).
            // Bug lama, tak berkaitan sama sidebar. 'master.part.create' dipakai
            // gantinya — sama-sama cocok pola 'master.part.*' di menu, dan
            // controller create() tidak menyentuh sphSvc sama sekali.
            'master.part.create',
            'master.losstime.index', 'master.shift.index', 'master.jenis-ng.index',
            'master.users.index',
        ],
        'manager' => [
            'chatbot.index',
            'maintenance.breakdown.index', 'maintenance.breakdown-dies.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
        ],
        'staff_produksi' => [
            'input.produksi.index', 'input.dandori.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
        ],
        'staff_qc' => [
            'input.qc.index',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
        ],
        'staff_maintenance' => [
            'maintenance.breakdown.index', 'maintenance.breakdown-dies.index',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
        ],
        'guest' => [
            'input.produksi.index', 'input.dandori.index', 'input.qc.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
        ],
    ];

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
        foreach (self::HALAMAN as $role => $daftarRoute) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($daftarRoute as $namaRoute) {
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
            ->assertSee("navGroups('master')", false);
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
        foreach (array_keys(self::HALAMAN) as $role) {
            $user = User::factory()->create(['role' => $role]);
            $rute = self::HALAMAN[$role][0];

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

            $this->assertLessThanOrEqual(
                8,
                $header + ($semuaTautan - $isiGrup),
                "Sidebar role {$role} melewati plafon 8 baris tingkat atas."
            );
        }
    }

    public function test_saringan_role_tetap_utuh(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);
        $response = $this->actingAs($qc)->get(route('vcb.quality'))->assertOk();

        $response->assertDontSee(route('master.users.index'));
        $response->assertDontSee(route('maintenance.breakdown.index'));
        $response->assertDontSee(route('chatbot.index'));
        $response->assertDontSee('MTC Dies');
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
        $html = $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent();

        foreach (SidebarMenu::forRole('admin') as $entri) {
            $daftar = $entri['type'] === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $this->assertStringContainsString(
                    route($item['route']),
                    $html,
                    "Tautan {$item['label']} hilang dari sidebar."
                );
            }
        }
    }
}
