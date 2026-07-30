<?php

namespace Tests\Unit\Chatbot;

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
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tool NG untuk chatbot. Angkanya WAJIB sejalan dengan dashboard NG yang sudah
 * live — chatbot dan layar tidak boleh menjawab beda untuk pertanyaan yang sama.
 */
class NgChatbotToolsTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $operator = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $inspector = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Inspector B', 'departemen' => 'QC', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'Mesin A4', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'PN-001', 'part_name' => 'Bracket', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $dent = MasterJenisNg::create(['kode' => 'NG-01', 'nama_ng' => 'Dent']);
        $burr = MasterJenisNg::create(['kode' => 'NG-02', 'nama_ng' => 'Burr']);
        $crack = MasterJenisNg::create(['kode' => 'NG-03', 'nama_ng' => 'Crack']);

        return compact('user', 'shift', 'operator', 'inspector', 'mesin', 'proses', 'dent', 'burr', 'crack');
    }

    private function sesiProduksi(array $f, string $tanggal, int $baik, array $ng): TransaksiProduksi
    {
        $trx = TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['operator']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $f['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => $baik, 'qty_ng' => array_sum($ng), 'created_by' => $f['user']->id,
        ]);
        foreach ($ng as $jenisId => $qty) {
            TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $jenisId, 'qty' => $qty]);
        }

        return $trx;
    }

    private function sesiQc(array $f, string $tanggal, int $baik, array $ng): TransaksiQc
    {
        $trx = TransaksiQc::create([
            'tgl_pengecekan' => $tanggal, 'tgl_produksi_part' => $tanggal, 'id_shift' => $f['shift']->id,
            'id_karyawan_qc' => $f['inspector']->id, 'id_part_proses' => $f['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_ok' => $baik, 'qty_ng' => array_sum($ng), 'created_by' => $f['user']->id,
        ]);
        foreach ($ng as $jenisId => $qty) {
            TransaksiQcNg::create(['id_transaksi_qc' => $trx->id, 'id_jenis_ng' => $jenisId, 'qty' => $qty]);
        }

        return $trx;
    }

    // ── getParetoNg ────────────────────────────────────────────────────────

    public function test_pareto_mengurutkan_jenis_ng_dari_terbanyak(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 1000, [$f['dent']->id => 5, $f['burr']->id => 30]);
        $this->sesiProduksi($f, '2026-03-02', 1000, [$f['crack']->id => 12, $f['burr']->id => 10]);

        $hasil = (new ChatbotTools)->getParetoNg('produksi');

        $this->assertSame(['Burr', 'Crack', 'Dent'], array_column($hasil['pareto'], 'nama_ng'));
        $this->assertSame([40, 12, 5], array_column($hasil['pareto'], 'qty'));
    }

    /** Porsi kumulatif adalah inti Pareto — tanpa itu ini cuma daftar urut. */
    public function test_pareto_menyertakan_porsi_dan_kumulatif(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 1000, [$f['burr']->id => 75, $f['dent']->id => 25]);

        $hasil = (new ChatbotTools)->getParetoNg('produksi');

        $this->assertSame(75.0, $hasil['pareto'][0]['porsi_pct']);
        $this->assertSame(75.0, $hasil['pareto'][0]['kumulatif_pct']);
        $this->assertSame(100.0, $hasil['pareto'][1]['kumulatif_pct']);
    }

    public function test_pareto_memisahkan_sisi_produksi_dan_qc(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 1000, [$f['dent']->id => 5]);
        $this->sesiQc($f, '2026-03-01', 1000, [$f['burr']->id => 9]);

        $tools = new ChatbotTools;

        $this->assertSame(['Dent'], array_column($tools->getParetoNg('produksi')['pareto'], 'nama_ng'));
        $this->assertSame(['Burr'], array_column($tools->getParetoNg('qc')['pareto'], 'nama_ng'));
    }

    public function test_pareto_menyaring_periode(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 1000, [$f['dent']->id => 5]);
        $this->sesiProduksi($f, '2026-05-01', 1000, [$f['burr']->id => 9]);

        $hasil = (new ChatbotTools)->getParetoNg('produksi', '2026-05');

        $this->assertSame(['Burr'], array_column($hasil['pareto'], 'nama_ng'));
    }

    /** Sumber tak dikenal harus jadi pesan jelas, bukan hasil kosong yang menyesatkan. */
    public function test_pareto_menolak_sumber_tak_dikenal(): void
    {
        $hasil = (new ChatbotTools)->getParetoNg('gudang');

        $this->assertArrayHasKey('error', $hasil);
        $this->assertStringContainsString('produksi', $hasil['error']);
    }

    public function test_pareto_tanpa_data_menjawab_jelas_bukan_daftar_kosong(): void
    {
        $this->fixtures();

        $hasil = (new ChatbotTools)->getParetoNg('qc');

        $this->assertSame([], $hasil['pareto']);
        $this->assertArrayHasKey('catatan', $hasil);
    }

    // ── getNgRate ──────────────────────────────────────────────────────────

    /** PPM dan persen sekaligus: rate pabrik ada di orde 0,00x% dan terbaca nol. */
    public function test_ng_rate_melaporkan_ppm_dan_persen(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 9_600, [$f['dent']->id => 400]);

        $hasil = (new ChatbotTools)->getNgRate('produksi');

        $this->assertSame(400, $hasil['total_ng']);
        $this->assertSame(10_000, $hasil['total_diproduksi']);
        $this->assertSame(40_000.0, $hasil['ng_rate_ppm']);
        $this->assertSame(4.0, $hasil['ng_rate_pct']);
    }

    /**
     * Penyebut = barang baik + NG. qty_aktual berisi barang BAIK saja
     * (dikonfirmasi ke produksi 2026-07-28), jadi memakainya sendirian sebagai
     * penyebut membuat rate understated.
     */
    public function test_ng_rate_memakai_total_diproduksi_sebagai_penyebut(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 100, [$f['dent']->id => 100]);

        $hasil = (new ChatbotTools)->getNgRate('produksi');

        $this->assertSame(200, $hasil['total_diproduksi']);
        $this->assertSame(50.0, $hasil['ng_rate_pct']);
    }

    public function test_ng_rate_tanpa_data_tidak_membagi_nol(): void
    {
        $this->fixtures();

        $hasil = (new ChatbotTools)->getNgRate('qc');

        $this->assertSame(0, $hasil['total_diproduksi']);
        $this->assertSame(0.0, $hasil['ng_rate_ppm']);
        $this->assertArrayHasKey('catatan', $hasil);
    }

    public function test_ng_rate_menolak_sumber_tak_dikenal(): void
    {
        $this->assertArrayHasKey('error', (new ChatbotTools)->getNgRate('gudang'));
    }

    // ── getRekapNg (diperbaiki) ────────────────────────────────────────────

    /**
     * namaProses dulu WAJIB, jadi LLM terpaksa mengarang isinya: "Pareto NG
     * proses" masuk sebagai namaProses="PARETO" dan dijawab "tidak ada data QC
     * untuk proses PARETO". Sekarang opsional.
     */
    public function test_rekap_ng_jalan_tanpa_nama_proses(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 900, [$f['dent']->id => 100]);

        $hasil = (new ChatbotTools)->getRekapNg(null, null, 'produksi');

        $this->assertArrayNotHasKey('error', $hasil);
        $this->assertSame(100, $hasil['total_ng']);
    }

    /**
     * Dulu hanya membaca transaksi_qc. Produksi punya data NG, QC nol sejak
     * sistem berjalan — jadi tool ini praktis selalu menjawab "tidak ada data".
     */
    public function test_rekap_ng_bisa_membaca_sisi_produksi(): void
    {
        $f = $this->fixtures();
        $this->sesiProduksi($f, '2026-03-01', 900, [$f['dent']->id => 100]);

        $hasil = (new ChatbotTools)->getRekapNg('BENDING', null, 'produksi');

        $this->assertSame(100, $hasil['total_ng']);
        $this->assertSame(1000, $hasil['total_diproduksi']);
    }
}
