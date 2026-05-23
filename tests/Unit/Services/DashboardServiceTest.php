<?php
namespace Tests\Unit\Services;
use Tests\TestCase;
use App\Services\DashboardService;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;
    private MasterPartProses $proses;
    private MasterKaryawan $karyawan;
    private MasterMesin $mesin;
    private MasterShift $shift;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = new DashboardService();
        $this->shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $this->karyawan = MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $this->mesin    = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'A1-AIDA', 'line' => 'A', 'tipe_mesin' => 'STAMPING']);
        $part           = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $this->proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->user = User::factory()->create(['role' => 'admin']);
    }

    public function test_vcb_produksi_calculates_achievement_correctly(): void
    {
        // 8 jam × 120 SPH = 960 target. Aktual = 960. Achievement = 100%
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-05-23', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00',
            'qty_aktual' => 960, 'actual_cycle_time' => 30, 'created_by' => $this->user->id,
        ]);

        $result = $this->service->getVcbProduksi(
            Carbon::parse('2026-05-23'), Carbon::parse('2026-05-23')
        );

        $this->assertCount(1, $result);
        $this->assertEquals(100.00, $result[0]['achievement_pct']);
        $this->assertEquals(120.00, $result[0]['avg_actual_sph']);
    }

    public function test_vcb_produksi_uses_sum_not_average(): void
    {
        // Two records: total 960 target, 900 aktual → 93.75%
        $base = [
            'tanggal_produksi' => '2026-05-23', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id, 'actual_cycle_time' => 30,
            'created_by' => $this->user->id,
        ];
        TransaksiProduksi::create([...$base, 'jam_start' => '08:00:00', 'jam_finish' => '12:00:00', 'qty_aktual' => 480]);
        TransaksiProduksi::create([...$base, 'jam_start' => '12:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 420]);

        $result = $this->service->getVcbProduksi(
            Carbon::parse('2026-05-23'), Carbon::parse('2026-05-23')
        );

        $this->assertEquals(93.75, $result[0]['achievement_pct']);
    }

    public function test_vcb_produksi_traffic_light_green(): void
    {
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-05-23', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00',
            'qty_aktual' => 960, 'actual_cycle_time' => 30, 'created_by' => $this->user->id,
        ]);
        $result = $this->service->getVcbProduksi(Carbon::parse('2026-05-23'), Carbon::parse('2026-05-23'));
        $this->assertEquals('success', $result[0]['traffic_light']);
    }

    public function test_traffic_light_rules(): void
    {
        $this->assertEquals('success', $this->service->getTrafficLight(100));
        $this->assertEquals('success', $this->service->getTrafficLight(105));
        $this->assertEquals('warning', $this->service->getTrafficLight(99));
        $this->assertEquals('warning', $this->service->getTrafficLight(85));
        $this->assertEquals('danger',  $this->service->getTrafficLight(84.9));
        $this->assertEquals('danger',  $this->service->getTrafficLight(0));
    }
}
