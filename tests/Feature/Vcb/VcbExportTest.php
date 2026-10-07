<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Export Excel VCB Produksi / Quality / Mesin.
 *
 * Tombolnya dulu menunjuk ke '/export/produksi' & '/export/qc' — rute yang
 * ikut terhapus bersama ExportController (Jun 2026). 404 sejak itu tanpa ada
 * yang lapor. Test di sini membaca ISI file, bukan cuma status 200, dan
 * mengunci bahwa view tidak lagi menunjuk ke rute hantu.
 */
class VcbExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function fixture(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $part = MasterPart::create(['part_no' => 'PN-01', 'part_name' => 'BRACKET', 'customer' => 'CUST-X']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 400]);
        $mesinA = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mesinB = MasterMesin::create(['id_mesin' => 'B1', 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $opA = MasterKaryawan::create(['nik' => 'N1', 'nama_karyawan' => 'OPERATOR A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $opB = MasterKaryawan::create(['nik' => 'N2', 'nama_karyawan' => 'OPERATOR B', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $insp = MasterKaryawan::create(['nik' => 'N3', 'nama_karyawan' => 'INSPECTOR Q', 'departemen' => 'QC', 'is_aktif' => true]);

        foreach ([[$opA, $mesinA, 4000], [$opB, $mesinB, 2000]] as [$op, $mesin, $qty]) {
            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $op->id,
                'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $qty, 'created_by' => $this->admin->id,
            ]);
        }
        TransaksiQc::create([
            'tgl_pengecekan' => '2026-08-20', 'tgl_produksi_part' => '2026-08-19', 'id_shift' => $shift->id, 'id_karyawan_qc' => $insp->id,
            'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_ok' => 3000, 'qty_ng' => 12, 'created_by' => $this->admin->id,
        ]);

        return compact('opA', 'opB', 'insp', 'mesinA', 'mesinB');
    }

    /** @return list<list<mixed>> */
    private function isi(TestResponse $res): array
    {
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));

        return IOFactory::load($res->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
    }

    private function kolom(array $isi, int $i): array
    {
        return array_column(array_slice($isi, 1), $i);
    }

    public function test_produksi_mode_1_memuat_semua_operator(): void
    {
        $this->fixture();
        $isi = $this->isi($this->get(route('vcb.produksi.export', ['date_from' => '2026-08-20', 'date_to' => '2026-08-20'])));

        $this->assertSame('Operator', $isi[0][0]);
        $this->assertEqualsCanonicalizing(['OPERATOR A', 'OPERATOR B'], $this->kolom($isi, 0));
    }

    public function test_produksi_mode_1_menghormati_filter_mesin(): void
    {
        $f = $this->fixture();
        $isi = $this->isi($this->get(route('vcb.produksi.export', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'mesin_ids' => [$f['mesinB']->id],
        ])));

        $this->assertSame(['OPERATOR B'], $this->kolom($isi, 0));
    }

    public function test_produksi_mode_2_satu_baris_per_sesi_operator(): void
    {
        $f = $this->fixture();
        $isi = $this->isi($this->get(route('vcb.produksi.export', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'karyawan_id' => $f['opA']->id,
        ])));

        $this->assertSame('Tanggal', $isi[0][0]);
        $this->assertCount(2, $isi); // header + 1 sesi
        $this->assertContains('A1', $isi[1]);
        $this->assertContains('PN-01', $isi[1]);
    }

    public function test_quality_mode_1_dan_mode_2(): void
    {
        $f = $this->fixture();
        // date_to +1 hari: cast 'date' di SQLite menyimpan '2026-08-20 00:00:00',
        // yang jatuh di luar whereBetween satu hari. Kolom MySQL prod bertipe DATE.
        $semua = $this->isi($this->get(route('vcb.quality.export', ['date_from' => '2026-08-20', 'date_to' => '2026-08-21'])));
        $this->assertSame(['INSPECTOR Q'], $this->kolom($semua, 0));

        $satu = $this->isi($this->get(route('vcb.quality.export', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-21', 'karyawan_id' => $f['insp']->id,
        ])));
        $this->assertCount(2, $satu);
        $this->assertSame(3000, (int) $satu[1][6]); // kolom Qty OK
    }

    public function test_mesin_memuat_baris_per_mesin(): void
    {
        $this->fixture();
        $isi = $this->isi($this->get(route('vcb.mesin.export', ['date_from' => '2026-08-20', 'date_to' => '2026-08-20'])));

        $this->assertEqualsCanonicalizing(['A1', 'B1'], $this->kolom($isi, 0));
    }

    public function test_guest_tidak_boleh_export(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->get(route('vcb.produksi.export'))->assertForbidden();
        $this->get(route('vcb.quality.export'))->assertForbidden();
        $this->get(route('vcb.mesin.export'))->assertForbidden();
    }

    /** Rute '/export/...' sudah tidak ada sejak Jun 2026 — view tak boleh menunjuknya lagi. */
    public function test_view_tidak_menunjuk_rute_export_hantu(): void
    {
        foreach (['vcb.produksi', 'vcb.quality', 'vcb.mesin'] as $halaman) {
            $html = $this->get(route($halaman))->assertOk()->getContent();
            $this->assertStringNotContainsString('/export/produksi', $html, $halaman);
            $this->assertStringNotContainsString('/export/qc', $html, $halaman);
        }
    }
}
