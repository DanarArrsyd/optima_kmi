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
 * Filter mesin multi-select ("Semua Mesin" grouped by line) di VCB Produksi
 * (Mode 1 & 2) dan VCB Mesin — ganti filter LINE lama (PR #177). Nol migrasi;
 * `transaksi_produksi.id_mesin` sudah ada sejak awal.
 */
class VcbMesinFilterTest extends TestCase
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
     * Dua operator, masing-masing di mesin beda, tanggal sama —
     * dipakai lintas endpoint (Mode 1, Mode 2, VCB Mesin).
     */
    private function duaMesin(): array
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

    public function test_vcb_mesin_data_disaring_oleh_mesin_id(): void
    {
        $m = $this->duaMesin();

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'mesin_ids' => [$m['mesinB']->id],
        ]))->json();

        $this->assertSame(['MESIN B'], $json['labels']);
    }

    public function test_vcb_mesin_data_menerima_multi_mesin(): void
    {
        $m = $this->duaMesin();

        // Mesin ketiga TIDAK diikutkan di filter — kalau filter benar-benar
        // tercabut, labels bakal 3 baris dan ikut memuat MESIN C.
        $mesinC = MasterMesin::create(['id_mesin' => 'C-'.uniqid(), 'nama_mesin' => 'MESIN C', 'line' => 'C', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $opC = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR C', 'departemen' => 'Produksi', 'is_aktif' => true]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $m['shift']->id, 'id_karyawan' => $opC->id,
            'id_mesin' => $mesinC->id, 'id_part_proses' => $m['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 500, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
            'mesin_ids' => [$m['mesinA']->id, $m['mesinB']->id],
        ]))->json();

        $this->assertCount(2, $json['labels']);
        $this->assertEqualsCanonicalizing(['MESIN A', 'MESIN B'], $json['labels']);
    }

    public function test_vcb_mesin_menampilkan_dropdown_mesin_grouped_by_line(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString('mesinFilterComp(_mesinForJs, [])', $html);
        $this->assertStringContainsString('const _mesinForJs =', $html);
        $this->assertStringContainsString('Line A', $html);
        $this->assertStringContainsString('Line B', $html);
        $this->assertStringContainsString('Semua Mesin', $html);
    }

    public function test_vcb_produksi_data_disaring_oleh_mesin_id(): void
    {
        $m = $this->duaMesin();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'mesin_ids' => [$m['mesinA']->id],
        ]))->json();

        $this->assertSame(['OPERATOR A'], $json['labels']);
    }

    public function test_vcb_produksi_data_tanpa_filter_mesin_menampilkan_semua(): void
    {
        $this->duaMesin();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
        ]))->json();

        $this->assertCount(2, $json['labels']);
    }

    public function test_vcb_produksi_operator_data_disaring_oleh_mesin_id(): void
    {
        $m = $this->duaMesin();

        // Operator A dapat sesi tambahan di mesin B, tanggal beda.
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-21', 'id_shift' => $m['shift']->id, 'id_karyawan' => $m['opA']->id,
            'id_mesin' => $m['mesinB']->id, 'id_part_proses' => $m['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 1000, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.produksi.operator-data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-21',
            'karyawan_id' => $m['opA']->id, 'mesin_ids' => [$m['mesinA']->id],
        ]))->json();

        $this->assertCount(1, $json['table']);
        $this->assertSame('20/08/26', $json['table'][0]['tanggal']);
    }

    public function test_vcb_produksi_menampilkan_dropdown_mesin_di_kedua_form(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.produksi'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'mesinFilterComp(_mesinForJs, [])'));
        $this->assertStringContainsString('const _mesinForJs =', $html);
        $this->assertSame(2, substr_count($html, 'input[name="mesin_ids[]"]'));
        $this->assertSame(4, substr_count($html, 'name="mesin_ids[]"'));
    }
}
