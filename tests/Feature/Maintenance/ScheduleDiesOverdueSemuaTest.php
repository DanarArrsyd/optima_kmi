<?php

namespace Tests\Feature\Maintenance;

use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveSchedule;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\KalenderKerjaService;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Laporan 7 Okt 2026: Control Stroke Dies menampilkan 434 dies, 51 "SEGERA
 * PREVENTIVE" (>=100%), tapi Schedule PDF cuma memuat 22 dies.
 *
 * Root cause: kuota hardcode 5 dies/MINGGU × 5 minggu Oktober (minggu 1 cuma
 * Kam-Jum = 2 slot) = 22 slot. 29 overdue sisanya jadi backlog (tanggal_rencana
 * NULL) yang sengaja tidak digambar PDF.
 *
 * Fixture meniru skala itu: 434 dies, 51 overdue, 25 perlu perhatian.
 */
class ScheduleDiesOverdueSemuaTest extends TestCase
{
    use RefreshDatabase;

    private const BULAN = '2026-10-01';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-07 09:00:00');
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 434 dies: 51 overdue (satu tepat 30.000), 25 perlu perhatian, sisanya ok. */
    private function bangunPabrik(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00']);
        $op = MasterKaryawan::create(['nik' => 'P1', 'nama_karyawan' => 'OP', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'PRESS', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 1, 'tanggal_instalasi' => '2020-01-01']);

        $buat = function (string $partNo, string $proses, int $stroke, string $tgl = '2026-09-01') use ($shift, $op, $mesin) {
            $part = MasterPart::firstOrCreate(['part_no' => $partNo], ['part_name' => "DIES {$partNo}", 'customer' => 'SJM']);
            $pp = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => MasterPartProses::where('id_part', $part->id)->count() + 1,
                'nama_proses' => $proses, 'sph_standar_produksi' => 500]);
            TransaksiProduksi::create([
                'tanggal_produksi' => $tgl, 'id_shift' => $shift->id, 'id_karyawan' => $op->id, 'id_mesin' => $mesin->id,
                'id_part_proses' => $pp->id, 'jam_start' => '07:00', 'jam_finish' => '15:00', 'qty_aktual' => $stroke,
                'actual_cycle_time' => 1, 'created_by' => $this->admin->id,
            ]);

