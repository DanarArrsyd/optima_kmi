<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProduksiStoreTest extends TestCase
{
    use RefreshDatabase;

    private function refs(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Op A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 1', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);

        return compact('shift', 'karyawan', 'mesin', 'proses');
    }

    private function payload(array $r, array $override = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-07-01',
            'id_shift' => $r['shift']->id,
            'id_karyawan' => $r['karyawan']->id,
            'id_mesin' => $r['mesin']->id,
            'id_part_proses' => $r['proses']->id,
            'jam_start' => '08:00',
            'jam_finish' => '16:00',
            'qty_aktual' => 4000,
        ], $override);
    }

    public function test_staff_produksi_can_store_and_cycle_time_is_computed(): void
    {
        $user = User::factory()->create(['role' => 'staff_produksi']);
        $r = $this->refs();

        $this->actingAs($user)
            ->post(route('input.produksi.store'), $this->payload($r))
            ->assertRedirect(route('input.produksi.index'))
            ->assertSessionHas('success');

        $row = TransaksiProduksi::first();
        $this->assertNotNull($row);
        $this->assertSame(4000, $row->qty_aktual);
        $this->assertSame($user->id, $row->created_by);
        // 8 jam / 4000 pcs = 7.2 detik/pcs.
        $this->assertEqualsWithDelta(7.2, (float) $row->actual_cycle_time, 0.01);
    }

    public function test_store_validation_rejects_missing_qty(): void
    {
        $user = User::factory()->create(['role' => 'staff_produksi']);
        $r = $this->refs();

        $this->actingAs($user)
            ->post(route('input.produksi.store'), $this->payload($r, ['qty_aktual' => null]))
            ->assertSessionHasErrors('qty_aktual');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_store_forbidden_for_qc_role(): void
    {
        $user = User::factory()->create(['role' => 'staff_qc']);
        $r = $this->refs();

        $this->actingAs($user)
            ->post(route('input.produksi.store'), $this->payload($r))
            ->assertForbidden();
    }

    public function test_non_owner_non_admin_cannot_delete(): void
    {
        $owner = User::factory()->create(['role' => 'staff_produksi']);
        $other = User::factory()->create(['role' => 'staff_produksi']);
        $r = $this->refs();
        $this->actingAs($owner)->post(route('input.produksi.store'), $this->payload($r));
        $row = TransaksiProduksi::first();

        $this->actingAs($other)->delete(route('input.produksi.destroy', $row))->assertForbidden();
        $this->assertNotSoftDeleted($row); // tidak boleh terhapus oleh non-owner
    }

    public function test_owner_can_delete_own_record(): void
    {
        $owner = User::factory()->create(['role' => 'staff_produksi']);
        $r = $this->refs();
        $this->actingAs($owner)->post(route('input.produksi.store'), $this->payload($r));
        $row = TransaksiProduksi::first();

        $this->actingAs($owner)->delete(route('input.produksi.destroy', $row))->assertRedirect(route('input.produksi.index'));
        $this->assertSoftDeleted($row);
    }

    public function test_admin_can_delete_any_record(): void
    {
        $owner = User::factory()->create(['role' => 'staff_produksi']);
        $admin = User::factory()->create(['role' => 'admin']);
        $r = $this->refs();
        $this->actingAs($owner)->post(route('input.produksi.store'), $this->payload($r));
        $row = TransaksiProduksi::first();

        $this->actingAs($admin)->delete(route('input.produksi.destroy', $row))->assertRedirect(route('input.produksi.index'));
        $this->assertSoftDeleted($row);
    }
}
