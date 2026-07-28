<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VcbNgDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_membuka_kedua_halaman(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.ng-proses'))->assertOk();
        $this->actingAs($admin)->get(route('vcb.ng-total'))->assertOk();
    }

    public function test_guest_bisa_membuka_kedua_halaman(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->get(route('vcb.ng-proses'))->assertOk();
        $this->actingAs($guest)->get(route('vcb.ng-total'))->assertOk();
    }

    public function test_staff_qc_tidak_bisa_membuka_ng_proses(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($qc)->get(route('vcb.ng-proses'))->assertForbidden();
        $this->actingAs($qc)->get(route('vcb.ng-total'))->assertOk();
    }

    public function test_staff_produksi_tidak_bisa_membuka_ng_total(): void
    {
        $produksi = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($produksi)->get(route('vcb.ng-total'))->assertForbidden();
        $this->actingAs($produksi)->get(route('vcb.ng-proses'))->assertOk();
    }

    public function test_belum_login_dialihkan(): void
    {
        $this->get(route('vcb.ng-proses'))->assertRedirect(route('login'));
        $this->get(route('vcb.ng-total'))->assertRedirect(route('login'));
    }

    public function test_endpoint_data_mengembalikan_bentuk_yang_diharapkan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('vcb.ng-proses.data', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
            ->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_ng', 'total_check', 'ng_rate'],
                'by_month',
            ]);
    }

    public function test_endpoint_detail_menolak_format_bulan_yang_salah(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('vcb.ng-proses.detail', ['bulan' => 'Maret']))
            ->assertStatus(422);
    }

    public function test_endpoint_detail_mengembalikan_array(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('vcb.ng-proses.detail', ['bulan' => '2026-03']))
            ->assertOk()
            ->assertJsonIsArray();
    }

    public function test_sidebar_memuat_entri_ng_proses_untuk_staff_produksi(): void
    {
        $produksi = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($produksi)
            ->get(route('vcb.produksi'))
            ->assertOk()
            ->assertSee('VCB NG Proses')
            ->assertDontSee('VCB NG Total');
    }

    public function test_sidebar_memuat_entri_ng_total_untuk_staff_qc(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($qc)
            ->get(route('vcb.quality'))
            ->assertOk()
            ->assertSee('VCB NG Total')
            ->assertDontSee('VCB NG Proses');
    }

    public function test_admin_melihat_kedua_entri_sidebar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('vcb.produksi'))
            ->assertOk()
            ->assertSee('VCB NG Proses')
            ->assertSee('VCB NG Total');
    }
}
