<?php
namespace Tests\Unit;

use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Services\MaintenanceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function mesin(?string $instalasi): MasterMesin
    {
        return MasterMesin::create([
            'id_mesin' => 'M'.uniqid(), 'nama_mesin' => 'Test', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $instalasi,
        ]);
    }

    public function test_mttr_is_average_repair_time_over_breakdown_count(): void
    {
        $m = $this->mesin('2026-01-01');
        foreach ([['2026-06-01', 2.0, 3.0], ['2026-06-10', 4.0, 5.0]] as [$tgl, $repair, $down]) {
            TransaksiBreakdownMesin::create([
                'no_lkm' => uniqid(), 'id_mesin' => $m->id,
                'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
                'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Sedang', 'pic_maintenance' => 'PIC',
                'repair_time' => $repair, 'total_downtime' => $down,
            ]);
        }

        $rows = (new MaintenanceDashboardService())->compute(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(2, $rows[0]['count']);
        $this->assertEqualsWithDelta(3.0, $rows[0]['mttr_jam'], 0.01); // (2+4)/2
        $this->assertEqualsWithDelta(8.0, $rows[0]['sum_downtime'], 0.01);
        $this->assertTrue($rows[0]['instalasi_set']);
        $this->assertGreaterThan(0, $rows[0]['availability_pct']);
        $this->assertLessThanOrEqual(100, $rows[0]['availability_pct']);

        // availability == (operating - sum_downtime) / operating * 100, computed from the same window
        $row = $rows[0];
        $operating = \Illuminate\Support\Carbon::parse('2026-01-01')
            ->diffInRealHours(\Illuminate\Support\Carbon::parse('2026-06-30')->endOfDay(), false);
        $expectedAvail = ($operating - $row['sum_downtime']) / $operating * 100;
        $this->assertEqualsWithDelta($expectedAvail, $row['availability_pct'], 0.5);
    }

    public function test_null_instalasi_marks_row_and_nulls_mtbf_availability(): void
    {
        $m = $this->mesin(null);
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-06-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'PIC',
            'repair_time' => 1.0, 'total_downtime' => 2.0,
        ]);

        $rows = (new MaintenanceDashboardService())->compute(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-06-30')
        );

        $this->assertFalse($rows[0]['instalasi_set']);
        $this->assertNull($rows[0]['mtbf_jam']);
        $this->assertNull($rows[0]['mtbf_hari']);
        $this->assertNull($rows[0]['availability_pct']);
        $this->assertSame(1.0, $rows[0]['mttr_jam']);
    }

    public function test_date_window_excludes_breakdowns_outside_range(): void
    {
        $m = $this->mesin('2026-01-01');
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => '2026-03-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-03-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-03-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'PIC',
            'repair_time' => 1.0, 'total_downtime' => 2.0,
        ]);

        $rows = (new MaintenanceDashboardService())->compute(
            Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31')
        );

        $this->assertSame(0, $rows[0]['count']);
        $this->assertSame(0.0, $rows[0]['mttr_jam']);
    }
}
