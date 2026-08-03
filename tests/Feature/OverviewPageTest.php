<?php

namespace Tests\Feature;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman /overview benar-benar terender BERISI, bukan cuma balas 200.
 *
 * Test sidebar (SidebarNavTest) juga membuka route ini untuk tiap role, tapi
 * DB-nya kosong di sana — jadi yang selalu kena cuma cabang "belum ada LHP".
 * Tanpa test di bawah, seluruh markup dashboard-nya (canvas, payload JSON,
 * tombol scope) punya NOL cakupan dan bisa rusak tanpa satu test pun merah.
 */
class OverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedPabrik(): void
    {
        $part = MasterPart::create(['part_no' => 'PT-OVP', 'part_name' => 'X', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => 400, 'sph_standar_qc' => 200,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-OVP', 'nama_mesin' => 'Press', 'line' => '1',
            'tipe_mesin' => 'BLANKING', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => '2026-01-01',
        ]);
        $kar = MasterKaryawan::create([
            'nik' => 'K-OVP', 'nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $user = User::factory()->create();

        foreach (['Shift 1' => ['06:00', '14:00'], 'Shift 2' => ['14:00', '22:00']] as $nama => [$s, $f]) {
            $shift = MasterShift::create(['nama_shift' => $nama, 'jam_start' => $s, 'jam_finish' => $f]);

            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-07-15', 'id_shift' => $shift->id,
                'id_karyawan' => $kar->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                'jam_start' => $s, 'jam_finish' => $f,
                'qty_aktual' => 3000, 'qty_ng' => 200,
                'created_by' => $user->id,
            ]);
        }

        $lt = MasterLosstime::create([
            'kode' => '4', 'jenis_losstime' => 'Tunggu_Dies',
            'std_menit_default' => null, 'std_menit_progressive' => null,
        ]);
        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-15',
            'id_shift' => MasterShift::first()->id,
            'id_karyawan' => $kar->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'id_losstime' => $lt->id,
            'actual_menit' => 45.5, 'std_menit' => 0, 'over_menit' => 0,
            'created_by' => $user->id,
        ]);
    }

    public function test_halaman_kosong_mengatakan_belum_ada_lhp(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('overview'))
            ->assertOk()
            // Grafik nol akan terbaca sebagai pabrik berhenti; halaman harus
            // MENGATAKAN datanya belum ada.
            ->assertSee('Belum ada LHP produksi', false)
            ->assertDontSee('ovDonutTotal', false);
    }

    public function test_dashboard_terender_lengkap_saat_ada_data(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        // Empat canvas baris 1: donut total + tren achievement + dua donut shift.
        foreach (['ovDonutTotal', 'ovLineAch', 'ovDonutShift0', 'ovDonutShift1'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "Canvas {$id} hilang.");
        }

        // Baris 2: tren OEE + Pareto.
        $this->assertStringContainsString('id="ovLineOee"', $html);
        $this->assertStringContainsString('id="ovPareto"', $html);

        // Tanggal acuan ditulis eksplisit — tanpa ini "LAST 24 H" tak bisa
        // diverifikasi mata: LHP sering masuk besoknya.
        $this->assertStringContainsString('15 Jul 2026', $html);

        // Tiga tombol scope Pareto.
        foreach (['Last 24 H', 'This Month', 'All Time'] as $label) {
            $this->assertStringContainsString($label, $html);
        }

        // css/chatbot.css TIDAK boleh ikut TERMUAT — dia menyetel #main jadi
        // kanvas penuh tanpa padding dan merusak grid halaman ini.
        //
        // Yang dicek tag <link>-nya, bukan sekadar ada-tidaknya string
        // "chatbot.css" di respons. Versi lama memakai
        // assertStringNotContainsString dan langsung merah begitu komentar CSS
        // halaman ini menyebut nama berkas itu untuk MENJELASKAN kenapa ia
        // dihindari — komentar di dalam <style> ikut terkirim ke browser.
        // Assertion yang menghukum penjelasannya sendiri bikin orang menghapus
        // komentarnya, bukan memperbaiki kodenya.
        $this->assertDoesNotMatchRegularExpression('#<link[^>]+chatbot\.css#', $html);
    }

    public function test_warna_donut_ikut_nilai_dan_konsisten_dengan_legenda(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        // Seed: 2 shift × (8 jam × sph 400) = target 6400, aktual 6000 → 93,75%
        // → di atas AMBANG_HIJAU, jadi hijau penuh.
        $this->assertStringContainsString('#15803d', $html);

        // Kuning hardcoded lama TIDAK boleh ada lagi. Kalau ia kembali, itu
        // tanda seseorang mengembalikan warna tetap dan cincin donut berhenti
        // memberi tahu apa pun soal nilainya.
        $this->assertStringNotContainsString('#facc15', $html);

        // Kotak legenda dan dataset donut memakai nilai yang SAMA. Keduanya
        // dihitung dari OverviewService::warnaAchievement(), bukan dua sumber
        // terpisah yang bisa melenceng.
        $this->assertStringContainsString('warnaAch', $html);
        $this->assertStringContainsString('background:#15803d', $html);
    }

    public function test_payload_tren_berisi_enam_titik(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        // Enam label tanggal mundur dari 15 Jul.
        foreach (['10-Jul', '11-Jul', '12-Jul', '13-Jul', '14-Jul', '15-Jul'] as $label) {
            $this->assertStringContainsString($label, $html, "Titik tren {$label} hilang.");
        }
    }

    public function test_loss_time_underscore_jadi_spasi_di_payload(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        $this->assertStringContainsString('Tunggu Dies', $html);
        $this->assertStringNotContainsString('Tunggu_Dies', $html);
    }

    public function test_semua_role_termasuk_guest_bisa_membuka(): void
    {
        $this->seedPabrik();

        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)->get(route('overview'))
                ->assertOk()
                ->assertSee('ovPareto', false);
        }
    }

    public function test_guest_tidak_dapat_tombol_export_apa_pun(): void
    {
        $this->seedPabrik();
        $guest = User::factory()->create(['role' => 'guest']);

        $html = $this->actingAs($guest)->get(route('overview'))->assertOk()->getContent();

        // Halaman ini memang belum punya export sama sekali. Assertion-nya
        // dipasang supaya kalau kelak ditambah, penambahnya sadar guest juga
        // kena — bukan ketahuan setelah live.
        $this->assertStringNotContainsString('Export', $html);
    }
}
