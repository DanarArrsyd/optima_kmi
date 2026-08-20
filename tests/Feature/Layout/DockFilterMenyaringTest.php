<?php

namespace Tests\Feature\Layout;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiQc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dock filter di halaman QC, Breakdown Mesin, dan Breakdown Dies.
 *
 * Yang diuji bukan tampilannya, melainkan bahwa tiap parameter benar-benar
 * MENYARING. Filter yang tampil rapi tapi tidak mempersempit hasil adalah
 * kegagalan senyap: pengguna mengira sedang melihat data tersaring.
 *
 * Asersinya dijangkarkan ke .erp-scroll, bukan seluruh HTML — nama orang dan
 * mesin juga muncul di daftar pilihan dock, jadi memeriksa seluruh halaman
 * membuat asersi yang tidak pernah bisa gagal.
 */
class DockFilterMenyaringTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private array $d = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00']);

        $insA = MasterKaryawan::create(['nik' => 'Q1', 'nama_karyawan' => 'INSPEKTUR ALFA', 'departemen' => 'QC', 'is_aktif' => true]);
        $insB = MasterKaryawan::create(['nik' => 'Q2', 'nama_karyawan' => 'INSPEKTUR BETA', 'departemen' => 'QC', 'is_aktif' => true]);

        $mA = MasterMesin::create(['id_mesin' => 'MA-1', 'nama_mesin' => 'MESIN ALFA', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mB = MasterMesin::create(['id_mesin' => 'MB-1', 'nama_mesin' => 'MESIN BETA', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $pA = MasterPart::create(['part_no' => 'PN-A', 'part_name' => 'PART ALFA', 'customer' => 'Honda']);
        $pB = MasterPart::create(['part_no' => 'PN-B', 'part_name' => 'PART BETA', 'customer' => 'Toyota']);
        $ppA = MasterPartProses::create(['id_part' => $pA->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 100, 'sph_standar_qc' => 50]);
        $ppB = MasterPartProses::create(['id_part' => $pB->id, 'id_proses' => 2, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 100, 'sph_standar_qc' => 50]);

        $ngA = MasterJenisNg::create(['kode' => 'NG1', 'nama_ng' => 'BURR']);

        $qc = fn ($ins, $pp) => TransaksiQc::create([
            'tgl_pengecekan' => now()->toDateString(), 'tgl_produksi_part' => now()->toDateString(),
            'id_shift' => $shift->id, 'id_karyawan_qc' => $ins->id, 'id_part_proses' => $pp->id,
            'jam_start' => '07:00:00', 'jam_finish' => '15:00:00',
            'qty_ok' => 100, 'qty_ng' => 0, 'created_by' => $this->user->id,
        ]);
        $qc($insA, $ppA);
        $qc($insB, $ppB);

        $bm = fn ($no, $mes, $pic, $st) => TransaksiBreakdownMesin::create([
            'no_lkm' => $no, 'id_mesin' => $mes->id,
            'tanggal_breakdown' => now()->toDateString(), 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => now()->toDateString(), 'jam_mulai_perbaikan' => '08:10',
            'tanggal_selesai_perbaikan' => now()->toDateString(), 'jam_selesai_perbaikan' => '09:00',
            'problem_mesin' => 'P', 'penyebab_breakdown' => 'C', 'tindakan_perbaikan' => 'T',
            'status_breakdown' => $st, 'pic_maintenance' => $pic, 'repair_time' => 0.8, 'total_downtime' => 1,
        ]);
        $bm('LKM-ALFA', $mA, 'PIC ALFA', 'Ringan');
        $bm('LKM-BETA', $mB, 'PIC BETA', 'Berat');

        $bd = fn ($no, $pp, $pic, $st) => TransaksiBreakdownDies::create([
            'no_lkd' => $no, 'id_part_proses' => $pp->id,
            'tanggal_breakdown' => now()->toDateString(), 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => now()->toDateString(), 'jam_mulai_perbaikan' => '08:10',
            'tanggal_selesai_perbaikan' => now()->toDateString(), 'jam_selesai_perbaikan' => '09:00',
            'problem_dies' => 'P', 'penyebab_breakdown' => 'C', 'tindakan_perbaikan' => 'T',
            'status_breakdown' => $st, 'pic_maintenance' => $pic, 'repair_time' => 0.8, 'total_downtime' => 1,
        ]);
        $bd('LKD-ALFA', $ppA, 'PIC ALFA', 'Ringan');
        $bd('LKD-BETA', $ppB, 'PIC BETA', 'Berat');

        $this->d = compact('insA', 'mA', 'pA', 'ppA', 'ngA');
    }

    public static function kasus(): array
    {
        return [
            'QC inspector' => ['input.qc.index', 'filter_karyawan_id', 'insA', 'id', 'INSPEKTUR ALFA', 'INSPEKTUR BETA'],
            'QC part' => ['input.qc.index', 'filter_part_id', 'pA', 'id', 'PART ALFA', 'PART BETA'],
            'QC customer' => ['input.qc.index', 'filter_customer', null, 'Honda', 'PART ALFA', 'PART BETA'],
            'Mesin: mesin' => ['maintenance.breakdown.index', 'mesin_id', 'mA', 'id', 'LKM-ALFA', 'LKM-BETA'],
            'Mesin: PIC' => ['maintenance.breakdown.index', 'filter_pic', null, 'PIC ALFA', 'LKM-ALFA', 'LKM-BETA'],
            'Mesin: status' => ['maintenance.breakdown.index', 'filter_status', null, 'Ringan', 'LKM-ALFA', 'LKM-BETA'],
            'Dies: part' => ['maintenance.breakdown-dies.index', 'part_id', 'pA', 'id', 'LKD-ALFA', 'LKD-BETA'],
            'Dies: PIC' => ['maintenance.breakdown-dies.index', 'filter_pic', null, 'PIC ALFA', 'LKD-ALFA', 'LKD-BETA'],
            'Dies: status' => ['maintenance.breakdown-dies.index', 'filter_status', null, 'Ringan', 'LKD-ALFA', 'LKD-BETA'],
            'Dies: customer' => ['maintenance.breakdown-dies.index', 'filter_customer', null, 'Honda', 'LKD-ALFA', 'LKD-BETA'],
        ];
    }

    #[DataProvider('kasus')]
    public function test_filter_menyaring(string $route, string $param, ?string $kunci, string $nilai, string $ada, string $tiada): void
    {
        $v = $kunci === null ? $nilai : $this->d[$kunci]->{$nilai};
        $grid = $this->grid($route, [$param => $v]);

        $this->assertStringContainsString($ada, $grid, "{$route}?{$param}: baris yang cocok hilang");
        $this->assertStringNotContainsString($tiada, $grid, "{$route}?{$param}: baris yang TIDAK cocok masih tampil — filter tidak menyaring");
    }

    public static function halamanBercentang(): array
    {
        return [
            'LHP Produksi' => ['input.produksi.index'],
            'LHP Quality' => ['input.qc.index'],
            'Breakdown Mesin' => ['maintenance.breakdown.index'],
            'Breakdown Dies' => ['maintenance.breakdown-dies.index'],
        ];
    }

    #[DataProvider('halamanBercentang')]
    public function test_punya_centang_filter_by_date(string $route): void
    {
        $html = $this->actingAs($this->user)->get(route($route))->assertOk()->getContent();

        $this->assertStringContainsString('name="pakai_tanggal"', $html, "{$route}: centang Filter by Date tidak ada");
        $this->assertStringContainsString('name="filter_apply"', $html, "{$route}: penanda filter_apply tidak ada — centang yang dilepas tak akan terkirim");
    }

    private function grid(string $route, array $query): string
    {
        $html = $this->actingAs($this->user)->get(route($route, $query))->assertOk()->getContent();
        $awal = strpos($html, 'class="erp-scroll"');
        $this->assertNotFalse($awal, 'grid data tidak ditemukan');

        return substr($html, $awal, strpos($html, '</table>', $awal) - $awal);
    }
}
