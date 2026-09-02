<?php

namespace Tests\Feature\Maintenance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleGridViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_control_stroke_mesin_tampilkan_pesan_belum_ada_jadwal(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin'))
            ->assertOk()
            ->assertSee('Belum ada jadwal');

        Carbon::setTestNow();
    }

    public function test_halaman_control_stroke_dies_tampilkan_pesan_belum_ada_jadwal(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-dies'))
            ->assertOk()
            ->assertSee('Belum ada jadwal');

        Carbon::setTestNow();
    }
}
