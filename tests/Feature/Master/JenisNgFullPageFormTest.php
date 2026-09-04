<?php

namespace Tests\Feature\Master;

use App\Models\MasterJenisNg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jenis NG satu-satunya modul Master yang belum ikut migrasi drawer/modal →
 * halaman penuh (6 modul lain sudah). Tambah dulu panel toggle inline di
 * index, Edit dulu Bootstrap modal — sekarang keduanya halaman penuh
 * sendiri, konsisten sama Losstime/Shift/dst.
 */
class JenisNgFullPageFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_nol_lagi_panel_tambah_inline_dan_modal_edit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        MasterJenisNg::create(['kode' => 'DIM', 'nama_ng' => 'Dimensi']);

        $html = $this->actingAs($admin)->get(route('master.jenis-ng.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="add-panel"', $html);
        $this->assertStringNotContainsString('id="editModal"', $html);
        $this->assertStringNotContainsString('id="editForm"', $html);
        $this->assertStringNotContainsString('function openEdit', $html);
        $this->assertStringContainsString(route('master.jenis-ng.create'), $html);
    }

    public function test_halaman_tambah_dan_submit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('master.jenis-ng.create'))->assertOk();

        $this->actingAs($admin)->post(route('master.jenis-ng.store'), [
            'kode' => 'DIM',
            'nama_ng' => 'Dimensi Over/Under',
            'deskripsi' => 'Ukuran meleset',
        ])->assertRedirect(route('master.jenis-ng.index'));

        $this->assertDatabaseHas('master_jenis_ng', ['kode' => 'DIM', 'nama_ng' => 'Dimensi Over/Under']);
    }

    public function test_halaman_edit_terisi_prefill_dan_submit(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ng = MasterJenisNg::create(['kode' => 'DIM', 'nama_ng' => 'Dimensi', 'deskripsi' => 'Lama']);

        $html = $this->actingAs($admin)->get(route('master.jenis-ng.edit', $ng))->assertOk()->getContent();
        $this->assertStringContainsString('value="DIM"', $html);
        $this->assertStringContainsString('value="Dimensi"', $html);

        $this->actingAs($admin)->put(route('master.jenis-ng.update', $ng), [
            'kode' => 'DIM',
            'nama_ng' => 'Dimensi Baru',
            'deskripsi' => 'Baru',
        ])->assertRedirect(route('master.jenis-ng.index'));

        $this->assertSame('Dimensi Baru', $ng->fresh()->nama_ng);
    }
}
