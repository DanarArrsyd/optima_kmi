<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User laporan: lagi meriksa LHP bulan Juli (rentang tanggal di-set manual
 * karena sekarang bulan September), edit satu baris (part name atau note),
 * submit — dilempar balik ke default bulan berjalan (September) alih-alih
 * balik ke Juli yang lagi diperiksa.
 *
 * Fix: link Edit di index membawa query string index saat ini lewat
 * `back_query`, halaman Edit menyimpannya di hidden field, controller
 * redirect ke index dengan query itu dikembalikan. Note/destroy (form-nya
 * nempel di index sendiri) pakai back() — otomatis kembali ke URL yang
 * sama lewat Referer browser.
 */
class EditRedirectPreservesFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'N-01', 'nama_mesin' => 'Press N', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'N-P1', 'part_name' => 'Panel N', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->ctx = compact('admin', 'shift', 'karyawan', 'mesin', 'proses');
    }

    public function test_update_produksi_kembali_ke_rentang_tanggal_juli_bukan_default_bulan_berjalan(): void
    {
        $r = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
        ]);

        $backQuery = http_build_query(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']);

        $response = $this->actingAs($this->ctx['admin'])->put(route('input.produksi.update', $r), [
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 90, 'ng_count' => 0,
            'back_query' => $backQuery,
        ]);

        $response->assertRedirect(route('input.produksi.index', [
            'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
        ]));
    }

    public function test_update_tanpa_back_query_tetap_ke_index_polos_tanpa_error(): void
    {
        $r = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
        ]);

        $response = $this->actingAs($this->ctx['admin'])->put(route('input.produksi.update', $r), [
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 90, 'ng_count' => 0,
        ]);

        $response->assertRedirect(route('input.produksi.index'));
    }

    public function test_edit_link_di_index_membawa_query_string_saat_ini(): void
    {
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 100, 'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
        ]);

        $html = $this->actingAs($this->ctx['admin'])
            ->get(route('input.produksi.index', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31']))
            ->assertOk()->getContent();

        $this->assertStringContainsString('back_query=', $html);
        $this->assertStringContainsString(urlencode('date_from=2026-07-01'), $html);
    }

    public function test_update_qc_kembali_ke_query_yang_sama(): void
    {
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Inspector N', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $qc = TransaksiQc::create([
            'tgl_pengecekan' => '2026-07-10',
            'tgl_produksi_part' => '2026-07-09',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_ok' => 100, 'qty_ng' => 0, 'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
        ]);

        $response = $this->actingAs($this->ctx['admin'])->put(route('input.qc.update', $qc), [
            'tgl_pengecekan' => '2026-07-10',
            'tgl_produksi_part' => '2026-07-09',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_ok' => 95, 'qty_ng' => 0, 'ng_count' => 0,
            'back_query' => http_build_query(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']),
        ]);

        $response->assertRedirect(route('input.qc.index', [
            'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
        ]));
    }

    public function test_update_dandori_kembali_ke_query_yang_sama(): void
    {
        $loss = MasterLosstime::create([
            'kode' => 'LS-N', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $d = TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 40, 'std_menit' => 15, 'over_menit' => 25,
            'created_by' => $this->ctx['admin']->id,
        ]);

        $response = $this->actingAs($this->ctx['admin'])->put(route('input.dandori.update', $d), [
            'tanggal' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 30,
            'back_query' => http_build_query(['date_from' => '2026-07-01', 'date_to' => '2026-07-31']),
        ]);

        $response->assertRedirect(route('input.dandori.index', [
            'date_from' => '2026-07-01', 'date_to' => '2026-07-31',
        ]));
    }
}
