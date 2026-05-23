<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\QcService;

class QcServiceTest extends TestCase
{
    private QcService $service;
    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new QcService();
    }

    public function test_total_qty_check(): void
    {
        $this->assertEquals(150, $this->service->calculateTotalQtyCheck(140, 10));
    }

    public function test_actual_cycle_time(): void
    {
        $this->assertEquals(24.00, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 140, 10));
    }

    public function test_actual_cycle_time_zero_qty_returns_zero(): void
    {
        $this->assertEquals(0.0, $this->service->calculateActualCycleTime('08:00:00', '09:00:00', 0, 0));
    }

    public function test_target_qc_qty(): void
    {
        $this->assertEquals(160.0, $this->service->calculateTargetQcQty('08:00:00', '10:00:00', 80.0));
    }

    public function test_duration_hours_overnight(): void
    {
        $this->assertEquals(8.0, $this->service->calculateDurationHours('22:00:00', '06:00:00'));
    }
}
