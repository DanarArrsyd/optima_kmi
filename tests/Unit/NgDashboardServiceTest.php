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
use App\Services\NgDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
