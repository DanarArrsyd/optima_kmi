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
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgDetailTableTest extends TestCase
{
    use RefreshDatabase;

    private function produksi(): TransaksiProduksi
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op NG', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'NG-01', 'nama_mesin' => 'Press NG', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'NG-P1', 'part_name' => 'NG Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $user = User::factory()->create();

        return TransaksiProduksi::create([
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
    }

    public function test_relasi_ng_details_mengembalikan_baris_detail(): void
    {
        $trx = $this->produksi();
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 2]);

        $this->assertCount(2, $trx->fresh()->ngDetails);
        $this->assertSame(7, (int) $trx->fresh()->ngDetails->sum('qty'));
        $this->assertSame('Dented', $trx->fresh()->ngDetails->first()->jenisNg->nama_ng);
    }

    public function test_jenis_ng_yang_sama_dua_kali_ditolak_database(): void
    {
        $trx = $this->produksi();
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);

        $this->expectException(QueryException::class);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 3]);
    }

    public function test_baris_detail_tidak_memakai_soft_deletes(): void
    {
        $trx = $this->produksi();
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        $row = TransaksiProduksiNg::create([
            'id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5,
        ]);
        $row->delete();

        // Hapus lalu tambah jenis yang sama harus berhasil — inilah alasan
        // tabel detail sengaja tidak memakai SoftDeletes.
        TransaksiProduksiNg::create([
            'id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 3,
        ]);

        $this->assertSame(1, TransaksiProduksiNg::where('id_transaksi_produksi', $trx->id)->count());
    }
}
