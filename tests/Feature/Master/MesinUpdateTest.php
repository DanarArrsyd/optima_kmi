<?php

namespace Tests\Feature\Master;

use App\Models\MasterMesin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MesinUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function mesin(): MasterMesin
    {
        return MasterMesin::create([
            'id_mesin' => 'MU-01', 'nama_mesin' => 'Press 80T', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 800,
            'tanggal_instalasi' => '2026-01-15',
        ]);
    }

    /** Value dari input date pada halaman edit — ini yang dikirim balik browser saat submit. */
    private function renderedInstalasi(string $html): string
    {
        preg_match('/name="tanggal_instalasi"[^>]*value="([^"]*)"/', $html, $m);

        return $m[1] ?? '';
    }

    public function test_edit_view_renders_existing_tanggal_instalasi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->mesin();

        $response = $this->actingAs($admin)->get(route('master.mesin.edit', $mesin));

        $response->assertOk();
        $this->assertSame('2026-01-15', $this->renderedInstalasi($response->getContent()));
    }

    public function test_update_via_edit_form_preserves_tanggal_instalasi(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->mesin();

        $html = $this->actingAs($admin)->get(route('master.mesin.edit', $mesin))->getContent();

        $response = $this->actingAs($admin)->put(route('master.mesin.update', $mesin), [
            'id_mesin' => 'MU-01',
            'nama_mesin' => 'Press 80T (renamed)',
            'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE',
            'kapasitas_max_stroke' => 800,
            'tanggal_instalasi' => $this->renderedInstalasi($html),
        ]);

        $response->assertRedirect(route('master.mesin.index'));
        $mesin->refresh();
        $this->assertSame('Press 80T (renamed)', $mesin->nama_mesin);
        $this->assertSame('2026-01-15', $mesin->tanggal_instalasi->toDateString());
    }

    public function test_tanggal_instalasi_is_cast_to_carbon(): void
    {
        $this->assertInstanceOf(Carbon::class, $this->mesin()->fresh()->tanggal_instalasi);
    }

    public function test_tanggal_instalasi_can_still_be_cleared(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->mesin();

        $this->actingAs($admin)->put(route('master.mesin.update', $mesin), [
            'id_mesin' => 'MU-01',
            'nama_mesin' => 'Press 80T',
            'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE',
            'kapasitas_max_stroke' => 800,
            'tanggal_instalasi' => '',
        ]);

        $this->assertNull($mesin->refresh()->tanggal_instalasi);
    }
}
