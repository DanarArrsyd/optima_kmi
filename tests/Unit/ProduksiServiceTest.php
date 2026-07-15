<?php
namespace Tests\Unit;

use App\Services\ProduksiService;
use PHPUnit\Framework\TestCase;

/**
 * Perhitungan murni cycle-time / durasi produksi. Termasuk edge shift overnight
 * (finish < start → lewat tengah malam) dan qty 0.
 */
class ProduksiServiceTest extends TestCase
{
    private ProduksiService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ProduksiService();
    }

    public function test_duration_seconds_same_day(): void
    {
        $this->assertSame(8 * 3600, $this->svc->calculateDurationSeconds('08:00:00', '16:00:00'));
    }

    public function test_duration_seconds_overnight_wraps_midnight(): void
    {
        // 22:00 → 06:00 = 8 jam melewati tengah malam.
        $this->assertSame(8 * 3600, $this->svc->calculateDurationSeconds('22:00:00', '06:00:00'));
    }

    public function test_duration_hours_rounds_to_four_decimals(): void
    {
        $this->assertSame(8.0, $this->svc->calculateDurationHours('08:00:00', '16:00:00'));
        $this->assertSame(0.5, $this->svc->calculateDurationHours('08:00:00', '08:30:00'));
    }

    public function test_actual_cycle_time_is_seconds_per_piece(): void
    {
        // 8 jam = 28800 detik, 4000 pcs → 7.2 detik/pcs.
        $this->assertSame(7.2, $this->svc->calculateActualCycleTime('08:00:00', '16:00:00', 4000));
    }

    public function test_actual_cycle_time_zero_qty_is_zero_not_division_error(): void
    {
        $this->assertSame(0.0, $this->svc->calculateActualCycleTime('08:00:00', '16:00:00', 0));
    }

    public function test_target_qty_is_hours_times_sph(): void
    {
        // 8 jam × SPH 500 = 4000 pcs target.
        $this->assertSame(4000.0, $this->svc->calculateTargetQty('08:00:00', '16:00:00', 500));
    }
}
