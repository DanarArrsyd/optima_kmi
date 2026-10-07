<?php

namespace Tests\Feature\Layout;

use App\Models\MasterMesin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pesan tabel kosong harus membedakan "tidak cocok filter" dari "memang belum
 * ada data". Dulu cari "zzz" di Master Mesin menampilkan "Belum ada data
 * mesin." walau tabelnya berisi — pengguna mengira datanya hilang.
 */
class TabelKosongTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_filter_tanpa_hasil_bilang_tidak_cocok_dan_tawarkan_reset(): void
    {
        MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'PRESS', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 1]);

        $this->get(route('master.mesin.index', ['q' => 'zzz']))->assertOk()
            ->assertSee('Tidak ada data mesin yang cocok dengan filter.')
            ->assertSee('href="'.route('master.mesin.index').'" class="erp-btn">Reset filter</a>', false)
            ->assertDontSee('Belum ada data mesin.');
    }

    public function test_tabel_benar_benar_kosong_tawarkan_tambah(): void
    {
        $this->get(route('master.mesin.index'))->assertOk()
            ->assertSee('Belum ada data mesin.')
            ->assertSee('href="'.route('master.mesin.create').'" class="erp-btn">Tambah</a>', false)
            ->assertDontSee('yang cocok dengan filter');
    }

    public function test_halaman_bertanggal_tanpa_filter_menyebut_periode(): void
    {
        $this->get(route('input.produksi.index'))->assertOk()
            ->assertSee('Belum ada data LHP Produksi pada periode ini.');

        $this->get(route('input.produksi.index', ['filter_karyawan_id' => 999]))->assertOk()
            ->assertSee('Tidak ada data LHP Produksi yang cocok dengan filter.');
    }

    public function test_tidak_ada_lagi_baris_kosong_tulis_tangan_di_halaman_daftar(): void
    {
        $tulisTangan = [];
        foreach (glob(resource_path('views/{input,master,maintenance}/*/index.blade.php'), GLOB_BRACE) as $f) {
            $isi = file_get_contents($f);
            if (str_contains($isi, '@empty') && ! preg_match('/@empty\s*<x-tabel-kosong/', $isi)) {
                $tulisTangan[] = str_replace(resource_path('views').'/', '', $f);
            }
        }

        $this->assertSame([], $tulisTangan, 'pakai x-tabel-kosong');
    }
}
