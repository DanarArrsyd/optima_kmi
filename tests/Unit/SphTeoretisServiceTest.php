<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\SphTeoretisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kandidat SPH teoretis.
 *
 * Tiap pengaman diuji SENDIRI karena arah kerusakannya jahat: kandidat yang
 * menggelembung menekan Performance part itu secara permanen, dan tidak ada yang
 * mempertanyakan angka yang terlihat buruk. Pengaman yang tidak diuji sama dengan
 * tidak ada.
 */
class SphTeoretisServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hari_libur' => []]);
        // Shift 1 06:00–14:00 = 8 jam terjadwal, supaya jamProduktif punya acuan.
        MasterShift::firstOrCreate(['nama_shift' => 'Shift 1'], ['jam_start' => '06:00', 'jam_finish' => '14:00']);
    }

    private function svc(): SphTeoretisService
    {
        return new SphTeoretisService;
    }

    private function proses(float $sphStd = 400): MasterPartProses
    {
        $part = MasterPart::firstOrCreate(
            ['part_no' => 'PT-SPH'],
            ['part_name' => 'Bracket', 'customer' => 'Toyota', 'tanggal_instalasi' => '2026-01-01']
        );

        return MasterPartProses::firstOrCreate(
            ['id_part' => $part->id, 'id_proses' => 1],
            ['nama_proses' => 'BLANKING', 'sph_standar_produksi' => $sphStd, 'sph_standar_qc' => 200]
        );
    }

    private function mesin(): MasterMesin
    {
        return MasterMesin::firstOrCreate(
            ['id_mesin' => 'M-SPH'],
            ['nama_mesin' => 'Press', 'line' => '1', 'tipe_mesin' => 'BLANKING',
                'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2026-01-01']
        );
    }

    /** LHP 8 jam (06:00–14:00) kecuali finish di-override. */
    private function lhp(int $qty, string $tgl, string $finish = '14:00', ?string $reviewed = null): void
    {
        $kar = MasterKaryawan::firstOrCreate(
            ['nik' => 'K-SPH'],
            ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]
        );

        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl,
            'id_shift' => MasterShift::first()->id,
            'id_karyawan' => $kar->id,
            'id_mesin' => $this->mesin()->id,
            'id_part_proses' => $this->proses()->id,
            'jam_start' => '06:00', 'jam_finish' => $finish,
            'qty_aktual' => $qty, 'qty_ng' => 0,
            'anomaly_reviewed_at' => $reviewed,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** Lima hari kerja berurutan, qty sama. */
    private function lhpLimaHari(int $qty): void
    {
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $tgl) {
            $this->lhp($qty, $tgl);
        }
    }

    public function test_kandidat_dari_persentil_bukan_maksimum(): void
    {
        /*
         * Empat hari 3.200 (400 SPH) + satu hari 8.000 (1.000 SPH, typo qty).
         *
         * MAX akan memberi 1.000 — palang disetel oleh satu salah input.
         * Persentil 95 dari 5 nilai jatuh di nilai tertinggi juga, JADI test ini
         * memakai 20 baris supaya persentilnya benar-benar mengabaikan outlier.
         */
        $this->proses(sphStd: 400);
        for ($i = 0; $i < 19; $i++) {
            $this->lhp(3200, Carbon::parse('2026-06-01')->addDays($i)->toDateString());
        }
        // Satu outlier ekstrem. Sengaja dibuat lolos ambang anomali (pct 150%)
        // dengan qty 4.700 → 587 SPH = 147% target, jadi TIDAK dibuang sebagai
        // anomali dan harus diredam oleh persentil, bukan oleh filter anomali.
        $this->lhp(4700, '2026-06-25');

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertSame('siap', $hasil['status']);
        $this->assertLessThan(587.0, $hasil['kandidat'], 'Persentil harus meredam outlier tunggal.');
        $this->assertGreaterThan(390.0, $hasil['kandidat']);
    }

    public function test_baris_anomali_dibuang_dan_dihitung(): void
    {
        // qty 3.200 dalam 5 menit → SPH meledak, jauh di atas ambang 150%.
        $this->proses(sphStd: 400);
        $this->lhpLimaHari(3200);
        $this->lhp(3200, '2026-08-10', finish: '06:05');   // waktu kerja 5 menit

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertSame(1, $hasil['baris_dibuang']);
        $this->assertSame(5, $hasil['baris_bersih']);
        // Kandidat tetap sekitar 400, tidak diracuni baris 5 menit itu.
        $this->assertEqualsWithDelta(400.0, $hasil['kandidat'], 5.0);
    }

    public function test_anomali_yang_sudah_direview_admin_teta_p_dipakai(): void
    {
        /*
         * anomaly_reviewed_at terisi = manusia sudah menyatakan angkanya nyata.
         * Membuangnya berarti mengabaikan penilaian admin, dan membuat halaman
         * Anomali LHP jadi tak ada gunanya untuk keperluan ini.
         */
        $this->proses(sphStd: 400);
        $this->lhpLimaHari(3200);
        $this->lhp(6400, '2026-08-10', reviewed: '2026-08-11 08:00:00');   // 800 SPH = 200% target

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertSame(0, $hasil['baris_dibuang'], 'Anomali yang sudah di-OK admin tidak boleh dibuang.');
        $this->assertSame(6, $hasil['baris_bersih']);
    }

    public function test_kurang_baris_tidak_diusulkan(): void
    {
        // Empat baris, minimum lima. Kandidat null — bukan angka setengah matang.
        $this->proses(sphStd: 400);
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'] as $tgl) {
            $this->lhp(3200, $tgl);
        }

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertNull($hasil['kandidat']);
        $this->assertSame('kurang_data', $hasil['status']);
        $this->assertStringContainsString('minimum', $hasil['alasan']);
    }

    public function test_lima_baris_di_satu_tanggal_tidak_cukup(): void
    {
        // Lolos minimum baris tapi cuma satu tanggal. Bukan kemampuan terbukti —
        // bisa satu shift yang kebetulan lancar, atau satu batch salah input.
        $this->proses(sphStd: 400);
        for ($i = 0; $i < 5; $i++) {
            $this->lhp(3200, '2026-08-05');
        }

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertNull($hasil['kandidat']);
        $this->assertSame('kurang_data', $hasil['status']);
        $this->assertStringContainsString('tanggal berbeda', $hasil['alasan']);
    }

    public function test_baris_sudah_direview_tapi_tetap_absurd_ditandai_curiga(): void
    {
        /*
         * MAKS_RASIO bukan penangkap salah input mentah — filter anomali sudah
         * melakukannya, dan ambangnya (150% target) jauh di bawah batas ini
         * (300%), jadi baris mentah seekstrem itu tak pernah sampai ke sini.
         *
         * Yang ditangkap batas ini: baris yang sudah di-OK admin tapi TETAP
         * absurd. Review manusia satu-satunya jalan melewati filter anomali, dan
         * meng-OK borongan tanpa dilihat itu wajar terjadi. Ini jaring terakhir.
         *
         * Target 50, produksi 400 SPH = 8× target, semua baris di-review.
         */
        $this->proses(sphStd: 50);
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $tgl) {
            $this->lhp(3200, $tgl, reviewed: '2026-08-11 08:00:00');
        }

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertSame('curiga', $hasil['status']);
        $this->assertNotNull($hasil['kandidat'], 'Kandidatnya tetap ditampilkan — menyembunyikannya menghapus petunjuknya.');
        $this->assertGreaterThan(SphTeoretisService::MAKS_RASIO, $hasil['rasio']);
    }

    public function test_target_absurd_tanpa_review_semua_baris_dibuang(): void
    {
        /*
         * Interaksi yang layak dicatat: part yang target-nya diset absurd rendah
         * membuat SELURUH barisnya melewati ambang anomali, jadi ia tak pernah
         * bisa menghasilkan kandidat.
         *
         * Itu arah yang AMAN — nol kandidat lebih baik daripada kandidat salah —
         * tapi berarti part semacam itu harus dibetulkan target-nya dulu, bukan
         * ditunggu muncul di laporan ini.
         */
        $this->proses(sphStd: 50);
        $this->lhpLimaHari(3200);   // 400 SPH = 800% target, tanpa review

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertSame(5, $hasil['baris_dibuang']);
        $this->assertSame(0, $hasil['baris_bersih']);
        $this->assertNull($hasil['kandidat']);
        $this->assertSame('kurang_data', $hasil['status']);
    }

    public function test_sph_dihitung_atas_jam_produktif_setelah_setup(): void
    {
        /*
         * Kalau setup tidak dikeluarkan, kandidatnya terlalu RENDAH — dan
         * kandidat rendah membuat Performance terlalu TINGGI, arah optimis yang
         * justru sedang diperbaiki.
         *
         * LHP 8 jam, dandori 2 jam, jadwal 8 jam → produktif 6 jam.
         * qty 2.400 → atas 6 jam = 400 SPH ; atas 8 jam mentah = 300 SPH.
         */
        $this->proses(sphStd: 400);
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06', '2026-08-07'] as $tgl) {
            $this->lhp(2400, $tgl);
            $this->dandori(120.0, $tgl);
        }

        $hasil = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertEqualsWithDelta(400.0, $hasil['kandidat'], 1.0);
        $this->assertNotEqualsWithDelta(300.0, $hasil['kandidat'], 1.0, 'Setup wajib dikeluarkan dari penyebut.');
    }

    public function test_nol_lhp_mengembalikan_array_kosong(): void
    {
        $this->assertSame([], $this->svc()->kandidat());
    }

    public function test_ambang_anomali_dibaca_dari_config_bukan_disalin(): void
    {
        /*
         * Ambangnya satu sumber dengan halaman Anomali LHP
         * (config/anomali_lhp.php). Dua salinan angka akan melenceng dan
         * melencengnya SENYAP: halaman menandai sebuah baris, laporan ini tetap
         * memakainya, dan rekor yang meracuni lolos tanpa jejak.
         *
         * Diturunkan ke 100% → baris 400 SPH pada target 400 (= 100%) masih
         * lolos, tapi yang di atasnya dibuang.
         */
        $this->proses(sphStd: 400);
        $this->lhpLimaHari(3200);          // 400 SPH = 100% target
        $this->lhp(4800, '2026-08-10');    // 600 SPH = 150% target

        config(['anomali_lhp.pct_maks' => 100]);
        $ketat = collect($this->svc()->kandidat())->firstWhere('nama_proses', 'BLANKING');

        config(['anomali_lhp.pct_maks' => 200]);
        $longgar = collect((new SphTeoretisService)->kandidat())->firstWhere('nama_proses', 'BLANKING');

        $this->assertSame(1, $ketat['baris_dibuang'], 'Ambang ketat harus membuang baris 150%.');
        $this->assertSame(0, $longgar['baris_dibuang'], 'Ambang longgar harus memakainya.');
    }

    private function dandori(float $menit, string $tgl): void
    {
        $lt = MasterLosstime::firstOrCreate(
            ['kode' => 'SD'],
            ['jenis_losstime' => 'Setting_Dies', 'std_menit_default' => null, 'std_menit_progressive' => null]
        );
        $kar = MasterKaryawan::firstOrCreate(
            ['nik' => 'K-SPH'],
            ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]
        );

        TransaksiDandoriRincian::create([
            'tanggal' => $tgl,
            'id_shift' => MasterShift::first()->id,
            'id_karyawan' => $kar->id,
            'id_mesin' => $this->mesin()->id,
            'id_part_proses' => $this->proses()->id,
            'id_losstime' => $lt->id,
            'actual_menit' => $menit, 'std_menit' => 0, 'over_menit' => $menit,
            'created_by' => User::factory()->create()->id,
        ]);
    }
}
