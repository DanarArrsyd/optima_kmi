<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterJenisNgKategoriTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_kategori_ada_dan_bisa_diisi(): void
    {
        $this->assertTrue(Schema::hasColumn('master_jenis_ng', 'kategori'));

        $jenis = MasterJenisNg::create([
            'kode' => 'DNT',
            'nama_ng' => 'Dented',
            'kategori' => 'visual',
        ]);

        $this->assertSame('visual', $jenis->fresh()->kategori);
    }

    public function test_kategori_boleh_kosong(): void
    {
        $jenis = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        $this->assertNull($jenis->fresh()->kategori);
    }
}
