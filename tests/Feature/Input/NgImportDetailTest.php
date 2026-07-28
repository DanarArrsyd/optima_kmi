<?php

namespace Tests\Feature\Input;

use App\Imports\ProduksiImport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class NgImportDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_satu_jenis_ng_membuat_satu_baris_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        MasterKaryawan::create([
            'nik' => 'IMP-1', 'nama_karyawan' => 'Op Import', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        MasterMesin::create([
            'id_mesin' => 'IM-01', 'nama_mesin' => 'Press Import', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'IM-P1', 'part_name' => 'Import Part', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        $import = new ProduksiImport;
        $import->collection(new Collection([
            new Collection([
                'tanggal_produksi' => '2026-07-28',
                'nama_shift' => 'Shift 1',
                'nama_operator' => 'Op Import',
                'id_mesin' => 'IM-01',
                'part_no' => 'IM-P1',
                'id_proses' => 1,
                'jam_start' => '08:00',
                'jam_finish' => '12:00',
                'qty_aktual' => 100,
                'qty_ng' => 5,
                'kode_jenis_ng' => 'DNT',
            ]),
        ]));

        $trx = TransaksiProduksi::latest('id')->first();

        $this->assertNotNull($trx, 'Baris produksi harus terbentuk dari import.');
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(5, (int) $trx->ngDetails->first()->qty);
        $this->assertSame(5, (int) $trx->qty_ng);
    }

    public function test_qty_ng_tanpa_kode_jenis_ditolak_bukan_disimpan_nol(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        MasterKaryawan::create([
            'nik' => 'IMP-2', 'nama_karyawan' => 'Op Import 2', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        MasterMesin::create([
            'id_mesin' => 'IM-02', 'nama_mesin' => 'Press Import 2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'IM-P2', 'part_name' => 'Import Part 2', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $import = new ProduksiImport;
        $import->collection(new Collection([
            new Collection([
                'tanggal_produksi' => '2026-07-28',
                'nama_shift' => 'Shift 1',
                'nama_operator' => 'Op Import 2',
                'id_mesin' => 'IM-02',
                'part_no' => 'IM-P2',
                'id_proses' => 1,
                'jam_start' => '08:00',
                'jam_finish' => '12:00',
                'qty_aktual' => 100,
                'qty_ng' => 5,
                'kode_jenis_ng' => '',
            ]),
        ]));

        // Baris ditolak, bukan disimpan dengan qty_ng = 0.
        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertNotEmpty($import->errors);
        $this->assertStringContainsString('Kode Jenis NG kosong', implode(' ', $import->errors));
    }
}
