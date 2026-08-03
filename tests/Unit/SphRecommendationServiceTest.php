<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\SphRecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rekomendasi upgrade SPH.
 *
 * Sebelum test ini, layanan ini NOL cakupan — dan query mentahnya memakai
 * fungsi khusus MySQL (GREATEST/IF/TIME_TO_SEC/TIMEDIFF/ADDTIME), sehingga
 * halaman Master Part / Dies 500 di suite (SQLite) tanpa ada yang tahu.
 *
 * Test ini menguji ANGKA, bukan sekadar "tidak meledak". Kalau ekspresi
 * durasi salah porting, SPH aktual ikut salah dan assertion di bawah gagal.
 */
class SphRecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    private SphRecommendationService $service;

    private User $user;

    private MasterShift $shift;

    private MasterKaryawan $karyawan;

    private MasterMesin $mesin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(SphRecommendationService::class);

        $this->user = User::factory()->create();
        $this->shift = MasterShift::create([
            'nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00',
        ]);
        $this->karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator SPH',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'SPH-01', 'nama_mesin' => 'Press SPH', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
    }

    /** Part + proses dengan standar yang ditentukan. */
    private function proses(int $stdProduksi = 100, int $stdQc = 100): MasterPartProses
    {
        $part = MasterPart::create([
            'part_no' => 'SPH-'.uniqid(), 'part_name' => 'Part SPH', 'customer' => 'Toyota',
        ]);

        return MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => $stdProduksi, 'sph_standar_qc' => $stdQc,
        ]);
    }

    private function lhp(MasterPartProses $proses, string $mulai, string $selesai, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-01',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => $mulai,
            'jam_finish' => $selesai,
            'qty_aktual' => $qty,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * 500 pcs dalam 4 jam = SPH aktual 125, standar 100 → terlampaui.
     * Rekomendasi = rata-rata semua sesi (125) dibulatkan ke 10 → 130.
     */
    public function test_merekomendasikan_upgrade_saat_sph_aktual_melampaui_standar(): void
    {
        $proses = $this->proses(stdProduksi: 100);
        $this->lhp($proses, '08:00:00', '12:00:00', 500);

        $hasil = $this->service->getAll();

        $this->assertCount(1, $hasil);
        $this->assertSame(125, $hasil[0]->max_sph);
        $this->assertSame(125, $hasil[0]->avg_all_sph);
        $this->assertSame(130, $hasil[0]->recommended_sph);
        $this->assertSame(1, $hasil[0]->exceed_count);
        $this->assertSame(1, $hasil[0]->total_sessions);
    }

    /**
     * Cabang lintas tengah malam: 22:00→02:00 juga 4 jam, jadi angkanya
     * harus IDENTIK dengan sesi siang di atas. Kalau cabang ini salah
     * porting, durasi jadi negatif dan SPH meledak.
     */
    public function test_sesi_melewati_tengah_malam_dihitung_sama_dengan_sesi_siang(): void
    {
        $proses = $this->proses(stdProduksi: 100);
        $this->lhp($proses, '22:00:00', '02:00:00', 500);

        $hasil = $this->service->getAll();

        $this->assertCount(1, $hasil);
        $this->assertSame(125, $hasil[0]->max_sph);
        $this->assertSame(130, $hasil[0]->recommended_sph);
    }

    /** Standar 200, aktual 125 → tidak layak, tidak boleh muncul. */
    public function test_sesi_di_bawah_standar_tidak_direkomendasikan(): void
    {
        $proses = $this->proses(stdProduksi: 200);
        $this->lhp($proses, '08:00:00', '12:00:00', 500);

        $this->assertCount(0, $this->service->getAll());
    }

    /** Beberapa sesi: rata-rata SEMUA sesi yang jadi dasar, bukan yang melampaui saja. */
    public function test_rekomendasi_memakai_rata_rata_semua_sesi(): void
    {
        $proses = $this->proses(stdProduksi: 100);
        $this->lhp($proses, '08:00:00', '12:00:00', 500);   // 125
        $this->lhp($proses, '13:00:00', '17:00:00', 700);   // 175

        $hasil = $this->service->getAll();

        $this->assertCount(1, $hasil);
        $this->assertSame(2, $hasil[0]->total_sessions);
        $this->assertSame(2, $hasil[0]->exceed_count);
        $this->assertSame(175, $hasil[0]->max_sph);
        $this->assertSame(150, $hasil[0]->avg_all_sph);       // (125 + 175) / 2
        $this->assertSame(150, $hasil[0]->recommended_sph);
    }

    /** Baris ter-soft-delete tidak boleh ikut dihitung. */
    public function test_lhp_terhapus_tidak_ikut_dihitung(): void
    {
        $proses = $this->proses(stdProduksi: 100);
        $this->lhp($proses, '08:00:00', '12:00:00', 500);
        $this->lhp($proses, '13:00:00', '17:00:00', 700)->delete();

        $hasil = $this->service->getAll();

        $this->assertSame(1, $hasil[0]->total_sessions);
        $this->assertSame(125, $hasil[0]->avg_all_sph);
    }

    /** forPart() menyaring ke satu part saja. */
    public function test_for_part_hanya_mengembalikan_part_yang_diminta(): void
    {
        $a = $this->proses(stdProduksi: 100);
        $b = $this->proses(stdProduksi: 100);
        $this->lhp($a, '08:00:00', '12:00:00', 500);
        $this->lhp($b, '08:00:00', '12:00:00', 500);

        $hasil = $this->service->forPart($a->id_part);

        $this->assertCount(1, $hasil);
        $this->assertSame($a->id, $hasil[0]->id_part_proses);
    }

    /** Sisi QC memakai qty_ok + qty_ng, bukan qty_ok saja. */
    public function test_qc_memakai_qty_ok_ditambah_qty_ng(): void
    {
        $proses = $this->proses(stdQc: 100);

        TransaksiQc::create([
            'tgl_pengecekan' => '2026-08-01',
            'tgl_produksi_part' => '2026-08-01',
            'id_shift' => $this->shift->id,
            'id_karyawan_qc' => $this->karyawan->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_ok' => 480,
            'qty_ng' => 20,          // 480 + 20 = 500 dalam 4 jam → 125
            'created_by' => $this->user->id,
        ]);

        $hasil = $this->service->getAllQc();

        $this->assertCount(1, $hasil);
        $this->assertSame(125, $hasil[0]->avg_all_sph);
        $this->assertSame(130, $hasil[0]->recommended_sph);
    }

    /**
     * Isolasi data lama: sesi SEBELUM tanggal upgrade terakhir tidak boleh
     * ikut menarik rekomendasi. Memakai DATE() di SQL.
     */
    public function test_sesi_sebelum_upgrade_terakhir_diabaikan(): void
    {
        $proses = $this->proses(stdProduksi: 100);
        $proses->update(['sph_last_upgraded_at' => '2026-08-01 00:00:00']);

        // Sesi lama (sebelum upgrade) — harus diabaikan.
        $lama = $this->lhp($proses, '08:00:00', '12:00:00', 900);
        $lama->update(['tanggal_produksi' => '2026-07-01']);

        // Sesi baru — inilah yang mendasari rekomendasi.
        $this->lhp($proses, '08:00:00', '12:00:00', 500);

        $hasil = $this->service->getAll();

        $this->assertCount(1, $hasil);
        $this->assertSame(1, $hasil[0]->total_sessions);
        $this->assertSame(125, $hasil[0]->avg_all_sph);
    }
}
