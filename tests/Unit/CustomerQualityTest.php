<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerQualityTest extends TestCase
{
    use RefreshDatabase;

    private MasterShift $shift;
    private MasterKaryawan $kar;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift = MasterShift::create(['nama_shift' => 'S', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->kar   = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'A', 'departemen' => 'QC', 'is_aktif' => true]);
        $this->user  = User::factory()->create();
    }

    private function partProses(string $customer, string $partNo): MasterPartProses
    {
        $part = MasterPart::create(['part_no' => $partNo, 'part_name' => "Part {$partNo}", 'customer' => $customer]);
        return MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);
    }

    private function qc(int $prosesId, int $ok, int $ng, string $tgl = '2026-07-10'): void
    {
        TransaksiQc::create([
            'tgl_pengecekan' => $tgl, 'tgl_produksi_part' => $tgl, 'id_shift' => $this->shift->id,
            'id_karyawan_qc' => $this->kar->id, 'id_part_proses' => $prosesId,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_ok' => $ok, 'qty_ng' => $ng,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_groups_and_ranks_by_customer_ng(): void
    {
        $toyota = $this->partProses('Toyota', 'PT-T1');
        $honda  = $this->partProses('Honda', 'PT-H1');

        $this->qc($toyota->id, ok: 900, ng: 100);  // Toyota: 100 NG / 1000 = 10%
        $this->qc($honda->id, ok: 950, ng: 50);     // Honda:  50 NG / 1000 = 5%

        $r = (new ChatbotTools())->getCustomerQuality();

        $this->assertCount(2, $r['ranking']);
        // Diurut NG terbanyak → Toyota duluan.
        $this->assertSame('Toyota', $r['ranking'][0]['customer']);
        $this->assertSame(100, $r['ranking'][0]['total_ng']);
        $this->assertSame(1000, $r['ranking'][0]['total_inspeksi']);
        $this->assertSame(10.0, $r['ranking'][0]['ng_rate_pct']);
        $this->assertSame(5.0, $r['ranking'][1]['ng_rate_pct']);
    }

    public function test_aggregates_multiple_parts_same_customer(): void
    {
        $p1 = $this->partProses('Toyota', 'PT-T1');
        $p2 = $this->partProses('Toyota', 'PT-T2'); // customer sama, part beda
        $this->qc($p1->id, ok: 400, ng: 100);
        $this->qc($p2->id, ok: 500, ng: 0);

        $r = (new ChatbotTools())->getCustomerQuality();

        $this->assertCount(1, $r['ranking']);
        $this->assertSame(1000, $r['ranking'][0]['total_inspeksi']);
        $this->assertSame(100, $r['ranking'][0]['total_ng']);
    }

    public function test_filter_by_customer_partial(): void
    {
        $this->qc($this->partProses('Toyota Astra', 'PT-T1')->id, ok: 100, ng: 10);
        $this->qc($this->partProses('Honda', 'PT-H1')->id, ok: 100, ng: 20);

        $r = (new ChatbotTools())->getCustomerQuality(null, null, 'toyota');

        $this->assertCount(1, $r['ranking']);
        $this->assertSame('Toyota Astra', $r['ranking'][0]['customer']);
    }

    public function test_period_filter(): void
    {
        $pp = $this->partProses('Toyota', 'PT-T1');
        $this->qc($pp->id, ok: 100, ng: 10, tgl: '2026-06-10');
        $this->qc($pp->id, ok: 100, ng: 90, tgl: '2026-07-10');

        $r = (new ChatbotTools())->getCustomerQuality('2026-07', '2026-07');

        $this->assertSame(90, $r['ranking'][0]['total_ng']); // hanya Juli
    }

    public function test_no_data_returns_error(): void
    {
        $r = (new ChatbotTools())->getCustomerQuality('2026-07', '2026-07');
        $this->assertArrayHasKey('error', $r);
    }
}
