<?php

namespace Tests\Feature\Master;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dock filter di tujuh halaman Master Data.
 *
 * Yang diuji bahwa tiap parameter benar-benar MENYARING, bukan sekadar
 * tampil. Filter yang rapi tapi tidak mempersempit hasil adalah kegagalan
 * senyap: pengguna mengira sedang melihat data tersaring.
 *
 * Asersinya dijangkarkan ke .erp-scroll — nama dan kode juga muncul di
 * daftar pilihan dock maupun di laci tambah/edit, jadi memeriksa seluruh
 * HTML membuat asersi yang tidak pernah bisa gagal.
 */
class MasterFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'ADMIN SATU']);
        User::factory()->create(['role' => 'manager', 'name' => 'MANAJER DUA']);

        MasterMesin::create(['id_mesin' => 'AA-1', 'nama_mesin' => 'MESIN ALFA', 'line' => 'LA', 'tipe_mesin' => 'TIPE-A', 'kapasitas_max_stroke' => 100]);
        MasterMesin::create(['id_mesin' => 'BB-1', 'nama_mesin' => 'MESIN BETA', 'line' => 'LB', 'tipe_mesin' => 'TIPE-B', 'kapasitas_max_stroke' => 100]);

        MasterKaryawan::create(['nik' => 'N1', 'nama_karyawan' => 'ORANG ALFA', 'departemen' => 'Produksi', 'is_aktif' => true]);
        MasterKaryawan::create(['nik' => 'N2', 'nama_karyawan' => 'ORANG BETA', 'departemen' => 'QC', 'is_aktif' => false]);

        MasterLosstime::create(['kode' => 'LA1', 'jenis_losstime' => 'LOSS ALFA', 'std_menit_default' => 10]);
        MasterLosstime::create(['kode' => 'LB1', 'jenis_losstime' => 'LOSS BETA', 'std_menit_default' => 20]);

        MasterJenisNg::create(['kode' => 'GA1', 'nama_ng' => 'NG ALFA', 'kategori' => 'KAT-A']);
        MasterJenisNg::create(['kode' => 'GB1', 'nama_ng' => 'NG BETA', 'kategori' => 'KAT-B']);

        MasterShift::create(['nama_shift' => 'SHIFT ALFA', 'jam_start' => '07:00', 'jam_finish' => '15:00', 'dihitung_availability' => true]);
        MasterShift::create(['nama_shift' => 'SHIFT BETA', 'jam_start' => '15:00', 'jam_finish' => '23:00', 'dihitung_availability' => false]);

        $pA = MasterPart::create(['part_no' => 'PA-1', 'part_name' => 'PART ALFA', 'customer' => 'CUST-A']);
        $pB = MasterPart::create(['part_no' => 'PB-1', 'part_name' => 'PART BETA', 'customer' => 'CUST-B']);
        MasterPartProses::create(['id_part' => $pA->id, 'id_proses' => 1, 'nama_proses' => 'PROSES ALFA', 'sph_standar_produksi' => 100, 'sph_standar_qc' => 50]);
        MasterPartProses::create(['id_part' => $pB->id, 'id_proses' => 2, 'nama_proses' => 'PROSES BETA', 'sph_standar_produksi' => 100, 'sph_standar_qc' => 50]);
    }

    public static function kasus(): array
    {
        return [
            'Mesin: cari' => ['master.mesin.index', ['q' => 'ALFA'], 'MESIN ALFA', 'MESIN BETA'],
            'Mesin: line' => ['master.mesin.index', ['filter_line' => 'LA'], 'MESIN ALFA', 'MESIN BETA'],
            'Mesin: tipe' => ['master.mesin.index', ['filter_tipe' => 'TIPE-A'], 'MESIN ALFA', 'MESIN BETA'],
            'Karyawan: cari' => ['master.karyawan.index', ['q' => 'ALFA'], 'ORANG ALFA', 'ORANG BETA'],
            'Karyawan: dept' => ['master.karyawan.index', ['filter_dept' => 'Produksi'], 'ORANG ALFA', 'ORANG BETA'],
            'Karyawan: status' => ['master.karyawan.index', ['filter' => 'aktif'], 'ORANG ALFA', 'ORANG BETA'],
            'Losstime: cari' => ['master.losstime.index', ['q' => 'ALFA'], 'LOSS ALFA', 'LOSS BETA'],
            'JenisNG: cari' => ['master.jenis-ng.index', ['q' => 'ALFA'], 'NG ALFA', 'NG BETA'],
            'JenisNG: kategori' => ['master.jenis-ng.index', ['filter_kategori' => 'KAT-A'], 'NG ALFA', 'NG BETA'],
            'Shift: cari' => ['master.shift.index', ['q' => 'ALFA'], 'SHIFT ALFA', 'SHIFT BETA'],
            'Shift: availability' => ['master.shift.index', ['filter_avail' => 'ya'], 'SHIFT ALFA', 'SHIFT BETA'],
            'Users: cari' => ['master.users.index', ['q' => 'ADMIN'], 'ADMIN SATU', 'MANAJER DUA'],
            'Users: role' => ['master.users.index', ['filter_role' => 'admin'], 'ADMIN SATU', 'MANAJER DUA'],
            'Part: cari' => ['master.part.index', ['q' => 'ALFA'], 'PART ALFA', 'PART BETA'],
            'Part: customer' => ['master.part.index', ['filter_customer' => 'CUST-A'], 'PART ALFA', 'PART BETA'],
        ];
    }

    #[DataProvider('kasus')]
    public function test_filter_menyaring(string $route, array $query, string $ada, string $tiada): void
    {
        $grid = $this->grid($route, $query);

        $this->assertStringContainsString($ada, $grid, "{$route}: baris yang cocok hilang");
        $this->assertStringNotContainsString($tiada, $grid, "{$route}: baris yang TIDAK cocok masih tampil — filter tidak menyaring");
    }

    private function grid(string $route, array $query): string
    {
        $html = $this->actingAs($this->admin)->get(route($route, $query))->assertOk()->getContent();
        $awal = strpos($html, 'class="erp-scroll');
        $this->assertNotFalse($awal, "grid data tidak ditemukan di {$route}");

        return substr($html, $awal, strpos($html, '</table>', $awal) - $awal);
    }
}
