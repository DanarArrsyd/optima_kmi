<?php

namespace Tests\Feature\Master;

use App\Models\MasterPart;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User melapor: coba "Tambah Part" buat part yang part_no+part_name-nya
 * PERSIS sama kayak yang sudah ada (niatnya nambah proses ke part itu),
 * kena error "sudah dipakai" tanpa arah — mentok. Fix: form Tambah Part
 * mendeteksi duplikat identik lalu langsung antar ke halaman edit part
 * yang sudah ada, bukan berhenti di error.
 */
class MasterPartDuplicateRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_duplikat_identik_diantar_ke_halaman_edit_bukan_error(): void
    {
        $part = MasterPart::create(['part_no' => 'BT-091A', 'part_name' => 'BRACKET KIRI', 'customer' => 'TOYOTA']);

        $response = $this->actingAs($this->admin)->post(route('master.part.store'), [
            'part_no' => 'BT-091A',
            'part_name' => 'BRACKET KIRI',
            'customer' => 'TOYOTA',
        ]);

        $response->assertRedirect(route('master.part.edit', $part));
        $response->assertSessionHas('success');
        $this->assertStringContainsString('sudah ada', session('success'));
        $this->assertSame(1, MasterPart::where('part_no', 'BT-091A')->count());
    }

    public function test_part_no_sama_tapi_part_name_beda_tetap_dibuat_baru(): void
    {
        MasterPart::create(['part_no' => 'BT-091A', 'part_name' => 'BRACKET KIRI', 'customer' => 'TOYOTA']);

        $response = $this->actingAs($this->admin)->post(route('master.part.store'), [
            'part_no' => 'BT-091A',
            'part_name' => 'BRACKET KANAN',
            'customer' => 'TOYOTA',
        ]);

        $baru = MasterPart::where('part_no', 'BT-091A')->where('part_name', 'BRACKET KANAN')->firstOrFail();
        $response->assertRedirect(route('master.part.edit', $baru));
        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(2, MasterPart::where('part_no', 'BT-091A')->count());
    }

    public function test_konflik_saat_edit_part_lain_tetap_error_biasa(): void
    {
        $partA = MasterPart::create(['part_no' => 'BT-091A', 'part_name' => 'BRACKET KIRI']);
        $partB = MasterPart::create(['part_no' => 'BT-091B', 'part_name' => 'BRACKET KANAN']);

        $response = $this->actingAs($this->admin)->put(route('master.part.update', $partB), [
            'part_no' => 'BT-091A',
            'part_name' => 'BRACKET KIRI',
        ]);

        $response->assertSessionHasErrors('part_no');
        $response->assertSessionMissing('success');
    }
}
