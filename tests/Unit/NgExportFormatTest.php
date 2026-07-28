<?php

namespace Tests\Unit;

use App\Exports\LhpProduksiExport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgExportFormatTest extends TestCase
{
    use RefreshDatabase;

    private function seedProduksi(array $override = []): TransaksiProduksi
    {
        $user = User::factory()->create();
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Export', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'EX-'.substr(uniqid(), -4), 'nama_mesin' => 'Press Export', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create([
            'part_no' => 'EX-'.substr(uniqid(), -4), 'part_name' => 'Export Part', 'customer' => 'Toyota',
        ]);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

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
            ...$override,
        ]);
    }

    public function test_kolom_jenis_ng_menggabungkan_semua_jenis(): void
    {
        $trx = $this->seedProduksi(['qty_ng' => 7]);

        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 2]);

        $row = (new LhpProduksiExport)->collection()->first();

        $this->assertContains('DNT (5); CRK (2)', $row);
    }

    public function test_sesi_tanpa_ng_menghasilkan_kolom_kosong(): void
    {
        $this->seedProduksi();

        $row = (new LhpProduksiExport)->collection()->first();

        // Kolom jenis NG ada di posisi 14 (setelah qty_ng), harus string kosong —
        // bukan '—' dan bukan '0'.
        $this->assertSame('', $row[14]);
    }

    public function test_baris_legacy_qty_ng_tanpa_detail_menghasilkan_kolom_kosong(): void
    {
        // Data lama: qty_ng terisi tapi jenisnya tidak pernah tercatat.
        // Kolom jenis kosong, tapi qty_ng-nya TIDAK boleh ikut hilang.
        $this->seedProduksi(['qty_ng' => 6]);

        $row = (new LhpProduksiExport)->collection()->first();

        $this->assertSame('', $row[14]);
        $this->assertSame(6, $row[13]);
    }

    public function test_accessor_ng_ringkas_dipakai_bersama(): void
    {
        $trx = $this->seedProduksi(['qty_ng' => 3]);
        $burry = MasterJenisNg::create(['kode' => 'BRY', 'nama_ng' => 'Burry']);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $burry->id, 'qty' => 3]);

        $this->assertSame('BRY (3)', $trx->fresh()->ng_ringkas);
    }
}
