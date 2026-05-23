<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\ProduksiService;

class ProduksiServiceTest extends TestCase
{
    private ProduksiService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ProduksiService();
    }

    public function test_duration_same_day(): void
    {
        $this->assertEquals(28800, $this->service->calculateDurationSeconds('08:00:00', '16:00:00'));
    }

    public function test_duration_overnight(): void
    {
        $this->assertEquals(28800, $this->service->calculateDurationSeconds('22:00:00', '06:00:00'));
    }

    public function test_duration_with_minutes(): void
    {
        $this->assertEquals(15300, $this->service->calculateDurationSeconds('08:30:00', '12:45:00'));
    }

    public function test_actual_cycle_time(): void
    {
        $this->assertEquals(30.00, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 120));
    }

    public function test_actual_cycle_time_zero_qty_returns_zero(): void
    {
        $this->assertEquals(0.0, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 0));
    }

    public function test_target_qty(): void
    {
        $this->assertEquals(240.0, $this->service->calculateTargetQty('08:00:00', '10:00:00', 120.0));
    }

    public function test_duration_hours(): void
    {
        $this->assertEquals(7.5, $this->service->calculateDurationHours('08:00:00', '15:30:00'));
    }
}
