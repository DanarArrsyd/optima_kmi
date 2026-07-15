<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MaintenanceDiesDashboardServiceStrokeTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create([
            'id_mesin' => 'SD'.uniqid(), 'nama_mesin' => 'Stroke Dies Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        return compact('user', 'shift', 'karyawan', 'mesin');
    }

    private function partProses(string $customer = 'Toyota'): MasterPartProses
    {
        $part = MasterPart::create(['part_no' => 'SD-P'.uniqid(), 'part_name' => 'Stroke Dies Part', 'customer' => $customer]);
        return MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    private function produksi(array $f, MasterPartProses $proses, string $tanggal, int $qty): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal, 'id_shift' => $f['shift']->id, 'id_karyawan' => $f['karyawan']->id,
            'id_mesin' => $f['mesin']->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '09:00', 'qty_aktual' => $qty,
            'created_by' => $f['user']->id,
        ]);
    }

    public function test_total_stroke_sums_qty_aktual_per_dies(): void
    {
        $f  = $this->fixtures();
        $pr = $this->partProses();
        $this->produksi($f, $pr, '2026-06-01', 300);
        $this->produksi($f, $pr, '2026-06-10', 80);
        $this->produksi($f, $pr, '2026-06-20', 70);

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')
        );

        $this->assertCount(1, $rows);
        $this->assertSame(450, $rows[0]['total_stroke']);
        $this->assertSame($pr->part->part_no, $rows[0]['kode_mesin']);
    }

    public function test_dies_with_no_transaksi_is_omitted(): void
    {
        $f = $this->fixtures();
        $this->partProses(); // no TransaksiProduksi created for it

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31')
        );

        $this->assertCount(0, $rows);
    }

    public function test_part_id_filter_narrows_to_single_part(): void
    {
        $f   = $this->fixtures();
        $pr1 = $this->partProses();
        $pr2 = $this->partProses();
        $this->produksi($f, $pr1, '2026-06-01', 100);
        $this->produksi($f, $pr2, '2026-06-01', 999);

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), $pr1->id_part
        );

        $this->assertCount(1, $rows);
        $this->assertSame(100, $rows[0]['total_stroke']);
    }

    public function test_customer_filter_narrows_result(): void
    {
        $f   = $this->fixtures();
        $pr1 = $this->partProses('Toyota');
        $pr2 = $this->partProses('Honda');
        $this->produksi($f, $pr1, '2026-06-01', 100);
        $this->produksi($f, $pr2, '2026-06-01', 200);

        $rows = (new MaintenanceDiesDashboardService())->computeStroke(
            Carbon::parse('2026-01-01'), Carbon::parse('2026-12-31'), null, 'Honda'
        );

        $this->assertCount(1, $rows);
        $this->assertSame(200, $rows[0]['total_stroke']);
    }
}
