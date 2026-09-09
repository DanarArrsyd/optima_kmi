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
            // over = max(0, actual − std), sama seperti DandoriController saat
            // input. Menulis over: 0 dengan std: 0 adalah data yang tak mungkin
            // ada di prod, dan membuat Pareto ter-render bernilai nol tanpa satu
            // assertion pun gagal.
            'actual_menit' => 45.5, 'std_menit' => 0, 'over_menit' => 45.5,
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

        // Rentang ditulis eksplisit di header panel. Label "LAST 24 H" yang
        // di-hardcode dihapus: ia BOHONG begitu rentangnya lebih dari sehari.
        $this->assertStringContainsString('15 JUL 2026', $html);

        // Filter tanggal terisi rentang yang sedang aktif, bukan kosong.
        $this->assertStringContainsString('name="date_from" value="2026-07-15"', $html);
        $this->assertStringContainsString('name="date_to" value="2026-07-15"', $html);

        // DUA tombol scope Pareto, bukan tiga. Lihat lossTimeSemuaScope().
        foreach (['Rentang', 'All Time'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringNotContainsString('This Month', $html);

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

    /**
     * Mode lepas-kunci (layar sempit / pendek) harus mengembalikan panel ke
     * tinggi-dari-konten.
     *
     * Ini test CSS, yang biasanya tidak layak diuji — dikecualikan karena bug
     * persisnya sudah terjadi di prod: blok @media mengembalikan tinggi kotak
     * grafik tapi LUPA .erp-panel-body yang masih flex: 1 1 0. flex-basis 0
     * membuat kontribusi tinggi body ke panel runtuh, panel menyusut sebatas
     * header (~30px), dan seluruh halaman rusak di layar 1366×768 — resolusi PC
     * pabrik yang paling umum.
     *
     * Yang diasersikan cuma keberadaan aturannya, bukan hasil rendernya. Itu
     * tidak membuktikan tampilannya benar, tapi cukup untuk mencegah baris ini
     * hilang lagi tanpa ada yang sadar.
     */
    public function test_mode_lepas_kunci_mengembalikan_panel_ke_tinggi_konten(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->seedPabrik();

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        // Ambil hanya blok @media lepas-kunci, supaya aturan mode terkunci
        // (yang memang flex: 1 1 0) tidak ikut terhitung sebagai lolos.
        $this->assertMatchesRegularExpression('/@media \(max-width: 1100px\)/', $html);

        $awal = strpos($html, '@media (max-width: 1100px)');
        $blok = substr($html, $awal, 2000);

        foreach ([
            '.ov-stack .erp-panel-body { flex: 0 0 auto; }' => 'body panel wajib tinggi-dari-konten',
            '.ov-stack > .erp-panel    { flex: 0 0 auto; }' => 'panel di stack wajib tinggi-dari-konten',
            '.ov-stack .erp-panel { overflow: visible; }' => 'overflow wajib dilepas supaya salah hitung terlihat, bukan tersembunyi',
        ] as $aturan => $alasan) {
            $this->assertStringContainsString($aturan, $blok, $alasan);
        }

        // Ambang 1400px pernah dipakai dan itu menyalakan mode ini di layar
        // 1366px — layout satu-layar tidak pernah terpakai di layar yang
        // paling butuh. Jangan naikkan lagi tanpa menghitung ulang.
        $this->assertStringNotContainsString('max-width: 1400px', $html);
    }

    public function test_oee_dan_apq_masing_masing_kotak_sendiri(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        // Empat kotak terpisah, masing-masing berjudul di .erp-panel-header —
        // bukan satu panel berisi tiga metrik bertumpuk.
        foreach (['OEE', 'Availability', 'Performance', 'Quality'] as $label) {
            $this->assertStringContainsString(
                '<span class="ov-h">'.$label.'</span>',
                $html,
                "Kotak {$label} tidak punya header sendiri."
            );
        }

        // Markup lama tidak boleh kembali. Kalau kelas ini dipakai lagi, itu
        // tanda ketiganya digabung balik ke satu panel.
        //
        // Dicek sebagai ATRIBUT class, bukan sebagai string mentah. Komentar
        // CSS halaman ini menyebut .ov-metric dan .ov-grow untuk menjelaskan
        // kenapa keduanya dihapus, dan komentar di dalam <style> ikut terkirim
        // ke browser — assertStringNotContainsString akan merah karena
        // penjelasannya sendiri. Kesalahan yang sama pernah terjadi pada
        // assertion chatbot.css di test lain di kelas ini.
        foreach (['ov-metric', 'ov-grow'] as $kelas) {
            $this->assertDoesNotMatchRegularExpression(
                '#class="[^"]*'.preg_quote($kelas, '#').'#',
                $html,
                "Kelas {$kelas} dipakai lagi — A/P/Q kemungkinan digabung balik."
            );
        }
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

    /* ═══ Filter tanggal ══════════════════════════════════════════ */

    public function test_default_tanpa_filter_tetap_tanggal_lhp_terakhir(): void
    {
        // Papan di lantai produksi tidak boleh berubah artinya hanya karena
        // filter ditambahkan. Default WAJIB sama seperti sebelum filter ada.
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        $this->assertStringContainsString('name="date_from" value="2026-07-15"', $html);
        $this->assertStringContainsString('15 JUL 2026', $html);
        // Reset tidak ditampilkan saat tidak ada yang perlu direset.
        $this->assertStringNotContainsString('>Reset<', $html);
    }

    public function test_filter_rentang_mengubah_label_dan_menampilkan_reset(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('overview', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31']))
            ->assertOk()->getContent();

        // Rentang dalam satu bulan diringkas: "01–31 JUL 2026".
        $this->assertStringContainsString('01–31 JUL 2026', $html);
        $this->assertStringContainsString('>Reset<', $html);
    }

    public function test_tanggal_terbalik_ditukar_bukan_ditolak(): void
    {
        // Salah urutan itu salah ketik. Menolaknya dengan error hanya menghalangi
        // hal yang jelas maksudnya.
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('overview', ['date_from' => '2026-07-31', 'date_to' => '2026-07-01']))
            ->assertOk()
            ->assertSee('01–31 JUL 2026', false);
    }

    public function test_tanggal_rusak_jatuh_ke_default_bukan_error(): void
    {
        /*
         * Halaman ini dibuka guest dan dipajang di lantai produksi. Satu query
         * string rusak tidak boleh mematikan papannya — yang salah diam-diam
         * jatuh ke default, dan default-nya selalu masuk akal.
         */
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        foreach (['bukan-tanggal', '2026-13-45', '', '2026/07/15'] as $rusak) {
            $this->actingAs($admin)
                ->get(route('overview', ['date_from' => $rusak]))
                ->assertOk()
                ->assertSee('15 JUL 2026', false);
        }
    }

    public function test_filter_ikut_dipakai_pareto_bukan_rentang_terpisah(): void
    {
        // Panel Pareto memakai rentang HALAMAN, bukan rentangnya sendiri. Dulu
        // tombolnya menetapkan rentang terpisah, sehingga satu panel bisa
        // menampilkan periode berbeda dari tujuh panel lain di halaman yang sama.
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)
            ->get(route('overview', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31']))
            ->assertOk()->getContent();

        /*
         * @json() meng-escape en-dash jadi \u2013, jadi payload JS berisi
         * "01\u201331 JUL 2026" — benar di browser, tapi tidak cocok kalau
         * dicari sebagai en-dash literal. Yang diperiksa: kunci scope-nya ada
         * dan berisi bulan yang diminta.
         */
        $this->assertMatchesRegularExpression('/rentang: "01\\\\u201331 JUL 2026"/', $html);
    }

    public function test_payload_tren_berisi_enam_titik(): void
    {
        $this->seedPabrik();
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('overview'))->assertOk()->getContent();

        /*
         * Enam HARI KERJA mundur dari Rabu 15 Jul:
         *   09 Kam · 10 Jum · 11 Sab · [12 MINGGU dilangkahi] · 13 Sen · 14 Sel · 15 Rab
         *
         * Karena itu 09-Jul ikut dan 12-Jul TIDAK. Nama harinya ditulis supaya
         * kalau assertion ini gagal, penyebabnya langsung terbaca.
         */
        foreach (['09-Jul', '10-Jul', '11-Jul', '13-Jul', '14-Jul', '15-Jul'] as $label) {
            $this->assertStringContainsString($label, $html, "Titik tren {$label} hilang.");
        }
        $this->assertStringNotContainsString('12-Jul', $html, 'Minggu tidak boleh muncul di tren.');
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

        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'staff_repair', 'guest'] as $role) {
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
