<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreventiveScheduleItemsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_backlog_ada_dan_tanggal_rencana_minggu_ke_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'backlog_since'));

        $columns = DB::select('PRAGMA table_info(preventive_schedule_items)');
        $byName = collect($columns)->keyBy('name');

        $this->assertSame(0, $byName['tanggal_rencana']->notnull, 'tanggal_rencana harus nullable');
        $this->assertSame(0, $byName['minggu_ke']->notnull, 'minggu_ke harus nullable');
        $this->assertSame(1, $byName['backlog_since']->notnull === 0 ? 1 : 0);
    }
}
