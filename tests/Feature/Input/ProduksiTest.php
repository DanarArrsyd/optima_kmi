<?php
namespace Tests\Feature\Input;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProduksiTest extends TestCase
{
    use RefreshDatabase;

    private User $staffProduksi;
    private array $baseData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffProduksi = User::factory()->create(['role' => 'staff_produksi']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $mesin    = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'A1-AIDA', 'line' => 'A', 'tipe_mesin' => 'STAMPING']);
        $part     = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'DRAW',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->baseData = [
            'tanggal_produksi' => '2026-05-23',
            'id_shift'         => $shift->id,
            'id_karyawan'      => $karyawan->id,
            'id_mesin'         => $mesin->id,
            'id_part_proses'   => $proses->id,
            'jam_start'        => '08:00',
            'jam_finish'       => '16:00',
            'qty_aktual'       => 960,
        ];
    }

    public function test_staff_produksi_can_view_produksi_list(): void
    {
        $this->actingAs($this->staffProduksi)
             ->get('/input/produksi')
             ->assertStatus(200);
    }

    public function test_staff_produksi_can_store_produksi(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', $this->baseData)
             ->assertRedirect('/input/produksi');

        $this->assertDatabaseHas('transaksi_produksi', [
            'tanggal_produksi' => '2026-05-23',
            'qty_aktual'       => 960,
        ]);
    }

    public function test_actual_cycle_time_is_calculated_on_store(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', $this->baseData);

        // 8 jam = 28800 detik / 960 qty = 30.00 detik
        $record = TransaksiProduksi::first();
        $this->assertEquals(30.00, $record->actual_cycle_time);
    }

    public function test_created_by_is_set_automatically(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', $this->baseData);
        $record = TransaksiProduksi::first();
        $this->assertEquals($this->staffProduksi->id, $record->created_by);
    }

    public function test_staff_qc_cannot_access_input_produksi(): void
    {
        $staffQc = User::factory()->create(['role' => 'staff_qc']);
        $this->actingAs($staffQc)->get('/input/produksi')->assertStatus(403);
    }

    public function test_qty_aktual_must_be_positive(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/produksi', array_merge($this->baseData, ['qty_aktual' => 0]))
             ->assertSessionHasErrors('qty_aktual');
    }
}