            return $pp;
        };

        // 51 overdue: satu PERSIS 30.000 (=100%), lalu makin tinggi sampai 38.000.
        // Dua di antaranya part no SAMA, proses beda — tidak boleh ter-collapse.
        $buat('PN-SAMA', 'BENDING', 32000);
        $buat('PN-SAMA', 'PIERCE', 31000);
        $buat('PN-PAS', 'BLANK', 30000);
        for ($i = 1; $i <= 48; $i++) {
            $buat("PN-OD-{$i}", 'FORMING', 30000 + $i * 166);
        }
        // 25 perlu perhatian (80–99%).
        for ($i = 1; $i <= 25; $i++) {
            $buat("PN-WR-{$i}", 'BLANK', 24000 + $i * 200);
        }
        // Dies yang PERNAH lewat ambang tapi sudah preventive → stroke reset, bukan overdue.
        $reset = $buat('PN-RESET', 'BLANK', 45000, '2026-08-01');
        DiesPreventiveLog::create(['id_part_proses' => $reset->id, 'tanggal_preventive' => '2026-09-15', 'id_karyawan' => $op->id,
            'stroke_at_preventive' => 45000, 'created_by' => $this->admin->id]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-09-20', 'id_shift' => $shift->id, 'id_karyawan' => $op->id, 'id_mesin' => $mesin->id,
            'id_part_proses' => $reset->id, 'jam_start' => '07:00', 'jam_finish' => '15:00', 'qty_aktual' => 2000,
            'actual_cycle_time' => 1, 'created_by' => $this->admin->id,
        ]);
        // Sisanya ok sampai total 434.
        for ($i = 1; $i <= 434 - 51 - 25 - 1; $i++) {
            $buat("PN-OK-{$i}", 'BLANK', 1000);
        }
    }

    private function generate(): PreventiveSchedule
    {
        return app(PreventiveScheduleService::class)->generate('dies', Carbon::parse(self::BULAN));
    }

    public function test_semua_dies_segera_preventive_di_dashboard_masuk_schedule_pdf(): void
    {
        $this->bangunPabrik();
        $ringkas = $this->getJson(route('maintenance.control-stroke-dies.data'))->json('summary');
        $this->assertSame(434, $ringkas['total_dies']);
        $this->assertSame(51, $ringkas['overdue'], 'setup: dashboard harus melihat 51 SEGERA PREVENTIVE');

        $schedule = $this->generate();
        $diPdf = $schedule->items()->whereNotNull('tanggal_rencana')->where('pct_snapshot', '>=', 100)->get();

        // Jumlahnya dibandingkan dengan DASHBOARD, bukan angka mati.
        $this->assertCount($ringkas['overdue'], $diPdf, 'Ada dies SEGERA PREVENTIVE yang tidak masuk schedule PDF.');
        $this->assertSame(0, $schedule->items()->whereNull('tanggal_rencana')->where('pct_snapshot', '>=', 100)->count(), 'overdue tidak boleh jadi backlog');
        $this->assertGreaterThan(22, $schedule->items()->whereNotNull('tanggal_rencana')->count());
    }

    public function test_tanggal_plan_valid_tersebar_dan_tanpa_duplikat(): void
    {
        $this->bangunPabrik();
        $items = $this->generate()->items()->whereNotNull('tanggal_rencana')->where('pct_snapshot', '>=', 100)->get();

        $this->assertSame($items->count(), $items->pluck('id_part_proses')->unique()->count(), 'dies dobel di schedule');

        $hariValid = collect(range(7, 31))->map(fn ($d) => Carbon::parse(sprintf('2026-10-%02d', $d)))
            ->filter(fn (Carbon $d) => KalenderKerjaService::hariKerja($d) && $d->isoWeekday() <= 5);
        foreach ($items as $it) {
            $tgl = $it->tanggal_rencana;
            $this->assertTrue(KalenderKerjaService::hariKerja($tgl), "{$tgl->toDateString()} bukan hari kerja");
            $this->assertLessThanOrEqual(5, $tgl->isoWeekday(), "{$tgl->toDateString()} Sabtu/Minggu");
            $this->assertGreaterThanOrEqual('2026-10-07', $tgl->toDateString(), 'PLAN di tanggal yang sudah lewat');
            $this->assertSame(10, $tgl->month);
            $this->assertSame(app(PreventiveScheduleService::class)->mingguKeUntuk('dies', Carbon::parse(self::BULAN), $tgl), $it->minggu_ke);
        }

        $perHari = $items->countBy(fn ($it) => $it->tanggal_rencana->toDateString());
        $this->assertSame($hariValid->count(), $perHari->count(), 'tidak semua hari valid dipakai — sebaran tidak merata');
        $this->assertLessThanOrEqual((int) ceil($items->count() / $hariValid->count()), $perHari->max());
        $this->assertGreaterThanOrEqual(intdiv($items->count(), $hariValid->count()), $perHari->min());
    }

    public function test_part_no_sama_proses_beda_dan_tepat_ambang_ikut_dan_yang_sudah_reset_tidak(): void
    {
        $this->bangunPabrik();
        $items = $this->generate()->items()->with('partProses.part')->get();
        $nama = $items->map(fn ($it) => $it->partProses->part->part_no.'|'.$it->partProses->nama_proses);

        $this->assertContains('PN-SAMA|BENDING', $nama);
        $this->assertContains('PN-SAMA|PIERCE', $nama);
        $this->assertContains('PN-PAS|BLANK', $nama, 'actual = standard (100%) wajib masuk');
        $this->assertNotContains('PN-RESET|BLANK', $nama, 'stroke sudah reset setelah preventive — bukan overdue');
    }

    public function test_dies_paling_kritis_dijadwalkan_lebih_awal(): void
    {
        $this->bangunPabrik();
        $items = $this->generate()->items()->whereNotNull('tanggal_rencana')->where('pct_snapshot', '>=', 100)->get();

        $mingguAwal = $items->min('minggu_ke');
        $mingguAkhir = $items->max('minggu_ke');
        $this->assertGreaterThan(
            $items->where('minggu_ke', $mingguAkhir)->max('pct_snapshot'),
            $items->where('minggu_ke', $mingguAwal)->min('pct_snapshot'),
            'dies paling lewat ambang harus di minggu paling awal'
        );
    }

    public function test_pdf_dan_halaman_tidak_mengacak_ulang_hanya_generate_ulang(): void
    {
        $this->bangunPabrik();
        $this->generate();
        $jepret = fn () => PreventiveSchedule::where('jenis', 'dies')->firstOrFail()->items()
            ->orderBy('id_part_proses')->get(['id', 'id_part_proses', 'tanggal_rencana'])->toArray();
        $awal = $jepret();

        $this->get(route('maintenance.control-stroke-dies.schedule-pdf'))->assertOk();
        $this->get(route('maintenance.control-stroke-dies.schedule-pdf'))->assertOk();
        $this->get(route('maintenance.control-stroke-dies.schedule-edit'))->assertOk();
        $this->get(route('maintenance.control-stroke-dies'))->assertOk();

        $this->assertSame($awal, $jepret(), 'membuka PDF/halaman mengubah schedule');
    }

    public function test_generate_ulang_tanpa_duplikat_dan_riwayat_act_utuh(): void
    {
        $this->bangunPabrik();
        $logSebelum = DiesPreventiveLog::count();
        $this->generate();

        $this->post(route('maintenance.control-stroke-dies.schedule-generate'))->assertRedirect()
            ->assertSessionHas('success', fn ($pesan) => str_contains($pesan, '(51 segera preventive)'));

        $s = PreventiveSchedule::where('jenis', 'dies')->get();
        $this->assertCount(1, $s, 'generate ulang membuat schedule kedua');
        $items = $s->first()->items;
        $this->assertSame($items->count(), $items->pluck('id_part_proses')->unique()->count());
        $this->assertSame(51, $items->where('pct_snapshot', '>=', 100)->whereNotNull('tanggal_rencana')->count());
        $this->assertSame($logSebelum, DiesPreventiveLog::count(), 'riwayat preventive (ACT) tersentuh');
    }
}
