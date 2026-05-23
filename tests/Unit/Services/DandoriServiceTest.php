<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\DandoriService;
use App\Models\MasterLosstime;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DandoriServiceTest extends TestCase
{
    use RefreshDatabase;

    private DandoriService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DandoriService();
    }

    public function test_menit_over_setting_dies_progressive(): void
    {
        $losstime = MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting_Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'PROGRESSIVE', 20);
        $this->assertEquals(5, $result);
    }

    public function test_menit_over_setting_dies_non_progressive(): void
    {
        $losstime = MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting_Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'BENDING', 8);
        $this->assertEquals(0, $result);
    }

    public function test_menit_over_trial_qc(): void
    {
        $losstime = MasterLosstime::create([
            'kode' => '7', 'jenis_losstime' => 'Trial_QC',
            'std_menit_default' => 5, 'std_menit_progressive' => 5,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'DRAW', 7);
        $this->assertEquals(2, $result);
    }

    public function test_menit_over_full_loss_type(): void
    {
        $losstime = MasterLosstime::create([
            'kode' => '4', 'jenis_losstime' => 'Tunggu_Dies',
            'std_menit_default' => null, 'std_menit_progressive' => null,
        ]);
        $result = $this->service->hitungMenitOver($losstime, 'DRAW', 45);
        $this->assertEquals(45, $result);
    }

    public function test_status_ok(): void
    {
        $this->assertEquals('OK', $this->service->hitungStatus(25, 30));
    }

    public function test_status_over(): void
    {
        $this->assertEquals('OVER', $this->service->hitungStatus(35, 30));
    }

    public function test_status_exact_standard_is_ok(): void
    {
        $this->assertEquals('OK', $this->service->hitungStatus(30, 30));
    }
}
