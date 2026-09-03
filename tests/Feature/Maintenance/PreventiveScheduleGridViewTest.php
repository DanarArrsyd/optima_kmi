<?php

namespace Tests\Feature\Maintenance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleGridViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_edit_jadwal_mesin_tampilkan_pesan_belum_ada_jadwal(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin.schedule-edit'))
            ->assertOk()
            ->assertSee('Belum ada jadwal');

        Carbon::setTestNow();
    }

    public function test_halaman_edit_jadwal_dies_tampilkan_pesan_belum_ada_jadwal(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-dies.schedule-edit'))
            ->assertOk()
            ->assertSee('Belum ada jadwal');

        Carbon::setTestNow();
    }
}
