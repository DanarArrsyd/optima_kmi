<?php
namespace Tests\Feature\Input;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\MasterLosstime;
use App\Models\TransaksiDandori;
use App\Models\TransaksiDandoriRincian;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DandoriTest extends TestCase
{
    use RefreshDatabase;

    private User $staffProduksi;
    private array $baseData;
    private MasterLosstime $settingDies;
    private MasterLosstime $trialQc;
    private MasterPartProses $proses;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staffProduksi = User::factory()->create(['role' => 'staff_produksi']);
        $shift    = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $mesin    = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'A1-AIDA', 'line' => 'A', 'tipe_mesin' => 'STAMPING']);
        $part     = MasterPart::create(['part_no' => 'PT-001', 'part_name' => 'Test Part']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => '1/2', 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 120, 'cycle_time_standar_produksi' => 30,
            'sph_standar_qc' => 80, 'cycle_time_standar_qc' => 45, 'std_dandori_menit' => 15,
        ]);
        $this->settingDies = MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting_Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $this->trialQc = MasterLosstime::create([
            'kode' => '7', 'jenis_losstime' => 'Trial_QC',
            'std_menit_default' => 5, 'std_menit_progressive' => 5,
        ]);
        $this->baseData = [
            'tanggal'        => '2026-05-23',
            'id_shift'       => $shift->id,
            'id_karyawan'    => $karyawan->id,
            'id_mesin'       => $mesin->id,
            'id_part_proses' => $this->proses->id,
            'rincian'        => [
                ['id_losstime' => $this->settingDies->id, 'menit_loss' => 20],
                ['id_losstime' => $this->trialQc->id,    'menit_loss' => 7],
            ],
        ];
    }

    public function test_staff_produksi_can_view_dandori_list(): void
    {
        $this->actingAs($this->staffProduksi)->get('/input/dandori')->assertStatus(200);
    }

    public function test_staff_produksi_can_store_dandori(): void
    {
        $this->actingAs($this->staffProduksi)
             ->post('/input/dandori', $this->baseData)
             ->assertRedirect('/input/dandori');
        $this->assertDatabaseCount('transaksi_dandori', 1);
        $this->assertEquals('2026-05-23', TransaksiDandori::first()->tanggal->format('Y-m-d'));
    }

    public function test_total_aktual_is_sum_of_rincian(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        // 20 + 7 = 27 menit
        $dandori = TransaksiDandori::first();
        $this->assertEquals(27, $dandori->total_aktual_menit);
    }

    public function test_standard_time_comes_from_part_proses(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        $dandori = TransaksiDandori::first();
        $this->assertEquals(15, $dandori->standard_time_menit);
    }

    public function test_status_over_when_aktual_exceeds_standard(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        // 27 > 15 → OVER
        $dandori = TransaksiDandori::first();
        $this->assertEquals('OVER', $dandori->status);
    }

    public function test_rincian_menit_over_is_calculated_correctly(): void
    {
        $this->actingAs($this->staffProduksi)->post('/input/dandori', $this->baseData);
        $rincian = TransaksiDandoriRincian::all();
        $this->assertCount(2, $rincian);
        // Setting_Dies PROGRESSIVE: max(20-15,0) = 5
        $this->assertEquals(5, $rincian->firstWhere('id_losstime', $this->settingDies->id)->menit_over);
        // Trial_QC: max(7-5,0) = 2
        $this->assertEquals(2, $rincian->firstWhere('id_losstime', $this->trialQc->id)->menit_over);
    }

    public function test_minimum_one_rincian_required(): void
    {
        $data = $this->baseData;
        $data['rincian'] = [];
        $this->actingAs($this->staffProduksi)
             ->post('/input/dandori', $data)
             ->assertSessionHasErrors('rincian');
    }
}
