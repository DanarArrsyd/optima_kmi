<?php

namespace Tests\Feature;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Permintaan user Okt 2026: ekspor Excel di SETIAP halaman daftar/analitik,
 * tanpa pengecualian. Penjaga di sini merah kalau halaman baru (atau lama)
 * tidak menawarkan tombol Export ke admin.
 *
 * Pengecualian sengaja: halaman form/edit, Overview (ringkasan satu layar),
 * AI Assistant, Backup, QR Stasiun, Jadwal Preventive (sudah punya PDF).
 */
class EksporSemuaHalamanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Admin Uji']));
    }

    public static function halaman(): array
    {
        return array_map(fn ($r) => [$r], [
            'input.produksi.index' => 'input.produksi.index', 'input.qc.index' => 'input.qc.index',
            'input.dandori.index' => 'input.dandori.index', 'input.repair.index' => 'input.repair.index',
            'input.repair-produksi.index' => 'input.repair-produksi.index',
            'maintenance.breakdown.index' => 'maintenance.breakdown.index', 'maintenance.breakdown-dies.index' => 'maintenance.breakdown-dies.index',
            'vcb.produksi' => 'vcb.produksi', 'vcb.quality' => 'vcb.quality', 'vcb.mesin' => 'vcb.mesin', 'vcb.dandori' => 'vcb.dandori',
            'vcb.ng-proses' => 'vcb.ng-proses', 'vcb.ng-total' => 'vcb.ng-total',
            'vcb.ng-proses.daftar' => 'vcb.ng-proses.daftar', 'vcb.ng-total.daftar' => 'vcb.ng-total.daftar',
            'maintenance.mttr' => 'maintenance.mttr', 'maintenance.mtbf' => 'maintenance.mtbf', 'maintenance.availability' => 'maintenance.availability',
            'maintenance.mttr-dies' => 'maintenance.mttr-dies', 'maintenance.mtbf-dies' => 'maintenance.mtbf-dies',
            'maintenance.availability-dies' => 'maintenance.availability-dies',
            'maintenance.control-stroke-mesin' => 'maintenance.control-stroke-mesin', 'maintenance.control-stroke-dies' => 'maintenance.control-stroke-dies',
            'master.mesin.index' => 'master.mesin.index', 'master.karyawan.index' => 'master.karyawan.index', 'master.part.index' => 'master.part.index',
            'master.shift.index' => 'master.shift.index', 'master.losstime.index' => 'master.losstime.index', 'master.jenis-ng.index' => 'master.jenis-ng.index',
            'master.users.index' => 'master.users.index', 'master.proses-repair.index' => 'master.proses-repair.index',
            'master.proses-repair-produksi.index' => 'master.proses-repair-produksi.index',
            'admin.anomali' => 'admin.anomali', 'sistem.log-aktivitas.index' => 'sistem.log-aktivitas.index',
        ]);
    }

    #[DataProvider('halaman')]
    public function test_halaman_menawarkan_export_excel(string $route): void
    {
        $this->get(route($route))->assertOk()->assertSee('Export Excel');
    }

    /** @return list<list<mixed>> */
    private function isi(TestResponse $res): array
    {
        $res->assertOk();

        return IOFactory::load($res->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
    }

    public function test_ekspor_anomali_menghormati_filter_jenis(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00']);
        $op = MasterKaryawan::create(['nik' => 'P1', 'nama_karyawan' => 'OP', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'PRESS', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 1]);
        $part = MasterPart::create(['part_no' => 'PN-1', 'part_name' => 'PART']);
        $tanpaStd = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'NOSTD', 'sph_standar_produksi' => 0]);
        $std = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'TINGGI', 'sph_standar_produksi' => 10]);
        foreach ([$tanpaStd, $std] as $pr) {
            TransaksiProduksi::create([
                'tanggal_produksi' => now()->toDateString(), 'id_shift' => $shift->id, 'id_karyawan' => $op->id, 'id_mesin' => $mesin->id,
                'id_part_proses' => $pr->id, 'jam_start' => '07:00', 'jam_finish' => '08:00', 'qty_aktual' => 5000, 'created_by' => auth()->id(),
            ]);
        }

        $semua = $this->isi($this->get(route('admin.anomali.export')));
        $noStd = $this->isi($this->get(route('admin.anomali.export', ['reason' => 'no_std'])));

        $this->assertCount(3, $semua);
        $this->assertCount(2, $noStd);
        $this->assertSame('NOSTD', $noStd[1][6]);
    }

    public function test_ekspor_log_menghormati_filter_dan_waktu_wib(): void
    {
        activity('master_mesin')->event('created')->causedBy(auth()->user())->log('Mesin ALFA dibuat');
        activity('master_part')->event('created')->causedBy(auth()->user())->log('Part BETA dibuat');

        $isi = $this->isi($this->get(route('sistem.log-aktivitas.export', ['modul' => 'master_mesin'])));

        $this->assertSame('Waktu (WIB)', $isi[0][0]);
        $deskripsi = array_column(array_slice($isi, 1), 4);
        $this->assertContains('Mesin ALFA dibuat', $deskripsi);
        $this->assertNotContains('Part BETA dibuat', $deskripsi);
    }

    public function test_ekspor_anomali_dan_log_hanya_admin(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'manager']));

        $this->get(route('admin.anomali.export'))->assertForbidden();
        $this->get(route('sistem.log-aktivitas.export'))->assertForbidden();
    }
}
