<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan penyaringannya sendiri berjalan di JavaScript dan tidak bisa dijalankan
 * dari PHPUnit. Yang diuji di sini: tiap <option> membawa data-nonaktif yang
 * benar, dan penyaringnya benar-benar dipanggil di halaman. Jangan menulis
 * asersi yang seolah menguji perilaku JS — itu asersi yang tak pernah bisa gagal.
 */
class VcbOperatorAktifTest extends TestCase
{
    use RefreshDatabase;

    /** Ambil isi <select> operator saja, bukan seluruh halaman. */
    private function selectOperator(string $html): string
    {
        $awal = strpos($html, 'id="one_karyawan_id"');
        $this->assertNotFalse($awal, 'Select operator tidak ditemukan.');

        return substr($html, $awal, strpos($html, '</select>', $awal) - $awal);
    }

    private function html(string $route): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
    }

    public function test_operator_aktif_membawa_data_nonaktif_kosong(): void
    {
        MasterKaryawan::create([
            'nik' => 'P1', 'nama_karyawan' => 'OPERATOR AKTIF',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $select = $this->selectOperator($this->html('vcb.produksi'));

        $this->assertStringContainsString('OPERATOR AKTIF', $select);
        $this->assertStringContainsString('data-nonaktif=""', $select);
    }

    public function test_operator_nonaktif_membawa_tanggalnya(): void
    {
        MasterKaryawan::create([
            'nik' => 'P2', 'nama_karyawan' => 'OPERATOR KELUAR',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $select = $this->selectOperator($this->html('vcb.produksi'));

        $this->assertStringContainsString('data-nonaktif="2026-06-12"', $select);
    }

    public function test_penyaring_dipanggil_di_halaman_produksi(): void
    {
        $html = $this->html('vcb.produksi');

        $this->assertStringContainsString("saringOrangNonaktif('one_karyawan_id'", $html);
    }
}
