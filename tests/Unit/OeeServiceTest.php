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
use App\Services\OeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OeeServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{mesin: MasterMesin, proses: MasterPartProses} */
    private function scaffold(int $sph = 400, ?string $install = '2026-01-01'): array
    {
        $part   = MasterPart::create(['part_no' => 'PT-OEE', 'part_name' => 'X', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => $sph, 'sph_standar_qc' => 200,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-OEE', 'nama_mesin' => 'Press', 'line' => '1',
            'tipe_mesin' => 'BLANKING', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => $install,
        ]);
        return ['mesin' => $mesin, 'proses' => $proses];
    }

    private function lhp(int $mesinId, int $prosesId, int $qty, int $ng, string $tgl = '2026-07-15'): void
    {
        $shift = MasterShift::firstOrCreate(['nama_shift' => 'S1'], ['jam_start' => '08:00', 'jam_finish' => '16:00']);
        $kar   = MasterKaryawan::firstOrCreate(['nik' => 'K-OEE'], ['nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $user  = User::factory()->create();
        TransaksiProduksi::create([
            'tanggal_produksi' => $tgl, 'id_shift' => $shift->id, 'id_karyawan' => $kar->id,
            'id_mesin' => $mesinId, 'id_part_proses' => $prosesId,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $qty, 'qty_ng' => $ng,
            'created_by' => $user->id,
        ]);
    }

    private function july(): array
    {
        $from = Carbon::create(2026, 7, 1)->startOfMonth();
        return [$from, $from->copy()->endOfMonth()];
    }

    public function test_performance_and_quality_are_exact(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        // 8 jam kerja × sph_std 400 = teoretis 3200; aktual 3200 → performance 100%.
        // NG 200 dari 3200 → quality (3200-200)/3200 = 93.75%.
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 200);

        [$from, $to] = $this->july();
        $r = (new OeeService())->computeMesin($mesin->id, $from, $to);

        $this->assertSame(100.0, $r['performance_pct']);
        $this->assertSame(93.75, $r['quality_pct']);
        $this->assertSame(3200, $r['total_qty']);
        $this->assertSame(200, $r['total_ng']);
        $this->assertSame(1, $r['jumlah_lhp']);
    }

    public function test_oee_is_product_of_three_components(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 400);
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 200);

        [$from, $to] = $this->july();
        $r = (new OeeService())->computeMesin($mesin->id, $from, $to);

        $this->assertNotNull($r['availability_pct'], 'Instalasi diset → availability harus terhitung.');
        $expected = round($r['availability_pct'] / 100 * $r['performance_pct'] / 100 * $r['quality_pct'] / 100 * 100, 2);
        $this->assertSame($expected, $r['oee_pct']);
    }

    public function test_oee_null_when_install_date_missing(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(install: null);
        $this->lhp($mesin->id, $proses->id, qty: 1000, ng: 0);

        [$from, $to] = $this->july();
        $r = (new OeeService())->computeMesin($mesin->id, $from, $to);

        $this->assertNull($r['availability_pct']);
        $this->assertNull($r['oee_pct']);
        $this->assertNotNull($r['performance_pct']); // P & Q tetap bisa
        $this->assertTrue(collect($r['catatan'])->contains(fn ($c) => str_contains($c, 'instalasi')));
    }

    public function test_performance_null_when_std_sph_missing(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold(sph: 0);
        $this->lhp($mesin->id, $proses->id, qty: 1000, ng: 50);

        [$from, $to] = $this->july();
        $r = (new OeeService())->computeMesin($mesin->id, $from, $to);

        $this->assertNull($r['performance_pct']);
        $this->assertSame(95.0, $r['quality_pct']); // quality tetap terhitung
        $this->assertNull($r['oee_pct']);
    }

    public function test_no_production_yields_null_perf_and_quality(): void
    {
        ['mesin' => $mesin] = $this->scaffold();

        [$from, $to] = $this->july();
        $r = (new OeeService())->computeMesin($mesin->id, $from, $to);

        $this->assertSame(0, $r['jumlah_lhp']);
        $this->assertNull($r['performance_pct']);
        $this->assertNull($r['quality_pct']);
    }

    public function test_chatbot_tools_resolves_kode_and_month(): void
    {
        ['mesin' => $mesin, 'proses' => $proses] = $this->scaffold();
        $this->lhp($mesin->id, $proses->id, qty: 3200, ng: 0);

        $r = (new ChatbotTools())->getOeeMesin('m-oee', '2026-07'); // case-insensitive kode

        $this->assertSame('Press', $r['mesin']);
        $this->assertSame('2026-07', $r['periode']);
        $this->assertSame(100.0, $r['performance_pct']);
    }

    public function test_chatbot_tools_errors_on_unknown_machine(): void
    {
        $r = (new ChatbotTools())->getOeeMesin('GHOST', '2026-07');
        $this->assertArrayHasKey('error', $r);
    }
}
