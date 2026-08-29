<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Warna batang Actual QTY harus dari achievement_pct langsung (sama seperti
 * badge tabel di bawahnya) — bukan traffic_light, yang dulu menjatuhkan
 * mesin DI ATAS target ke merah yang sama dengan mesin DI BAWAH target.
 */
class VcbMesinChartTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function buatMesin(string $idMesin, int $qtyAktual, float $sph): void
    {
        $shift = MasterShift::firstOrCreate(['nama_shift' => 'S1'], ['jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => "OP {$idMesin}", 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => $idMesin, 'nama_mesin' => "MESIN {$idMesin}", 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => "P-{$idMesin}", 'part_name' => "PART {$idMesin}"]);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => $sph]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00',
            'jam_finish' => '09:00',
            'qty_aktual' => $qtyAktual,
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_warna_batang_dari_achievement_bukan_traffic_light(): void
    {
        // Target 1 jam x 100 sph = 100. Lebihi target → 150 pcs = 150%.
        $this->buatMesin('OVER', 150, 100);
        // Di bawah target → 50 pcs = 50%.
        $this->buatMesin('UNDER', 50, 100);
        // Pas target → 100%.
        $this->buatMesin('EXACT', 100, 100);

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ]))->json();

        $byLabel = array_combine($json['labels'], $json['datasets'][0]['backgroundColor']);

        $this->assertSame('#1d4ed8', $byLabel['MESIN OVER'], 'di atas target harus biru, bukan merah');
        $this->assertSame('#b91c1c', $byLabel['MESIN UNDER'], 'di bawah target harus merah');
        $this->assertSame('#15803d', $byLabel['MESIN EXACT'], 'pas target harus hijau');
    }

    public function test_target_qty_dikirim_sebagai_dataset_garis(): void
    {
        $this->buatMesin('A', 100, 100);

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20',
            'date_to' => '2026-08-20',
        ]))->json();

        $target = collect($json['datasets'])->firstWhere('label', 'Target QTY');

        $this->assertNotNull($target);
        $this->assertSame('line', $target['type']);
    }
}
