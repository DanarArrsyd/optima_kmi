<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\OverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Angka halaman Overview.
 *
 * Yang dijaga di sini bukan cuma "rumusnya benar", tapi tiga kelas bug yang
 * sudah pernah kejadian di repo ini:
 *   1. Agregasi pabrik yang memakai rata-rata polos, bukan pooled.
 *   2. Metrik yang mengembalikan 0 padahal maksudnya "tak bisa dihitung".
 *   3. SQL mentah khusus MySQL — lossTime() dites langsung supaya kalau ada
 *      fungsi yang tak dikenal SQLite, gagalnya di sini, bukan di prod.
 */
class OverviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private function svc(): OverviewService
    {
        return new OverviewService;
    }

    private function user(): User
    {
        return User::factory()->create();
    }

    /** @return array{mesin: MasterMesin, proses: MasterPartProses} */
    private function scaffold(int $sph = 400, string $install = '2026-01-01', string $kode = 'M-OV'): array
    {
        $part = MasterPart::firstOrCreate(
            ['part_no' => 'PT-OV'],
            ['part_name' => 'X', 'customer' => 'Toyota']
        );
        $proses = MasterPartProses::firstOrCreate(
            ['id_part' => $part->id, 'id_proses' => 1],
            ['nama_proses' => 'BLANKING', 'sph_standar_produksi' => $sph, 'sph_standar_qc' => 200]
        );
        $mesin = MasterMesin::create([
            'id_mesin' => $kode, 'nama_mesin' => 'Press', 'line' => '1',
            'tipe_mesin' => 'BLANKING', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $install,
        ]);

        return ['mesin' => $mesin, 'proses' => $proses];
    }

    private function shift(string $nama, string $start = '08:00', string $finish = '16:00'): MasterShift
    {
        return MasterShift::firstOrCreate(
            ['nama_shift' => $nama],
            ['jam_start' => $start, 'jam_finish' => $finish]
        );
    }

    private function lhp(
        int $mesinId,
        int $prosesId,
        int $qty,
        int $ng = 0,
        string $tgl = '2026-07-15',
        string $shift = 'Shift 1',
        string $start = '08:00',
        string $finish = '16:00',
    ): void {
        $kar = MasterKaryawan::firstOrCreate(
            ['nik' => 'K-OV'],
            ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]
        );

        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl,
            'id_shift' => $this->shift($shift, $start, $finish)->id,
            'id_karyawan' => $kar->id,
            'id_mesin' => $mesinId,
            'id_part_proses' => $prosesId,
            'jam_start' => $start, 'jam_finish' => $finish,
            'qty_aktual' => $qty, 'qty_ng' => $ng,
            'created_by' => $this->user()->id,
        ]);
    }

    private function dandori(string $jenis, float $menit, string $tgl, int $mesinId, int $prosesId): void
    {
        $lt = MasterLosstime::firstOrCreate(
            ['jenis_losstime' => $jenis],
            ['kode' => substr(md5($jenis), 0, 3), 'std_menit_default' => null, 'std_menit_progressive' => null]
        );
        $kar = MasterKaryawan::firstOrCreate(
            ['nik' => 'K-OV'],
            ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]
        );

        TransaksiDandoriRincian::create([
            'tanggal' => $tgl,
            'id_shift' => $this->shift('Shift 1')->id,
            'id_karyawan' => $kar->id,
            'id_mesin' => $mesinId,
            'id_part_proses' => $prosesId,
            'id_losstime' => $lt->id,
            'actual_menit' => $menit,
            'std_menit' => 0,
            /*
             * over = max(0, actual − std), sama seperti yang dihitung
             * DandoriController saat input. Versi lama menulis 0 mati-matian
             * padahal std-nya juga 0 — data yang tak mungkin ada di prod, dan
             * ia menyembunyikan bahwa Pareto berpindah ke kolom over: seluruh
             * assertion tetap hijau sampai kolomnya benar-benar dipakai.
             * Fixture yang tidak mungkin terjadi = test yang tidak menguji apa pun.
             */
            'over_menit' => $menit,
            'created_by' => $this->user()->id,
        ]);
    }

    private function tgl(string $s): Carbon
    {
        return Carbon::parse($s)->startOfDay();
    }

    /* ═══ Tanggal acuan ═══════════════════════════════════════════ */

    public function test_tanggal_acuan_adalah_lhp_produksi_terbaru(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold();
        $this->lhp($m->id, $p->id, qty: 100, tgl: '2026-07-10');
        $this->lhp($m->id, $p->id, qty: 100, tgl: '2026-07-18');
        $this->lhp($m->id, $p->id, qty: 100, tgl: '2026-07-14');

        $this->assertSame('2026-07-18', $this->svc()->tanggalAcuan()->toDateString());
    }

    public function test_tanggal_acuan_null_kalau_belum_ada_lhp(): void
    {
        $this->assertNull($this->svc()->tanggalAcuan());
    }

    /* ═══ Achievement ═════════════════════════════════════════════ */

    public function test_achievement_di_pool_bukan_dirata_rata_antar_mesin(): void
    {
        // Mesin A: 8 jam × sph 400 = target 3200, aktual 3200 → 100%
        // Mesin B: 1 jam × sph 400 = target 400,  aktual 100  → 25%
        // Rata-rata polos = 62,5%. Pooled = 3300/3600 = 91,67%.
        // Pooled yang benar: mesin B cuma jalan 1 jam, bobotnya harus kecil.
        ['mesin' => $a, 'proses' => $p] = $this->scaffold(sph: 400, kode: 'M-A');
        $b = $this->scaffold(sph: 400, kode: 'M-B')['mesin'];

        $this->lhp($a->id, $p->id, qty: 3200, tgl: '2026-07-15', start: '08:00', finish: '16:00');
        $this->lhp($b->id, $p->id, qty: 100, tgl: '2026-07-15', start: '08:00', finish: '09:00');

        $r = $this->svc()->achievement($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertSame(91.67, $r['pct']);
        $this->assertSame(3300, $r['qty']);
        $this->assertSame(3600.0, $r['target']);
    }

    public function test_achievement_null_bukan_nol_kalau_sph_standar_belum_diset(): void
    {
        // Target nol berarti "tidak ada yang bisa dibandingkan", BUKAN gagal
        // total. 0% membuat donut terlihat seperti pabrik berhenti.
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 0);
        $this->lhp($m->id, $p->id, qty: 500, tgl: '2026-07-15');

        $r = $this->svc()->achievement($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertNull($r['pct']);
        $this->assertSame(500, $r['qty']);
    }

    /* ═══ Achievement per shift ═══════════════════════════════════ */

    public function test_shift_3_tidak_dipecah_jadi_donut_tapi_tetap_masuk_total(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);

        // Shift 1: target 3200, aktual 1600 → 50%
        $this->lhp($m->id, $p->id, qty: 1600, tgl: '2026-07-15', shift: 'Shift 1', start: '06:00', finish: '14:00');
        // Shift 2: target 3200, aktual 3200 → 100%
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-07-15', shift: 'Shift 2', start: '14:00', finish: '22:00');
        // Shift 3: target 3200, aktual 800 → 25%. Tidak punya donut sendiri.
        $this->lhp($m->id, $p->id, qty: 800, tgl: '2026-07-15', shift: 'Shift 3', start: '22:00', finish: '06:00');

        $perShift = $this->svc()->achievementPerShift($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertSame(['Shift 1', 'Shift 2'], array_column($perShift, 'nama'));
        $this->assertSame([50.0, 100.0], array_column($perShift, 'pct'));

        // Total pabrik memuat KETIGA shift: 5600 / 9600 = 58,33%.
        // Kalau Shift 3 bocor keluar dari total, angkanya jadi 4800/6400 = 75%.
        $total = $this->svc()->achievement($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));
        $this->assertSame(58.33, $total['pct']);
    }

    public function test_shift_yang_belum_ada_di_master_bernilai_null(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold();
        $this->lhp($m->id, $p->id, qty: 100, tgl: '2026-07-15', shift: 'Shift 1');

        $perShift = $this->svc()->achievementPerShift($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        // Shift 2 tak pernah dibuat di test ini → null, bukan 0%.
        $this->assertNull(collect($perShift)->firstWhere('nama', 'Shift 2')['pct']);
    }

    /* ═══ OEE pabrik ══════════════════════════════════════════════ */

    public function test_performance_dan_quality_pabrik_di_pool_lintas_mesin(): void
    {
        ['mesin' => $a, 'proses' => $p] = $this->scaffold(sph: 400, kode: 'M-A');
        $b = $this->scaffold(sph: 400, kode: 'M-B')['mesin'];

        // Teoretis total = 2 × (8 jam × 400) = 6400.
        // Diproduksi = (3000+200) + (2000+400) = 5600 → performance 87,5%.
        // Baik 5000 dari 5600 → quality 89,29%.
        $this->lhp($a->id, $p->id, qty: 3000, ng: 200, tgl: '2026-07-15');
        $this->lhp($b->id, $p->id, qty: 2000, ng: 400, tgl: '2026-07-15');

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertSame(87.5, $r['performance_pct']);
        $this->assertSame(89.29, $r['quality_pct']);
    }

    public function test_availability_pabrik_ditimbang_jam_jendela_bukan_dirata_rata(): void
    {
        /*
         * Rentang 15–17 Jul 2026 = Rab, Kam, Jum — tiga hari kerja, 8 jam each.
         *
         * Mesin lama : instalasi lama  → jendela 3 hari = 24 jam, downtime 0  → 100%
         * Mesin baru : instalasi 17 Jul → jendela 1 hari =  8 jam, downtime 4 →  50%
         *
         * Rata-rata polos = 75%. Pooled tertimbang = (32 − 4) / 32 = 87,5%.
         * Inilah sebabnya operating_hours dikeluarkan MaintenanceDashboardService.
         */
        // Jam terjadwal WAJIB ada sejak penyebutnya bukan kalender lagi.
        // Shift 1 06:00–14:00 = 8 jam per hari kerja.
        $this->shift('Shift 1');

        $lama = $this->scaffold(install: '2026-01-01', kode: 'M-LAMA')['mesin'];
        $baru = $this->scaffold(install: '2026-07-17', kode: 'M-BARU')['mesin'];

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-OV', 'id_mesin' => $baru->id,
            'tanggal_breakdown' => '2026-07-17', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-17', 'jam_mulai_perbaikan' => '08:00',
            'tanggal_selesai_perbaikan' => '2026-07-17', 'jam_selesai_perbaikan' => '12:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 4.0, 'total_downtime' => 4.0,
        ]);

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-17'));

        // Mesin lama memang tak punya breakdown — disebut eksplisit supaya
        // jelas dia BUKAN yang diabaikan dari penyebut.
        $this->assertNotNull($lama->tanggal_instalasi);
        $this->assertSame(87.5, $r['availability_pct']);
        $this->assertNotSame(75.0, $r['availability_pct']);
    }

    public function test_dandori_ikut_menurunkan_availability_pabrik(): void
    {
        // Integrasi: keputusan "dandori itu kerugian availability" diambil di
        // MaintenanceDashboardService, tapi yang dilihat orang angkanya di
        // halaman Overview. Test ini yang memastikan perubahan itu benar-benar
        // sampai ke sana, bukan berhenti di service maintenance.
        $this->shift('Shift 1');   // 8 jam terjadwal per hari kerja
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(install: '2026-01-01');

        $tanpa = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));
        $this->assertSame(100.0, $tanpa['availability_pct']);

        $this->dandori('Setting_Dies', 360.0, '2026-07-15', $m->id, $p->id);   // 6 jam

        // 8 jam terjadwal − 6 jam dandori = 25%. Dulu 75% dengan penyebut 24 jam.
        $dengan = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));
        $this->assertEqualsWithDelta(25.0, $dengan['availability_pct'], 0.5);
    }

    /* ═══ Dandori dikeluarkan dari penyebut Performance ═══════════ */

    public function test_dandori_dikeluarkan_dari_penyebut_performance(): void
    {
        /*
         * Konvensi: waktu ganti dies MASUK ke jam_start–jam_finish LHP.
         * Kalau setup dibiarkan di penyebut, ia dihukum DUA KALI — menekan
         * Performance DAN Availability.
         *
         * LHP 8 jam, SPH std 400 → teoretis mentah 3200. Diproduksi 3200.
         *   TANPA pengurangan : 3200 / 3200        = 100,00%   (setup dihukum 2x)
         *   DENGAN pengurangan: 3200 / (3200×6/8)  = 133,33%   (setup dihukum 1x)
         */
        $this->shift('Shift 1');
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400, install: '2026-01-01');
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-07-15', start: '06:00', finish: '14:00');
        $this->dandori('Setting_Dies', 120.0, '2026-07-15', $m->id, $p->id);   // 2 jam

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertEqualsWithDelta(133.33, $r['performance_pct'], 0.05);
        $this->assertNotEqualsWithDelta(100.0, $r['performance_pct'], 0.05, 'Setup tidak boleh dihukum dua kali.');
    }

    public function test_dandori_melebihi_jam_lhp_mesin_itu_diabaikan_bukan_dipaksa(): void
    {
        /*
         * Data MUSTAHIL di konvensi "include": dandori tak bisa lebih lama dari
         * jam LHP yang memuatnya. Berarti LHP mesin itu diisi TANPA memasukkan
         * dandori — konvensi lama, tercampur.
         *
         * Test ini SEKALIGUS membuktikan pengurangannya PER MESIN, bukan rasio
         * pabrik. Mesin A mustahil dan dibuang; mesin B utuh.
         *   per mesin    : teoretis = 0 (A dibuang) + 3200 (B) = 3200 → P 106,25%
         *   rasio pabrik : 4000 × (10−5)/10 = 2000              → P 170,00%
         */
        $this->shift('Shift 1');
        ['mesin' => $a, 'proses' => $p] = $this->scaffold(sph: 400, install: '2026-01-01', kode: 'M-A');
        $b = $this->scaffold(sph: 400, install: '2026-01-01', kode: 'M-B')['mesin'];

        // A: LHP 2 jam tapi dandori 5 jam → mustahil.
        $this->lhp($a->id, $p->id, qty: 200, tgl: '2026-07-15', start: '06:00', finish: '08:00');
        $this->dandori('Setting_Dies', 300.0, '2026-07-15', $a->id, $p->id);

        // B: LHP 8 jam, nol dandori.
        $this->lhp($b->id, $p->id, qty: 3200, tgl: '2026-07-15', start: '06:00', finish: '14:00');

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertEqualsWithDelta(106.25, $r['performance_pct'], 0.05);
        $this->assertNotEqualsWithDelta(170.0, $r['performance_pct'], 0.05, 'Bukan rasio pabrik — per mesin.');
        $this->assertNotEmpty(array_filter(
            $r['catatan'],
            fn (string $c) => str_contains($c, 'melebihi jam LHP')
        ));
    }

    public function test_nol_dandori_performance_tidak_bergeser(): void
    {
        // Penjaga arah lain: tanpa dandori, penyebutnya TIDAK boleh berubah.
        $this->shift('Shift 1');
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400, install: '2026-01-01');
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-07-15', start: '06:00', finish: '14:00');

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertEqualsWithDelta(100.0, $r['performance_pct'], 0.05);
    }

    public function test_oee_null_kalau_salah_satu_komponen_tak_terhitung(): void
    {
        // Ada mesin & breakdown (availability bisa), tapi nol LHP produksi →
        // performance & quality null → OEE HARUS null, bukan 0.
        $this->scaffold(install: '2026-01-01');

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertNull($r['oee_pct']);
        $this->assertNull($r['performance_pct']);
        $this->assertNotEmpty($r['catatan']);
    }

    public function test_mesin_tanpa_tanggal_instalasi_dicatat_bukan_didiamkan(): void
    {
        ['mesin' => $ok, 'proses' => $p] = $this->scaffold(install: '2026-01-01', kode: 'M-OK');
        MasterMesin::create([
            'id_mesin' => 'M-NOINST', 'nama_mesin' => 'Y', 'line' => '1',
            'tipe_mesin' => 'BLANKING', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => null,
        ]);
        $this->lhp($ok->id, $p->id, qty: 3200, tgl: '2026-07-15');

        $r = $this->svc()->oee($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertNotNull($r['availability_pct']);
        $this->assertNotEmpty(array_filter(
            $r['catatan'],
            fn (string $c) => str_contains($c, 'tanggal instalasi belum diset')
        ));
    }

    /* ═══ Tren ════════════════════════════════════════════════════ */

    public function test_tren_menghasilkan_enam_titik_mundur_dari_acuan(): void
    {
        config(['hari_libur' => []]);
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-07-15');

        $tren = $this->svc()->tren($this->tgl('2026-07-15'));

        /*
         * Acuan Rabu 2026-07-15. Enam HARI KERJA ke belakang:
         *   09 Kam · 10 Jum · 11 Sab · [12 MINGGU dilangkahi] · 13 Sen · 14 Sel · 15 Rab
         *
         * Titik pertama karena itu 07-09, bukan 07-10 seperti sebelum Minggu
         * dilangkahi. Tanggalnya ditulis eksplisit dengan nama harinya supaya
         * kalau assertion ini gagal, penyebabnya langsung terbaca — bukan
         * bikin orang menghitung kalender sendiri.
         */
        $this->assertCount(OverviewService::HARI_TREN, $tren);
        $this->assertSame('2026-07-09', $tren[0]['tanggal']);
        $this->assertSame('2026-07-15', $tren[5]['tanggal']);
        $this->assertNotContains('2026-07-12', array_column($tren, 'tanggal'));
        $this->assertSame('15-Jul', $tren[5]['label']);

        // Hari tanpa LHP = null, BUKAN 0 — grafik memutus garisnya, dan 0
        // akan terbaca sebagai "produksi nol" padahal cuma tak ada data.
        $this->assertNull($tren[0]['achievement_pct']);
        $this->assertSame(100.0, $tren[5]['achievement_pct']);
    }

    /* ═══ Hari kerja & hari libur ═════════════════════════════════ */

    public function test_minggu_bukan_hari_kerja_tanpa_perlu_didaftarkan(): void
    {
        // 2026-08-02 adalah Minggu. Tidak ada di config/hari_libur.php dan
        // memang TIDAK BOLEH perlu ada — Minggu diturunkan dari tanggalnya.
        config(['hari_libur' => []]);

        $this->assertFalse(OverviewService::hariKerja($this->tgl('2026-08-02')));
        $this->assertTrue(OverviewService::hariKerja($this->tgl('2026-08-03')));   // Senin
        $this->assertTrue(OverviewService::hariKerja($this->tgl('2026-08-01')));   // Sabtu, pabrik jalan
    }

    public function test_tanggal_merah_dari_config_bukan_hari_kerja(): void
    {
        config(['hari_libur' => ['2026-08-17']]);

        $this->assertFalse(OverviewService::hariKerja($this->tgl('2026-08-17')));
        $this->assertTrue(OverviewService::hariKerja($this->tgl('2026-08-18')));
    }

    public function test_tren_melewati_minggu_dan_tetap_enam_titik(): void
    {
        config(['hari_libur' => []]);
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-08-03');

        // Acuan Senin 03-Aug. Enam hari kerja ke belakang HARUS melangkahi
        // Minggu 02-Aug dan Minggu 26-Jul.
        $tren = $this->svc()->tren($this->tgl('2026-08-03'));

        $this->assertCount(6, $tren, 'Jumlah titik tetap 6 walau ada hari yang dilangkahi.');
        $this->assertNotContains('2026-08-02', array_column($tren, 'tanggal'), 'Minggu tidak boleh muncul.');
        $this->assertNotContains('2026-07-26', array_column($tren, 'tanggal'), 'Minggu kedua juga tidak.');
        $this->assertSame('2026-08-03', $tren[5]['tanggal'], 'Titik terakhir tetap tanggal acuan.');

        // Rentang KALENDER-nya melebar melampaui 6 hari — itu konsekuensi yang
        // disengaja, dan alasan label panel menyebut "hari kerja".
        $rentang = $this->tgl($tren[0]['tanggal'])->diffInDays($this->tgl($tren[5]['tanggal']));
        $this->assertGreaterThan(5, $rentang);
    }

    public function test_hari_libur_yang_punya_lhp_tetap_ditampilkan(): void
    {
        // Lembur di hari libur itu nyata. Menyembunyikan hari yang PUNYA data
        // membuat angkanya hilang dari tren tanpa jejak — lebih buruk daripada
        // menampilkan hari libur.
        config(['hari_libur' => ['2026-08-05']]);
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);
        $this->lhp($m->id, $p->id, qty: 1000, tgl: '2026-08-05');
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-08-06');

        $tren = $this->svc()->tren($this->tgl('2026-08-06'));

        $this->assertContains('2026-08-05', array_column($tren, 'tanggal'));
        $this->assertNotNull(
            collect($tren)->firstWhere('tanggal', '2026-08-05')['achievement_pct'],
            'Angka hari lembur harus ikut terhitung, bukan null.'
        );
    }

    public function test_libur_panjang_tidak_membuat_pencarian_menggantung(): void
    {
        // Seluruh jendela jelajah ditandai libur. Yang benar: berhenti di batas
        // dan mengembalikan lebih sedikit titik — bukan loop tanpa ujung.
        $libur = [];
        for ($i = 0; $i <= 40; $i++) {
            $libur[] = $this->tgl('2026-08-06')->subDays($i)->toDateString();
        }
        config(['hari_libur' => $libur]);

        $tren = $this->svc()->tren($this->tgl('2026-08-06'));

        $this->assertSame([], $tren);
    }

    /* ═══ Tren pada rentang ═══════════════════════════════════════ */

    public function test_rentang_satu_hari_diperlebar_jadi_enam_hari_kerja(): void
    {
        /*
         * Aturan ini yang membuat default halaman TIDAK berubah setelah filter
         * ditambahkan: rentang default satu hari, dan satu hari diperlebar mundur
         * jadi HARI_TREN hari kerja. Aturan yang sama berlaku kalau pengguna
         * memilih satu tanggal — jadi tidak ada cabang "sedang difilter atau
         * tidak" yang bisa melenceng.
         */
        config(['hari_libur' => []]);
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-07-15');

        $tren = $this->svc()->tren($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertCount(OverviewService::HARI_TREN, $tren);
        $this->assertSame('2026-07-15', $tren[5]['tanggal']);
    }

    public function test_rentang_pendek_satu_titik_per_hari_kerja(): void
    {
        config(['hari_libur' => []]);
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-08-05');

        // 03 Sen s/d 08 Sab = 6 hari kerja (09 Min di luar rentang).
        $tren = $this->svc()->tren($this->tgl('2026-08-03'), $this->tgl('2026-08-08'));

        $this->assertCount(6, $tren);
        $this->assertSame('2026-08-03', $tren[0]['tanggal']);
        $this->assertSame('2026-08-08', $tren[5]['tanggal']);
    }

    public function test_rentang_panjang_beralih_ke_agregasi_mingguan(): void
    {
        /*
         * Batas 31 titik bukan kosmetik. Tiap titik memanggil achievement() DAN
         * oee(), dan oee() memanggil compute() — sekitar lima query per titik.
         * Enam bulan tanpa batas ini ~130 titik dan ~650 query dalam satu
         * request; grafik 130 titik juga tak terbaca.
         */
        config(['hari_libur' => []]);
        ['mesin' => $m, 'proses' => $p] = $this->scaffold(sph: 400);
        $this->lhp($m->id, $p->id, qty: 3200, tgl: '2026-08-05');

        // Enam bulan: jauh di atas 31 hari kerja.
        $tren = $this->svc()->tren($this->tgl('2026-03-01'), $this->tgl('2026-08-31'));

        $this->assertGreaterThan(OverviewService::MAKS_TITIK_HARIAN, 130);
        $this->assertLessThanOrEqual(
            30,
            count($tren),
            'Rentang panjang harus diagregasi per minggu, bukan per hari.'
        );
        $this->assertNotEmpty($tren);
    }

    public function test_ambang_agregasi_tepat_di_batas(): void
    {
        /*
         * Batas yang tidak diuji adalah batas yang bergeser diam-diam. Dua rentang
         * dipilih supaya mengapit MAKS_TITIK_HARIAN (31), dan jumlah hari kerjanya
         * dihitung dulu bukan ditebak:
         *
         *   2026-08-03 s/d 2026-09-04 = 29 hari kerja → di bawah batas, HARIAN
         *   2026-08-03 s/d 2026-09-08 = 32 hari kerja → di atas batas, MINGGUAN
         *
         * Selisihnya cuma empat hari kalender, jadi kalau ambangnya bergeser
         * sedikit pun salah satu assertion ini gagal.
         */
        config(['hari_libur' => []]);
        $this->scaffold(sph: 400);

        $harian = $this->svc()->tren($this->tgl('2026-08-03'), $this->tgl('2026-09-04'));
        $this->assertCount(29, $harian, 'Di bawah batas: satu titik per hari kerja.');

        $mingguan = $this->svc()->tren($this->tgl('2026-08-03'), $this->tgl('2026-09-08'));
        $this->assertLessThanOrEqual(6, count($mingguan), 'Di atas batas: satu titik per minggu ISO.');
        $this->assertGreaterThan(3, count($mingguan));
    }

    public function test_dua_scope_pareto_bukan_tiga(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold();
        $this->dandori('Tunggu_Dies', 9.0, '2026-07-15', $m->id, $p->id);
        $this->dandori('Tunggu_Dies', 5.0, '2026-06-20', $m->id, $p->id);

        $scope = $this->svc()->lossTimeSemuaScope($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        // 'rentang' mengikuti rentang halaman; 'semua' pertanyaan berbeda
        // (penyumbang terbesar sepanjang masa), bukan rentang berbeda.
        $this->assertSame(['rentang', 'semua'], array_keys($scope));
        $this->assertSame(9.0, $scope['rentang'][0]['menit']);
        $this->assertSame(14.0, $scope['semua'][0]['menit']);
    }

    /* ═══ Loss time (Pareto) ══════════════════════════════════════ */

    /**
     * Gerbang dialek SQL: lossTime() memakai DB::raw (COALESCE + SUM + GROUP BY).
     * Kalau ada fungsi khusus MySQL menyelinap masuk, test ini merah di SQLite —
     * itu justru gunanya. Tanpa test ini halaman Pareto bisa hidup bertahun-tahun
     * dengan nol cakupan.
     */
    public function test_loss_time_terurut_menurun_dan_underscore_jadi_spasi(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold();

        $this->dandori('Tunggu_Dies', 120.5, '2026-07-15', $m->id, $p->id);
        $this->dandori('Tunggu_Dies', 80.0, '2026-07-15', $m->id, $p->id);
        $this->dandori('Setting_Dies', 60.0, '2026-07-15', $m->id, $p->id);
        $this->dandori('Ke_Toilet', 15.0, '2026-07-15', $m->id, $p->id);

        $rows = $this->svc()->lossTime($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertSame(['Tunggu Dies', 'Setting Dies', 'Ke Toilet'], array_column($rows, 'jenis'));
        $this->assertSame([200.5, 60.0, 15.0], array_column($rows, 'menit'));
    }

    public function test_loss_time_all_time_tanpa_batas_tanggal(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold();

        $this->dandori('Tunggu_Dies', 10.0, '2025-01-05', $m->id, $p->id);
        $this->dandori('Tunggu_Dies', 20.0, '2026-07-15', $m->id, $p->id);

        $semua = $this->svc()->lossTime();

        $this->assertSame(30.0, $semua[0]['menit']);
    }

    public function test_loss_time_kosong_mengembalikan_array_kosong(): void
    {
        $this->assertSame([], $this->svc()->lossTime());
    }

    /**
     * Pareto Overview menggrafikkan over_menit, SAMA dengan VCB Dandori, dan
     * tetap membawa actual_menit di kunci 'aktual'.
     *
     * Keduanya wajib ada, dan alasannya bukan kelengkapan data:
     *   - 'menit' (over) yang digrafikkan — keputusan user, supaya kedua halaman
     *     tidak lagi menampilkan angka berbeda untuk hal yang tampak sama.
     *   - 'aktual' dibawa serta karena Availability mengurangi actual_menit
     *     (MaintenanceDashboardService), BUKAN over. Jumlah Pareto ini karena itu
     *     memang tidak sama dengan kerugian yang dipakai OEE di panel sebelah.
     *     Tanpa aktual, selisih itu tak punya tempat untuk dijelaskan dan jadi
     *     kontradiksi senyap di satu halaman.
     *
     * Selisih 90 vs 80 dipilih supaya kolom yang tertukar gagal telak, bukan
     * marginal.
     */
    public function test_loss_time_overview_pakai_over_standar_dan_membawa_aktual(): void
    {
        ['mesin' => $m, 'proses' => $p] = $this->scaffold();

        $lt = MasterLosstime::firstOrCreate(
            ['jenis_losstime' => 'Setting_Dies'],
            ['kode' => 'SD', 'std_menit_default' => 10, 'std_menit_progressive' => 15]
        );
        $kar = MasterKaryawan::firstOrCreate(
            ['nik' => 'K-OV'],
            ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]
        );

        // Aktual 90 menit dengan standar 10 → over 80. Dua angka yang jauh beda,
        // jadi kalau kolomnya tertukar, test ini gagal telak bukan marginal.
        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-15',
            'id_shift' => $this->shift('Shift 1')->id,
            'id_karyawan' => $kar->id,
            'id_mesin' => $m->id,
            'id_part_proses' => $p->id,
            'id_losstime' => $lt->id,
            'actual_menit' => 90, 'std_menit' => 10, 'over_menit' => 80,
            'created_by' => $this->user()->id,
        ]);

        $rows = $this->svc()->lossTime($this->tgl('2026-07-15'), $this->tgl('2026-07-15'));

        $this->assertSame(80.0, $rows[0]['menit'], 'Batang Pareto harus over_menit.');
        $this->assertSame(90.0, $rows[0]['aktual'], 'actual_menit wajib tetap dibawa untuk tooltip.');
    }

    /* ═══ Warna donut ═════════════════════════════════════════════ */

    public function test_warna_terjepit_di_kedua_ambang(): void
    {
        // ≤20% merah penuh, ≥80% hijau penuh. Nilai di luar rentang tidak
        // boleh "melewati" warnanya jadi sesuatu yang lain.
        $merah = OverviewService::warnaAchievement(OverviewService::AMBANG_MERAH);
        $hijau = OverviewService::warnaAchievement(OverviewService::AMBANG_HIJAU);

        $this->assertSame('#b91c1c', $merah);
        $this->assertSame('#15803d', $hijau);

        $this->assertSame($merah, OverviewService::warnaAchievement(0.0));
        $this->assertSame($merah, OverviewService::warnaAchievement(5.0));
        $this->assertSame($hijau, OverviewService::warnaAchievement(100.0));
        // Achievement di atas 100% itu nyata (SPH standar terlalu rendah) dan
        // harus tetap hijau, bukan berputar balik ke merah.
        $this->assertSame($hijau, OverviewService::warnaAchievement(150.0));
    }

    public function test_warna_digradasi_bukan_tiga_kotak_diskret(): void
    {
        // Titik tengah rentang = simpul kuning.
        $this->assertSame('#ca8a04', OverviewService::warnaAchievement(50.0));

        // 79% dan 81% tidak boleh identik — batas mendadak membuat selisih dua
        // poin terlihat seperti dua kelas berbeda.
        $this->assertNotSame(
            OverviewService::warnaAchievement(79.0),
            OverviewService::warnaAchievement(81.0)
        );

        // Monoton: makin tinggi achievement, komponen merah makin turun.
        $merahDi = fn (float $p) => hexdec(substr(OverviewService::warnaAchievement($p), 1, 2));
        $this->assertGreaterThan($merahDi(60.0), $merahDi(30.0));
        $this->assertGreaterThan($merahDi(80.0), $merahDi(60.0));
    }

    public function test_warna_null_abu_netral_bukan_merah(): void
    {
        // Merah berarti "buruk". Tak ada data bukan buruk, cuma tak diketahui —
        // mewarnainya merah itu melaporkan kegagalan yang tidak pernah terukur.
        $this->assertSame('#e5e7eb', OverviewService::warnaAchievement(null));
    }
}
