<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairProduksiBatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RPP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair_produksi' => 100]);

        $this->ref = compact('shift', 'karyawan', 'proses');
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_repair_produksi' => '2026-09-01',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_part_proses_repair_produksi' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ], $override);
    }

    public function test_batch_menyimpan_banyak_baris_sekaligus(): void
    {
        $response = $this->post(route('input.repair-produksi.batch'), [
            'rows' => [$this->baris(), $this->baris(['qty_aktual' => 50])],
        ]);

        $response->assertRedirect(route('input.repair-produksi.index'));
        $this->assertDatabaseCount('transaksi_repair_produksi', 2);
    }

    public function test_batch_menolak_baris_tanpa_qty(): void
    {
        $response = $this->post(route('input.repair-produksi.batch'), [
            'rows' => [$this->baris(['qty_aktual' => 0])],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('transaksi_repair_produksi', 0);
    }

    public function test_staff_qc_tidak_bisa_akses_input_repair_produksi(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff_qc']))
            ->get(route('input.repair-produksi.index'))
            ->assertForbidden();
    }

    public function test_hanya_admin_bisa_edit_dan_hapus_repair_produksi(): void
    {
        $repair = TransaksiRepairProduksi::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);

        $this->get(route('input.repair-produksi.edit', $repair))->assertForbidden();
        $this->delete(route('input.repair-produksi.destroy', $repair))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        // GET edit admin (assertOk) sekarang diuji terpisah di
        // test_admin_bisa_buka_dan_update_repair_produksi_lewat_form_edit
        // (Task 9). Method ini fokus pada otorisasi 403 non-admin + destroy.

        // destroy() memakai back(fallback: ...) — GET forbidden di atas tadi
        // (walau 403) tetap ditulis StartSession sebagai "previous URL" sesi
        // ini (sama seperti RepairBatchTest::test_hanya_admin_bisa_edit_dan_hapus_repair),
        // jadi back() tanpa paksaan ini akan nyasar ke URL edit itu, bukan
        // fallback-nya. Set previous URL manual meniru kondisi nyata: admin
        // datang dari halaman daftar sebelum menekan hapus.
        $this->withSession(['_previous.url' => route('input.repair-produksi.index')]);
        $this->actingAs($admin)->delete(route('input.repair-produksi.destroy', $repair))
            ->assertRedirect(route('input.repair-produksi.index'));
        $this->assertSoftDeleted('transaksi_repair_produksi', ['id' => $repair->id]);
    }

    public function test_admin_bisa_buka_dan_update_repair_produksi_lewat_form_edit(): void
    {
        $repair = TransaksiRepairProduksi::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('input.repair-produksi.edit', $repair))->assertOk();

        $response = $this->actingAs($admin)->put(route('input.repair-produksi.update', $repair), $this->baris([
            'qty_aktual' => 200,
        ]));

        $response->assertRedirect(route('input.repair-produksi.index'));
        $this->assertSame(200, $repair->fresh()->qty_aktual);
    }

    public function test_update_repair_produksi_menghitung_ulang_actual_cycle_time(): void
    {
        $repair = TransaksiRepairProduksi::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->put(route('input.repair-produksi.update', $repair), $this->baris([
            'jam_start' => '08:00', 'jam_finish' => '10:00', 'qty_aktual' => 300,
        ]))->assertRedirect(route('input.repair-produksi.index'));

        $this->assertEquals(24.0, $repair->fresh()->actual_cycle_time);
    }
}
