<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter LINE (multi-select) di VCB Produksi (Mode 1 & 2) dan VCB Mesin.
 * `master_mesin.line` sudah ada sejak awal — filter ini query tambahan saja,
 * nol migrasi.
 */
class VcbLineFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    /**
     * Dua operator, masing-masing di mesin/line beda, tanggal sama —
     * dipakai lintas endpoint (Mode 1, Mode 2, VCB Mesin).
     */
    private function duaLine(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500]);

        $mesinA = MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mesinB = MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $opA = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $opB = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR B', 'departemen' => 'Produksi', 'is_aktif' => true]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $opA->id,
            'id_mesin' => $mesinA->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'created_by' => $this->admin->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $opB->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 2000, 'created_by' => $this->admin->id,
        ]);

        return compact('shift', 'proses', 'opA', 'opB', 'mesinA', 'mesinB');
    }

    public function test_vcb_produksi_data_disaring_oleh_line(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'line' => ['A'],
        ]))->json();

        $this->assertSame(['OPERATOR A'], $json['labels']);
    }

    public function test_vcb_produksi_data_tanpa_filter_line_menampilkan_semua(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
        ]))->json();

        $this->assertCount(2, $json['labels']);
    }

    public function test_vcb_produksi_operator_data_disaring_oleh_line(): void
    {
        $b = $this->duaLine();

        // Operator A dapat sesi tambahan di mesin B, tanggal beda.
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-21', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['opA']->id,
            'id_mesin' => $b['mesinB']->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 1000, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.produksi.operator-data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-21',
            'karyawan_id' => $b['opA']->id, 'line' => ['A'],
        ]))->json();

        $this->assertCount(1, $json['table']);
        $this->assertSame('20/08/26', $json['table'][0]['tanggal']);
    }

    public function test_vcb_mesin_data_disaring_oleh_line(): void
    {
        $this->duaLine();

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'line' => ['B'],
        ]))->json();

        $this->assertSame(['MESIN B'], $json['labels']);
    }

    public function test_vcb_mesin_data_menerima_multi_line(): void
    {
        $b = $this->duaLine();

        // Line ketiga TIDAK diikutkan di filter — kalau filter benar-benar tercabut,
        // labels bakal 3 baris dan ikut memuat MESIN C.
        $mesinC = MasterMesin::create(['id_mesin' => 'C-'.uniqid(), 'nama_mesin' => 'MESIN C', 'line' => 'C', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $opC = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR C', 'departemen' => 'Produksi', 'is_aktif' => true]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $b['shift']->id, 'id_karyawan' => $opC->id,
            'id_mesin' => $mesinC->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 500, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'line' => ['A', 'B'],
        ]))->json();

        $this->assertCount(2, $json['labels']);
        $labels = $json['labels'];
        sort($labels);
        $this->assertEqualsCanonicalizing(['MESIN A', 'MESIN B'], $labels);
    }

    public function test_vcb_mesin_menampilkan_checkbox_line_dari_master_data(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString('class="line-cb" value="A"', $html);
        $this->assertStringContainsString('class="line-cb" value="B"', $html);
        $this->assertStringContainsString("params.append('line[]'", $html);
    }

    public function test_vcb_produksi_menampilkan_checkbox_line_di_kedua_form(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.produksi'))->assertOk()->getContent();

        $this->assertStringContainsString('class="all-line-cb" value="A"', $html);
        $this->assertStringContainsString('class="one-line-cb" value="A"', $html);
        $this->assertStringContainsString("querySelectorAll('.all-line-cb:checked')", $html);
        $this->assertStringContainsString("querySelectorAll('.one-line-cb:checked')", $html);
    }
}
