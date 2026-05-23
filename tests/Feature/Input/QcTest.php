<?php
namespace Tests\Feature\Input;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QcTest extends TestCase
{
    use RefreshDatabase;

    private User $staffQc;
    private array $baseData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffQc = User::factory()->create(['role' => 'staff_qc']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'Q001', 'nama_karyawan' => 'Siti', 'departemen' => 'QC']);
        $part     = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->baseData = [
            'tgl_pengecekan'    => '2026-05-23',
            'tgl_produksi_part' => '2026-05-22',
            'id_shift'          => $shift->id,
            'id_karyawan_qc'    => $karyawan->id,
            'id_part_proses'    => $proses->id,
            'jam_start'         => '08:00',
            'jam_finish'        => '14:00',
            'qty_ok'            => 140,
            'qty_ng'            => 10,
        ];
    }

    public function test_staff_qc_can_view_qc_list(): void
    {
        $this->actingAs($this->staffQc)->get('/input/qc')->assertStatus(200);
    }

    public function test_staff_qc_can_store_qc(): void
    {
        $this->actingAs($this->staffQc)
             ->post('/input/qc', $this->baseData)
             ->assertRedirect('/input/qc');
        $this->assertDatabaseHas('transaksi_qc', ['qty_ok' => 140, 'qty_ng' => 10]);
    }

    public function test_actual_cycle_time_is_calculated(): void
    {
        $this->actingAs($this->staffQc)->post('/input/qc', $this->baseData);
        // 6 jam = 21600 detik / (140+10) = 144.00 detik
        $record = TransaksiQc::first();
        $this->assertEquals(144.00, $record->actual_cycle_time);
    }

    public function test_created_by_is_set_automatically(): void
    {
        $this->actingAs($this->staffQc)->post('/input/qc', $this->baseData);
        $record = TransaksiQc::first();
        $this->assertEquals($this->staffQc->id, $record->created_by);
    }

    public function test_staff_produksi_cannot_access_input_qc(): void
    {
        $staffProd = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staffProd)->get('/input/qc')->assertStatus(403);
    }

    public function test_total_qty_zero_fails_validation(): void
    {
        $this->actingAs($this->staffQc)
             ->post('/input/qc', array_merge($this->baseData, ['qty_ok' => 0, 'qty_ng' => 0]))
             ->assertSessionHasErrors('qty_ok');
    }
}
