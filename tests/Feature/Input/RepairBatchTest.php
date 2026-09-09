<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepairBatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'REPAIRMAN SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'REPAIR LAS', 'sph_standar_repair' => 100]);

        $this->ref = compact('shift', 'karyawan', 'proses');
        $this->actingAs(User::factory()->create(['role' => 'staff_repair']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_repair' => '2026-09-01',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_part_proses_repair' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 100,
        ], $override);
    }

    public function test_batch_menyimpan_banyak_baris_sekaligus(): void
    {
        $response = $this->post(route('input.repair.batch'), [
            'rows' => [$this->baris(), $this->baris(['qty_aktual' => 50])],
        ]);

        $response->assertRedirect(route('input.repair.index'));
        $this->assertDatabaseCount('transaksi_repair', 2);
    }

    public function test_batch_menolak_baris_tanpa_qty(): void
    {
        $response = $this->post(route('input.repair.batch'), [
            'rows' => [$this->baris(['qty_aktual' => 0])],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('transaksi_repair', 0);
    }

    public function test_staff_produksi_tidak_bisa_akses_input_repair(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']))
            ->get(route('input.repair.index'))
            ->assertForbidden();
    }

    public function test_hanya_admin_bisa_edit_dan_hapus_repair(): void
    {
        $repair = TransaksiRepair::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);

        $this->get(route('input.repair.edit', $repair))->assertForbidden();
        $this->delete(route('input.repair.destroy', $repair))->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('input.repair.edit', $repair))->assertOk();

        // destroy() memakai back(fallback: ...) — GET forbidden di atas tadi
        // (walau 403) tetap ditulis StartSession sebagai "previous URL" sesi
        // ini, jadi back() tanpa paksaan ini akan nyasar ke URL edit itu,
        // bukan fallback-nya. Set previous URL manual meniru kondisi nyata:
        // admin datang dari halaman daftar sebelum menekan hapus.
        $this->withSession(['_previous.url' => route('input.repair.index')]);
        $this->actingAs($admin)->delete(route('input.repair.destroy', $repair))
            ->assertRedirect(route('input.repair.index'));
        $this->assertSoftDeleted('transaksi_repair', ['id' => $repair->id]);
    }

    public function test_admin_bisa_update_repair_lewat_form_edit(): void
    {
        $repair = TransaksiRepair::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('input.repair.update', $repair), $this->baris([
            'qty_aktual' => 200,
        ]));

        $response->assertRedirect(route('input.repair.index'));
        $this->assertSame(200, $repair->fresh()->qty_aktual);
    }

    public function test_update_repair_menghitung_ulang_actual_cycle_time(): void
    {
        $repair = TransaksiRepair::create([
            ...$this->baris(), 'actual_cycle_time' => 72, 'created_by' => auth()->id(),
        ]);
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->put(route('input.repair.update', $repair), $this->baris([
            'jam_start' => '08:00',
            'jam_finish' => '10:00',
            'qty_aktual' => 300,
        ]));

        $response->assertRedirect(route('input.repair.index'));
        // durasi 2 jam = 7200 detik / 300 = 24, BUKAN nilai lama 72 yang stale.
        $this->assertEquals(24.0, $repair->fresh()->actual_cycle_time);
    }
}
