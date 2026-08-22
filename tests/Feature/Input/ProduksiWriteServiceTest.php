<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\ProduksiWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Satu jalur tulis untuk satu baris LHP. Dipakai endpoint batch; jalur yang
 * disalin sudah berkali-kali menyimpang di repo ini.
 */
class ProduksiWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $karyawanLain = MasterKaryawan::create(['nik' => 'K2', 'nama_karyawan' => 'OPERATOR DUA', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);
        $jenisNg = MasterJenisNg::create(['kode' => 'NG1', 'nama_ng' => 'GORES']);
        $losstime = MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 45]);

        $this->ref = compact('shift', 'karyawan', 'karyawanLain', 'mesin', 'proses', 'jenisNg', 'losstime');

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_mesin' => $this->ref['mesin']->id,
            'id_part_proses' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '15:00',
            'qty_aktual' => 4000,
        ], $override);
    }

    public function test_menulis_baris_lhp_dengan_cycle_time_terhitung(): void
    {
        $produksi = app(ProduksiWriteService::class)->simpan($this->baris());

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(4000, $produksi->qty_aktual);
        $this->assertSame(auth()->id(), $produksi->created_by);
        // 8 jam / 4000 pcs = 7,2 detik per pcs.
        $this->assertEqualsWithDelta(7.2, (float) $produksi->actual_cycle_time, 0.01);
    }

    public function test_menulis_rincian_ng_bersama_barisnya(): void
    {
        $produksi = app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_ng' => 1,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
        ]));

        $this->assertSame(1, $produksi->ngDetails()->count());
    }

    public function test_menulis_rincian_dandori_dengan_field_sesi_yang_sama(): void
    {
        app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50]],
        ]));

        $baris = TransaksiDandoriRincian::first();
        $this->assertNotNull($baris);
        $this->assertSame($this->ref['mesin']->id, $baris->id_mesin);
        $this->assertSame($this->ref['proses']->id, $baris->id_part_proses);
        $this->assertEquals(30, $baris->std_menit);
        $this->assertEquals(20, $baris->over_menit);
    }

    public function test_operator_dandori_bisa_beda_dari_operator_lhp(): void
    {
        app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori_karyawan_id' => $this->ref['karyawanLain']->id,
            'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50]],
        ]));

        $this->assertSame($this->ref['karyawanLain']->id, TransaksiDandoriRincian::first()->id_karyawan);
    }

    public function test_kunci_bantu_tidak_ikut_tertulis_ke_kolom(): void
    {
        // ada_ng, ng_count, ada_dandori, dandori_count, dandori_karyawan_id
        // adalah kunci bantu form — bukan kolom. Kalau ikut masuk ke create(),
        // penyimpanan meledak dengan "column not found".
        $produksi = app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_ng' => 1,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
            'ada_dandori' => 1,
            'dandori_count' => 0,
            'dandori' => [],
        ]));

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertNull($produksi->getAttribute('ng_count'));
    }
}
