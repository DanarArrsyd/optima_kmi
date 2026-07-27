<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\NgDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgDetailServiceTest extends TestCase
{
    use RefreshDatabase;

    private NgDetailService $service;

    private TransaksiProduksi $trx;

    private MasterJenisNg $dented;

    private MasterJenisNg $crack;

    private MasterJenisNg $burry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NgDetailService::class);

        $user = User::factory()->create();

        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Sync', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'SY-01', 'nama_mesin' => 'Press Sync', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'SY-P1', 'part_name' => 'Sync Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->trx = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
            'created_by' => $user->id,
        ]);

        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $this->crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);
        $this->burry = MasterJenisNg::create(['kode' => 'BRY', 'nama_ng' => 'Burry']);
    }

    public function test_menyimpan_tiga_jenis_dan_menurunkan_qty_ng(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ['id_jenis_ng' => $this->burry->id, 'qty' => 3],
        ]);

        $this->assertCount(3, $this->trx->fresh()->ngDetails);
        $this->assertSame(10, (int) $this->trx->fresh()->qty_ng);
    }

    public function test_edit_mengurangi_jenis_menghapus_detail_lama_dan_menurunkan_qty_ng(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ['id_jenis_ng' => $this->burry->id, 'qty' => 3],
        ]);

        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
        ]);

        $fresh = $this->trx->fresh();
        $this->assertCount(1, $fresh->ngDetails);
        $this->assertSame($this->crack->id, $fresh->ngDetails->first()->id_jenis_ng);
        $this->assertSame(4, (int) $fresh->qty_ng);
    }

    public function test_hapus_lalu_tambah_jenis_yang_sama_berhasil(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
        ]);

        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->crack->id, 'qty' => 1],
        ]);

        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 9],
        ]);

        $fresh = $this->trx->fresh();
        $this->assertCount(1, $fresh->ngDetails);
        $this->assertSame(9, (int) $fresh->qty_ng);
    }

    public function test_baris_kosong_dan_qty_nol_diabaikan(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ['id_jenis_ng' => '', 'qty' => 3],
            ['id_jenis_ng' => $this->crack->id, 'qty' => 0],
        ]);

        $this->assertCount(1, $this->trx->fresh()->ngDetails);
        $this->assertSame(5, (int) $this->trx->fresh()->qty_ng);
    }

    public function test_array_kosong_menghasilkan_qty_ng_nol(): void
    {
        $this->service->syncProduksi($this->trx, []);

        $this->assertCount(0, $this->trx->fresh()->ngDetails);
        $this->assertSame(0, (int) $this->trx->fresh()->qty_ng);
    }

    public function test_available_true_saat_kedua_tabel_detail_ada(): void
    {
        $this->assertTrue(NgDetailService::available());
    }
}
