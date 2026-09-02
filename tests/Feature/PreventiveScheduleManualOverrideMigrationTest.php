<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreventiveScheduleManualOverrideMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_lock_manual_override_ada(): void
    {
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'is_manual_override'));
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'overridden_by'));
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'overridden_at'));
    }
}
