<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TrendTest extends TestCase
{
    use RefreshDatabase;

    private int $mesinId;

    private int $prosesId;

    private MasterShift $shift;

    private MasterKaryawan $kar;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $part = MasterPart::create(['part_no' => 'PT-1', 'part_name' => 'X', 'customer' => 'Toyota']);
        $this->prosesId = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200])->id;
        $this->mesinId = MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'AMADA 80T', 'line' => 'A', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100])->id;
        $this->shift = MasterShift::create(['nama_shift' => 'S', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->kar = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $this->user = User::factory()->create();
    }

    private function produksi(string $tgl, int $qty): void
    {
        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl, 'id_shift' => $this->shift->id, 'id_karyawan' => $this->kar->id,
            'id_mesin' => $this->mesinId, 'id_part_proses' => $this->prosesId,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $qty, 'qty_ng' => 0,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_produksi_trend_series_and_direction(): void
    {
        $this->produksi('2026-05-10', 1000);
        $this->produksi('2026-06-10', 1500);
        $this->produksi('2026-07-10', 3000);

        $r = (new ChatbotTools)->getTrend('produksi', '2026-05', '2026-07');

        $this->assertSame('Pcs', $r['satuan']);
        $this->assertCount(3, $r['seri']);
        $this->assertSame(['2026-05', '2026-06', '2026-07'], array_column($r['seri'], 'bulan'));
        $this->assertSame([1000, 1500, 3000], array_column($r['seri'], 'nilai'));
        $this->assertSame(1000, $r['ringkasan']['awal']);
        $this->assertSame(3000, $r['ringkasan']['akhir']);
        $this->assertEquals(2000, $r['ringkasan']['delta']);
        $this->assertSame('naik', $r['ringkasan']['arah']);
        $this->assertSame(3000, $r['ringkasan']['max']);
    }

    public function test_empty_months_are_zero_not_skipped(): void
    {
        $this->produksi('2026-07-10', 500); // cuma Juli

        $r = (new ChatbotTools)->getTrend('produksi', '2026-05', '2026-07');

        $this->assertSame([0, 0, 500], array_column($r['seri'], 'nilai'));
        $this->assertSame('naik', $r['ringkasan']['arah']);
    }

    public function test_unknown_metric_errors(): void
    {
        $r = (new ChatbotTools)->getTrend('xxx', '2026-05', '2026-07');
        $this->assertArrayHasKey('error', $r);
    }

    public function test_default_range_is_last_six_months(): void
    {
        Carbon::setTestNow('2026-07-15');
        $r = (new ChatbotTools)->getTrend('produksi');
        Carbon::setTestNow();

        $this->assertCount(6, $r['seri']);
        $this->assertSame('2026-02', $r['bulan_mulai']);
        $this->assertSame('2026-07', $r['bulan_akhir']);
    }

    public function test_ng_trend_uses_rate_percent(): void
    {
        // Metrik ng butuh QC; tanpa data → semua 0, arah stabil.
        $r = (new ChatbotTools)->getTrend('ng', '2026-06', '2026-07');
        $this->assertSame('% NG', $r['satuan']);
        $this->assertSame('stabil', $r['ringkasan']['arah']);
    }
}
