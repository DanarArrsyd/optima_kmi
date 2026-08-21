<?php

namespace Tests\Feature\Master;

use App\Imports\KaryawanImport;
use App\Models\MasterKaryawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
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

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function karyawanAktif(): MasterKaryawan
    {
        return MasterKaryawan::create([
            'nik' => 'N1', 'nama_karyawan' => 'ORANG SATU',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
    }

    public function test_menonaktifkan_menyimpan_tanggal_yang_dikirim(): void
    {
        $k = $this->karyawanAktif();

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k), ['tanggal_nonaktif' => '2026-06-12'])
            ->assertRedirect();

        $k->refresh();
        $this->assertFalse($k->is_aktif);
        $this->assertSame('2026-06-12', $k->tanggal_nonaktif->toDateString());
    }

    public function test_mengaktifkan_kembali_mengosongkan_tanggal(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N2', 'nama_karyawan' => 'ORANG DUA',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k))
            ->assertRedirect();

        $k->refresh();
        $this->assertTrue($k->is_aktif);
        $this->assertNull($k->tanggal_nonaktif);
    }

    public function test_tanggal_masa_depan_ditolak(): void
    {
        $k = $this->karyawanAktif();

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k), [
                'tanggal_nonaktif' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('tanggal_nonaktif');

        $this->assertTrue($k->fresh()->is_aktif);
    }

    public function test_tanpa_tanggal_jatuh_ke_hari_ini(): void
    {
        $k = $this->karyawanAktif();

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k))
            ->assertRedirect();

        $this->assertSame(today()->toDateString(), $k->fresh()->tanggal_nonaktif->toDateString());
    }

    /** Ambil isi grid saja — nama karyawan juga muncul di dock filter dan laci. */
    private function grid(string $html): string
    {
        $awal = strpos($html, 'class="erp-scroll');
        $this->assertNotFalse($awal, 'Wadah .erp-scroll tidak ditemukan.');

        return substr($html, $awal, strpos($html, '</table>', $awal) - $awal);
    }

    public function test_kolom_nonaktif_sejak_tampil_di_tabel(): void
    {
        MasterKaryawan::create([
            'nik' => 'N7', 'nama_karyawan' => 'ORANG TUJUH',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('master.karyawan.index'))->assertOk()->getContent();

        $grid = $this->grid($html);
        $this->assertStringContainsString('Nonaktif Sejak', $grid);
        $this->assertStringContainsString('12/06/2026', $grid);
    }

    public function test_karyawan_aktif_menampilkan_strip(): void
    {
        MasterKaryawan::create([
            'nik' => 'N8', 'nama_karyawan' => 'ORANG DELAPAN',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('master.karyawan.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('12/06/2026', $this->grid($html));
    }

    public function test_form_nonaktif_menyediakan_input_tanggal(): void
    {
        $this->karyawanAktif();

        $html = $this->actingAs($this->admin())
            ->get(route('master.karyawan.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="tanggal_nonaktif"', $html);
    }

    public function test_import_mengaktifkan_sekaligus_mengosongkan_tanggal(): void
    {
        MasterKaryawan::create([
            'nik' => 'N5', 'nama_karyawan' => 'ORANG LIMA',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        (new KaryawanImport)->collection(new Collection([
            new Collection(['nik' => 'N5', 'nama_karyawan' => 'ORANG LIMA', 'departemen' => 'Produksi']),
        ]));

        $k = MasterKaryawan::where('nama_karyawan', 'ORANG LIMA')->first();
        $this->assertTrue($k->is_aktif);
        $this->assertNull($k->tanggal_nonaktif);
    }
}
