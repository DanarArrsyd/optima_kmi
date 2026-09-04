<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regresi: departemen "Maintenance" sempat mustahil disimpan — migration
 * 2026_07_02_110000 cuma nambah 'Maintenance' ke ENUM di MySQL, no-op di
 * SQLite dengan asumsi salah bahwa kolomnya sudah longgar. CHECK constraint
 * lama tetap berlaku di SQLite (test suite ini) sampai
 * 2026_09_04_000001_change_departemen_to_string_on_master_karyawan
 * mengubahnya jadi VARCHAR biasa.
 */
class MasterKaryawanCreateTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_karyawan_departemen_maintenance_bisa_disimpan(): void
    {
        $response = $this->actingAs($this->admin)->post(route('master.karyawan.store'), [
            'nik' => 'K001',
            'nama_karyawan' => 'BUDI SANTOSO',
            'departemen' => 'Maintenance',
        ]);

        $response->assertRedirect(route('master.karyawan.index'));
        $response->assertSessionDoesntHaveErrors();
        $this->assertDatabaseHas('master_karyawan', ['nik' => 'K001', 'departemen' => 'Maintenance']);
    }

    public function test_karyawan_departemen_produksi_dan_qc_tetap_bisa_disimpan(): void
    {
        foreach (['Produksi', 'QC'] as $dept) {
            $response = $this->actingAs($this->admin)->post(route('master.karyawan.store'), [
                'nik' => "NIK-{$dept}",
                'nama_karyawan' => "Karyawan {$dept}",
                'departemen' => $dept,
            ]);
            $response->assertSessionDoesntHaveErrors();
        }

        $this->assertSame(2, MasterKaryawan::count());
    }
}
