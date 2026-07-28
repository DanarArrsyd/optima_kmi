<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use App\Models\User;
use App\Services\NgDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NgDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private NgDashboardService $svc;

    private User $user;

    private MasterShift $shift;

    private MasterKaryawan $karyawan;

    private MasterMesin $mesin;

    private MasterPartProses $proses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(NgDashboardService::class);
        $this->user = User::factory()->create();
        $this->shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op NG Dash', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'ND-01', 'nama_mesin' => 'Press ND', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'ND-P1', 'part_name' => 'ND Part', 'customer' => 'Toyota']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    private function produksi(string $tanggal, int $qtyAktual, int $qtyNg): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal,
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => $qtyAktual,
            'qty_ng' => $qtyNg,
            'created_by' => $this->user->id,
        ]);
    }

    private function qc(string $tanggal, int $qtyOk, int $qtyNg): TransaksiQc
    {
        return TransaksiQc::create([
            'tgl_pengecekan' => $tanggal,
            'tgl_produksi_part' => $tanggal,
            'id_shift' => $this->shift->id,
            'id_karyawan_qc' => $this->karyawan->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_ok' => $qtyOk,
            'qty_ng' => $qtyNg,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_ng_rate_produksi_memakai_qty_aktual_plus_ng_sebagai_penyebut(): void
    {
        // 5 NG dari (95 baik + 5 NG) = 5%
        $this->produksi('2026-03-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame('2026-03', $hasil['by_month'][0]['bulan_raw']);
        $this->assertSame(5, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
        $this->assertSame(5.0, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_ng_rate_qc_memakai_qty_ok_plus_ng_sebagai_penyebut(): void
    {
        // 10 NG dari (90 ok + 10 NG) = 10%
        $this->qc('2026-03-10', 90, 10);

        $hasil = $this->svc->getVcbNg('qc', '2026-01-01', '2026-12-31');

        $this->assertSame(10, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
        $this->assertSame(10.0, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_beberapa_sesi_dalam_satu_bulan_dijumlahkan(): void
    {
        $this->produksi('2026-03-01', 90, 10);
        $this->produksi('2026-03-20', 190, 10);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame(20, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(300, $hasil['by_month'][0]['total_check']);
        $this->assertSame(6.7, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_bulan_dengan_transaksi_tapi_nol_ng_tampil_sebagai_nol_persen(): void
    {
        $this->produksi('2026-03-10', 100, 0);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame(0, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
        $this->assertSame(0.0, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_bulan_tanpa_transaksi_sama_sekali_tidak_muncul(): void
    {
        $this->produksi('2026-03-10', 95, 5);
        $this->produksi('2026-05-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        // April tidak punya transaksi — tidak boleh muncul sebagai bulan 0%.
        $this->assertCount(2, $hasil['by_month']);
        $this->assertSame(['2026-03', '2026-05'], array_column($hasil['by_month'], 'bulan_raw'));
    }

    public function test_label_bulan_tidak_overflow_saat_dijalankan_di_tanggal_31(): void
    {
        // Bug: Carbon::createFromFormat('Y-m', '2026-02') tanpa '!' mengisi hari
        // dari "hari ini". Kalau "hari ini" tanggal 31 Januari, hari-31 dipaksakan
        // ke bulan Februari (28 hari) lalu overflow ke Maret — label jadi "Mar 2026"
        // padahal bulan_raw tetap benar '2026-02'.
        Carbon::setTestNow('2026-01-31');

        try {
            $this->produksi('2026-02-10', 95, 5);

            $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

            $this->assertSame('2026-02', $hasil['by_month'][0]['bulan_raw']);
            $this->assertSame('Feb 2026', $hasil['by_month'][0]['bulan']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_urut_menaik_menurut_bulan(): void
    {
        $this->produksi('2026-05-10', 95, 5);
        $this->produksi('2026-01-10', 95, 5);
        $this->produksi('2026-03-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame(['2026-01', '2026-03', '2026-05'], array_column($hasil['by_month'], 'bulan_raw'));
    }

    public function test_di_luar_rentang_tidak_ikut(): void
    {
        $this->produksi('2026-03-10', 95, 5);
        $this->produksi('2026-08-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-03-01', '2026-03-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame('2026-03', $hasil['by_month'][0]['bulan_raw']);
    }

    public function test_sesi_ter_soft_delete_tidak_ikut_terhitung(): void
    {
        $this->produksi('2026-03-10', 95, 5);
        $this->produksi('2026-03-11', 90, 10)->delete();

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame(5, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
    }

    public function test_rentang_tanpa_data_mengembalikan_struktur_kosong(): void
    {
        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame([], $hasil['by_month']);
        $this->assertSame(0, $hasil['summary']['total_ng']);
        $this->assertSame(0, $hasil['summary']['total_check']);
        $this->assertSame(0.0, $hasil['summary']['ng_rate']);
    }

    public function test_summary_menjumlahkan_seluruh_rentang(): void
    {
        $this->produksi('2026-03-10', 90, 10);
        $this->produksi('2026-05-10', 190, 10);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame(20, $hasil['summary']['total_ng']);
        $this->assertSame(300, $hasil['summary']['total_check']);
        $this->assertSame(6.7, $hasil['summary']['ng_rate']);
    }

    public function test_sumber_tidak_dikenal_melempar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->svc->getVcbNg('mesin', '2026-01-01', '2026-12-31');
    }

    public function test_detail_mengembalikan_jenis_ng_urut_qty_terbanyak(): void
    {
        $trx = $this->produksi('2026-03-10', 90, 10);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 3]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 7]);

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(2, $hasil);
        $this->assertSame('DNT', $hasil[0]['kode']);
        $this->assertSame('Dented', $hasil[0]['nama_ng']);
        $this->assertSame(7, $hasil[0]['qty']);
        $this->assertSame(70.0, $hasil[0]['porsi']);
        $this->assertSame('CRK', $hasil[1]['kode']);
        $this->assertSame(3, $hasil[1]['qty']);
        $this->assertSame(30.0, $hasil[1]['porsi']);
    }

    public function test_detail_menjumlahkan_jenis_yang_sama_dari_beberapa_sesi(): void
    {
        $a = $this->produksi('2026-03-01', 95, 5);
        $b = $this->produksi('2026-03-20', 95, 5);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $a->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $b->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(1, $hasil);
        $this->assertSame(10, $hasil[0]['qty']);
        $this->assertSame(100.0, $hasil[0]['porsi']);
    }

    public function test_detail_tidak_bocor_lintas_bulan(): void
    {
        $maret = $this->produksi('2026-03-10', 95, 5);
        $april = $this->produksi('2026-04-10', 97, 3);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $maret->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $april->id, 'id_jenis_ng' => $crack->id, 'qty' => 3]);

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(1, $hasil);
        $this->assertSame('DNT', $hasil[0]['kode']);
    }

    public function test_detail_mengabaikan_sesi_ter_soft_delete(): void
    {
        $hidup = $this->produksi('2026-03-10', 95, 5);
        $mati = $this->produksi('2026-03-11', 95, 5);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $hidup->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $mati->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        $mati->delete();

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertSame(5, $hasil[0]['qty']);
    }

    public function test_detail_sisi_qc_terpisah_dari_produksi(): void
    {
        $trx = $this->qc('2026-03-10', 90, 10);
        $burry = MasterJenisNg::create(['kode' => 'BRY', 'nama_ng' => 'Burry']);

        TransaksiQcNg::create(['id_transaksi_qc' => $trx->id, 'id_jenis_ng' => $burry->id, 'qty' => 10]);

        $this->assertSame([], $this->svc->getVcbNgDetail('produksi', '2026-03'));

        $hasil = $this->svc->getVcbNgDetail('qc', '2026-03');
        $this->assertCount(1, $hasil);
        $this->assertSame('BRY', $hasil[0]['kode']);
        $this->assertSame(10, $hasil[0]['qty']);
    }

    public function test_detail_bulan_tanpa_ng_mengembalikan_array_kosong(): void
    {
        $this->produksi('2026-03-10', 100, 0);

        $this->assertSame([], $this->svc->getVcbNgDetail('produksi', '2026-03'));
    }

    public function test_detail_jenis_ng_terhapus_tetap_beda_baris_meski_kode_sama_sama_tanda_tanya(): void
    {
        // Dua jenis NG berbeda yang master row-nya sudah soft-delete sama-sama
        // fallback ke kode '?'. Sebelum perbaikan, x-for di Blade dikunci pakai
        // `row.kode` sehingga Alpine menganggap keduanya satu baris yang sama
        // dan salah satunya hilang dari tabel. Di level service ini kita pastikan
        // datanya sendiri tetap dua baris terpisah dengan id_jenis_ng berbeda.
        $trx = $this->produksi('2026-03-10', 90, 10);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 7]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 3]);

        $dented->delete();
        $crack->delete();

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(2, $hasil);

        $idJenisNg = array_column($hasil, 'id_jenis_ng');
        $this->assertNotSame($idJenisNg[0], $idJenisNg[1]);
        $this->assertEqualsCanonicalizing([$dented->id, $crack->id], $idJenisNg);

        foreach ($hasil as $row) {
            $this->assertSame('?', $row['kode']);
            $this->assertSame('Jenis NG terhapus', $row['nama_ng']);
        }
    }

    public function test_detail_sumber_tidak_dikenal_melempar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->svc->getVcbNgDetail('mesin', '2026-03');
    }

    public function test_detail_mengembalikan_kosong_saat_tabel_detail_belum_ada(): void
    {
        $trx = $this->produksi('2026-03-10', 95, 5);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);

        // Meniru jendela antara rsync dan artisan migrate: kode sudah live,
        // tabelnya belum ada. Halaman harus tampil kosong, bukan meledak.
        Schema::drop('transaksi_produksi_ng');
        Schema::drop('transaksi_qc_ng');

        $this->assertSame([], $this->svc->getVcbNgDetail('produksi', '2026-03'));
    }
}
