<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JadwalkanPreventiveCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_membuat_jadwal_mesin_dan_dies_untuk_bulan_berjalan(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');

        $this->artisan('preventive:jadwalkan')->assertExitCode(0);

        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'mesin']);
        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'dies']);

        Carbon::setTestNow();
    }

    public function test_command_tidak_meledak_walau_nol_item_layak_dijadwalkan(): void
    {
        // Nol mesin/dies di DB sama sekali — command tetap harus SUKSES (schedule
        // header tetap dibuat dengan nol items, bukan exception).
        $this->artisan('preventive:jadwalkan')->assertExitCode(0);
    }
}
