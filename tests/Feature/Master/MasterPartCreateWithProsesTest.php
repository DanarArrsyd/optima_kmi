<?php

namespace Tests\Feature\Master;

use App\Models\MasterPart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User minta: input Part Name dan proses jadi SATU halaman, bukan simpan
 * part dulu baru lompat ke edit buat nambah proses satu-satu.
 */
class MasterPartCreateWithProsesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function payload(array $proses = []): array
    {
        return [
            'part_no' => 'BT-091A',
            'part_name' => 'BRACKET KIRI',
            'customer' => 'TOYOTA',
            'proses' => $proses,
        ];
    }

    public function test_part_dan_dua_proses_tersimpan_sekali_submit(): void
    {
        $response = $this->actingAs($this->admin)->post(route('master.part.store'), $this->payload([
            [
                'id_proses' => '1', 'nama_proses' => 'PROGRESSIVE',
                'sph_standar_produksi' => 500, 'sph_standar_qc' => 480,
                'std_trial_qc_menit' => 15, 'std_setting_dies_menit' => 30,
            ],
            [
                'id_proses' => '2', 'nama_proses' => 'BENDING',
                'sph_standar_produksi' => 300, 'sph_standar_qc' => 280,
                'std_trial_qc_menit' => 10, 'std_setting_dies_menit' => 20,
            ],
        ]));

        $part = MasterPart::where('part_no', 'BT-091A')->firstOrFail();
        $response->assertRedirect(route('master.part.edit', $part));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('2 proses', session('success'));

        $this->assertSame(2, $part->proses()->count());
        $this->assertDatabaseHas('master_part_proses', ['id_part' => $part->id, 'id_proses' => '1', 'nama_proses' => 'PROGRESSIVE']);
        $this->assertDatabaseHas('master_part_proses', ['id_part' => $part->id, 'id_proses' => '2', 'nama_proses' => 'BENDING']);
    }

    public function test_part_tanpa_proses_tetap_bisa_disimpan(): void
    {
        $response = $this->actingAs($this->admin)->post(route('master.part.store'), $this->payload());

        $part = MasterPart::where('part_no', 'BT-091A')->firstOrFail();
        $response->assertRedirect(route('master.part.edit', $part));
        $this->assertSame(0, $part->proses()->count());
    }

    public function test_id_proses_dobel_dalam_satu_submit_ditolak_nol_part_tersimpan(): void
    {
        $response = $this->actingAs($this->admin)->post(route('master.part.store'), $this->payload([
            [
                'id_proses' => '1', 'nama_proses' => 'PROGRESSIVE',
                'sph_standar_produksi' => 500, 'sph_standar_qc' => 480,
                'std_trial_qc_menit' => 15, 'std_setting_dies_menit' => 30,
            ],
            [
                'id_proses' => '1', 'nama_proses' => 'BENDING',
                'sph_standar_produksi' => 300, 'sph_standar_qc' => 280,
                'std_trial_qc_menit' => 10, 'std_setting_dies_menit' => 20,
            ],
        ]));

        $response->assertSessionHasErrors();
        $this->assertSame(0, MasterPart::where('part_no', 'BT-091A')->count());
    }

    public function test_baris_proses_tidak_lengkap_ditolak_nol_part_tersimpan(): void
    {
        $response = $this->actingAs($this->admin)->post(route('master.part.store'), $this->payload([
            ['id_proses' => '1', 'nama_proses' => ''],
        ]));

        $response->assertSessionHasErrors();
        $this->assertSame(0, MasterPart::where('part_no', 'BT-091A')->count());
    }
}
