<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Grafik kiri halaman MTTR/MTBF Mesin & Dies: tren rata-rata per bulan.
 *
 * Yang dijaga: titik bulan X == KPI halaman saat rentangnya persis bulan X
 * (satu agregasi, bukan dua), bulan tanpa breakdown = null (celah grafik, bukan
 * "perbaikan 0 jam"), bulan tepi dipotong ke rentang filter, plafon 36 bulan.
 */
class TrenBulananMttrMtbfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function mesin(string $kode, string $instalasi = '2025-01-01'): MasterMesin
    {
        MasterShift::firstOrCreate(['nama_shift' => 'Shift 1'], ['jam_start' => '06:00', 'jam_finish' => '14:00']);

        return MasterMesin::create([
            'id_mesin' => $kode, 'nama_mesin' => 'Test '.$kode, 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $instalasi,
        ]);
    }

    private function bdMesin(MasterMesin $m, string $tgl, float $repair): void
    {
        TransaksiBreakdownMesin::create([
            'no_lkm' => uniqid(), 'id_mesin' => $m->id,
            'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'PIC',
            'repair_time' => $repair, 'total_downtime' => $repair,
        ]);
    }

    private function tren(string $route, array $q): array
    {
        return $this->actingAs($this->admin())->getJson(route($route, $q))->assertOk()->json('tren');
    }

    public function test_tren_mttr_mesin_satu_titik_per_bulan_dan_bulan_kosong_null(): void
    {
        $a = $this->mesin('A1');
        $b = $this->mesin('B1');
        $this->bdMesin($a, '2026-01-05', 2.0);
        $this->bdMesin($a, '2026-01-06', 4.0); // MTTR A Jan = 3
        $this->bdMesin($b, '2026-01-07', 5.0); // MTTR B Jan = 5 → rata-rata antar mesin 4
        $this->bdMesin($a, '2026-03-10', 1.0);

        $tren = $this->tren('maintenance.mttr.tren', ['date_from' => '2026-01-01', 'date_to' => '2026-03-31']);

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column($tren, 'bulan'));
        $this->assertEqualsWithDelta(4.0, $tren[0]['mttr'], 0.01);
        $this->assertSame(3, $tren[0]['total_bd']);
        $this->assertNull($tren[1]['mttr'], 'bulan tanpa breakdown harus celah, bukan 0');
        $this->assertSame(0, $tren[1]['total_bd']);
        $this->assertEqualsWithDelta(1.0, $tren[2]['mttr'], 0.01);
    }

    public function test_titik_bulan_sama_dengan_kpi_halaman_untuk_rentang_bulan_itu(): void
    {
        $a = $this->mesin('A1');
        $b = $this->mesin('B1');
        $this->bdMesin($a, '2026-02-03', 2.0);
        $this->bdMesin($b, '2026-02-04', 7.0);
        $this->bdMesin($a, '2026-02-20', 3.0);

        foreach ([['mttr', 'avg_mttr'], ['mtbf', 'avg_mtbf']] as [$metrik, $kpi]) {
            $tren = $this->tren("maintenance.{$metrik}.tren", ['date_from' => '2026-01-01', 'date_to' => '2026-03-31']);
            $feb = $tren[1];
            $this->assertSame('2026-02', $feb['bulan']);

            $summary = $this->actingAs($this->admin())
                ->getJson(route("maintenance.{$metrik}.data", ['date_from' => $feb['from'], 'date_to' => $feb['to']]))
                ->assertOk()->json('summary');

            $this->assertNotNull($feb[$metrik]);
            $this->assertEqualsWithDelta($summary[$kpi], $feb[$metrik], 0.001, "titik {$metrik} Feb ≠ KPI rentang Feb");
        }
    }

    public function test_bulan_tepi_dipotong_ke_rentang_filter(): void
    {
        $this->mesin('A1');

        $tren = $this->tren('maintenance.mtbf.tren', ['date_from' => '2026-01-15', 'date_to' => '2026-02-10']);

        $this->assertCount(2, $tren);
        $this->assertSame(['2026-01-15', '2026-01-31'], [$tren[0]['from'], $tren[0]['to']]);
        $this->assertSame(['2026-02-01', '2026-02-10'], [$tren[1]['from'], $tren[1]['to']]);
        $this->assertNotNull($tren[0]['mtbf'], 'MTBF tetap ada walau nol breakdown (uptime penuh)');
    }

    public function test_mesin_yang_belum_terpasang_tidak_menyeret_mtbf_bulan_sebelumnya(): void
    {
        $lama = $this->mesin('A1');
        $this->mesin('B1', '2026-03-01');

        $tren = $this->tren('maintenance.mtbf.tren', ['date_from' => '2026-01-01', 'date_to' => '2026-03-31']);

        // Jan: hanya A1 yang terpasang. B1 (operating_hours 0, mtbf_jam 0) tidak ikut dirata-rata.
        $sendiri = $this->actingAs($this->admin())
            ->getJson(route('maintenance.mtbf.data', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'mesin_ids' => [$lama->id]]))
            ->json('summary.avg_mtbf');
        $this->assertGreaterThan(0, $sendiri);
        $this->assertEqualsWithDelta($sendiri, $tren[0]['mtbf'], 0.001);
    }

    public function test_rentang_panjang_dipotong_ke_36_bulan_terakhir(): void
    {
        $this->mesin('A1');

        $tren = $this->tren('maintenance.mttr.tren', ['date_from' => '2000-01-01', 'date_to' => '2026-10-19']);

        $this->assertCount(36, $tren);
        $this->assertSame('2023-11', $tren[0]['bulan']);
        $this->assertSame('2023-11-01', $tren[0]['from']);
        $this->assertSame('2026-10', $tren[35]['bulan']);
        $this->assertSame('2026-10-19', $tren[35]['to']);
    }

    public function test_filter_mesin_ikut_ke_tren(): void
    {
        $a = $this->mesin('A1');
        $b = $this->mesin('B1');
        $this->bdMesin($a, '2026-01-05', 2.0);
        $this->bdMesin($b, '2026-01-05', 8.0);

        $tren = $this->tren('maintenance.mttr.tren', ['date_from' => '2026-01-01', 'date_to' => '2026-01-31', 'mesin_ids' => [$b->id]]);

        $this->assertEqualsWithDelta(8.0, $tren[0]['mttr'], 0.01);
        $this->assertSame(1, $tren[0]['total_bd']);
    }

    public function test_tren_dies_pakai_service_dies(): void
    {
        MasterShift::firstOrCreate(['nama_shift' => 'Shift 1'], ['jam_start' => '06:00', 'jam_finish' => '14:00']);
        $part = MasterPart::create(['part_no' => 'PT-1', 'part_name' => 'Bracket', 'customer' => 'Toyota', 'tanggal_instalasi' => '2025-01-01']);
        $pr = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);
        foreach ([['2026-04-02', 1.0], ['2026-04-09', 3.0]] as [$tgl, $repair]) {
            TransaksiBreakdownDies::create([
                'no_lkd' => uniqid(), 'id_part_proses' => $pr->id,
                'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '08:00',
                'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
                'repair_time' => $repair, 'total_downtime' => $repair,
            ]);
        }

        $mttr = $this->tren('maintenance.mttr-dies.tren', ['date_from' => '2026-03-01', 'date_to' => '2026-04-30']);
        $this->assertNull($mttr[0]['mttr']);
        $this->assertEqualsWithDelta(2.0, $mttr[1]['mttr'], 0.01);
        $this->assertSame(2, $mttr[1]['total_bd']);

        $mtbf = $this->tren('maintenance.mtbf-dies.tren', ['date_from' => '2026-04-01', 'date_to' => '2026-04-30', 'customer' => 'Honda']);
        $this->assertNull($mtbf[0]['mtbf'], 'filter customer ikut: nol dies Honda');
    }

    public static function halaman(): array
    {
        return [
            'mttr mesin' => ['maintenance.mttr'], 'mtbf mesin' => ['maintenance.mtbf'],
            'mttr dies' => ['maintenance.mttr-dies'], 'mtbf dies' => ['maintenance.mtbf-dies'],
        ];
    }

    #[DataProvider('halaman')]
    public function test_halaman_memasang_dua_grafik_kanan_kiri(string $route): void
    {
        $html = $this->actingAs($this->admin())->get(route($route))->assertOk()->getContent();

        $this->assertStringContainsString('<canvas id="trenChart"></canvas>', $html);
        $this->assertStringContainsString('id="periodeKanan"', $html);
        $this->assertStringContainsString('function trenBulananMtc(cfg)', $html);
        // URL tren benar untuk halaman INI (salin-tempel antar halaman gampang salah sasaran).
        $this->assertStringContainsString('url: `'.route($route.'.tren').'`', $html);
        // Bulan terpilih menimpa rentang data tabel/grafik kanan.
        $this->assertStringContainsString("if (bulan) { params.set('date_from', bulan.from); params.set('date_to', bulan.to);", $html);
        // Respons klik bulan yang basi tidak boleh menimpa bulan yang terakhir diklik.
        $this->assertMatchesRegularExpression('/const generasi = \+\+generasiTabel;.*?const data = await res\.json\(\);\s*if \(generasi !== generasiTabel\) return;/s', $html);
        // Reset pilihan SINKRON sebelum pemuat paralel jalan.
        $this->assertMatchesRegularExpression('/tren\.reset\(\);\s*await Promise\.all\(\[tren\.muat\(paramFilter\(\)\), loadTabelInti\(\)\]\);/', $html);
    }

    #[DataProvider('halaman')]
    public function test_guest_boleh_baca_tren_staff_qc_tidak(string $route): void
    {
        $guest = User::factory()->create(['role' => 'guest']);
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($guest)->getJson(route($route.'.tren'))->assertOk()->assertJsonStructure(['tren']);
        $this->actingAs($qc)->getJson(route($route.'.tren'))->assertForbidden();
    }
}
