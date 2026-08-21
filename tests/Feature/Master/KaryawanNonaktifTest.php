<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tanggal_nonaktif hanya berarti saat is_aktif = false. Tiap jalur yang
 * mengaktifkan kembali seseorang wajib mengosongkannya — tanggal basi yang
 * menempel pada orang aktif akan menyaringnya keluar dari dropdown VCB tanpa
 * ada yang sadar.
 */
class KaryawanNonaktifTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_tanggal_nonaktif_tersimpan_dan_ter_cast(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N9',
            'nama_karyawan' => 'ORANG KELUAR',
            'departemen' => 'Produksi',
            'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $this->assertSame('2026-06-12', $k->fresh()->tanggal_nonaktif->toDateString());
    }
}
