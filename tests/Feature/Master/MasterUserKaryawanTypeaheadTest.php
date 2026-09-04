<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Field "Tautkan ke Karyawan" di Tambah/Edit User masih <select> native —
 * pola sama yang sudah dibenahi di modul lain (Part, Dandori). Diganti
 * typeahead pakai filterSearchComp generik (komponen bersama).
 */
class MasterUserKaryawanTypeaheadTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_tambah_pakai_typeahead_bukan_select_native(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        MasterKaryawan::create([
            'nik' => 'K-001', 'nama_karyawan' => 'Budi Typeahead', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $html = $this->actingAs($admin)->get(route('master.users.create'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="id_karyawan" class="form-select', $html);
        $this->assertStringContainsString('filterSearchComp(', $html);
        $this->assertStringContainsString('K-001', $html);
        $this->assertStringContainsString('Budi Typeahead', $html);
        $this->assertStringContainsString('name="id_karyawan"', $html);
    }

    public function test_halaman_edit_pakai_typeahead_dan_prefill_karyawan_tertaut(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $karyawan = MasterKaryawan::create([
            'nik' => 'K-002', 'nama_karyawan' => 'Sari Terhubung', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $user = User::factory()->create(['role' => 'staff_qc', 'id_karyawan' => $karyawan->id]);

        $html = $this->actingAs($admin)->get(route('master.users.edit', $user))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="id_karyawan" class="form-select', $html);
        $this->assertStringContainsString('filterSearchComp(', $html);
        $this->assertStringContainsString('K-002', $html);
        $this->assertStringContainsString('Sari Terhubung', $html);
    }

    public function test_submit_tambah_dengan_id_karyawan_dari_typeahead_tersimpan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $karyawan = MasterKaryawan::create([
            'nik' => 'K-003', 'nama_karyawan' => 'Dedi Simpan', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $response = $this->actingAs($admin)->post(route('master.users.store'), [
            'name' => 'User Tertaut',
            'email' => 'usertertaut@test.local',
            'password' => 'rahasia123',
            'roles' => ['staff_produksi'],
            'id_karyawan' => $karyawan->id,
        ]);

        $response->assertRedirect(route('master.users.index'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('users', ['email' => 'usertertaut@test.local', 'id_karyawan' => $karyawan->id]);
    }
}
