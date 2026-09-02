<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveSchedule;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private MasterShift $shift;

    private MasterKaryawan $karyawan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        Carbon::setTestNow('2026-08-20');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Mesin dengan stroke tinggi (mendekati/lewat threshold 300000) via 1 baris produksi besar. */
    private function mesinDenganStroke(string $kode, int $stroke): MasterMesin
    {
        $mesin = MasterMesin::create([
            'id_mesin' => $kode, 'nama_mesin' => "Press {$kode}", 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => '2020-01-01',
        ]);
        $part = MasterPart::create(['part_no' => "P-{$kode}", 'part_name' => "Part {$kode}", 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $stroke,
            'actual_cycle_time' => 10, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        return $mesin;
    }

    /** Part+proses dies dengan stroke tinggi (mendekati/lewat threshold 30000) via 1 baris produksi besar. */
    private function diesDenganStroke(string $kode, int $stroke): MasterPartProses
    {
        $mesin = MasterMesin::create([
            'id_mesin' => "M-{$kode}", 'nama_mesin' => "Press M-{$kode}", 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => '2020-01-01',
        ]);
        $part = MasterPart::create(['part_no' => "D-{$kode}", 'part_name' => "Dies {$kode}", 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $stroke,
            'actual_cycle_time' => 10, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        return $proses;
    }

    public function test_generate_dies_menghasilkan_item_dengan_id_part_proses_bukan_id_mesin(): void
    {
        // 6 dies overdue (>=100% dari threshold 30000) — kapasitas 5/minggu.
        for ($i = 1; $i <= 6; $i++) {
            $this->diesDenganStroke("D{$i}", 31000);
        }

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('dies', Carbon::parse('2026-08-01'));

        $this->assertGreaterThan(0, $schedule->items()->count());

        foreach ($schedule->items as $item) {
            $this->assertNotNull($item->id_part_proses, 'Item dies harus punya id_part_proses.');
            $this->assertNull($item->id_mesin, 'Item dies tidak boleh punya id_mesin.');
        }

        $this->assertMatchesRegularExpression('#^PM-DIES/1/VIII/2026$#', $schedule->nomor_dokumen);

        // Kapasitas 5 dies/minggu tidak boleh dilanggar.
        $perMinggu = $schedule->items()->get()->groupBy('minggu_ke');
        foreach ($perMinggu as $items) {
            $this->assertLessThanOrEqual(5, $items->count(), 'Kapasitas 5 dies/minggu dilanggar.');
        }
    }

    public function test_kapasitas_mesin_satu_per_minggu_membatasi_jumlah_terjadwal(): void
    {
        // 5 mesin overdue (>=100% dari 300000) — kapasitas 1/minggu, Agustus 2026 punya 5 minggu kerja.
        for ($i = 1; $i <= 5; $i++) {
            $this->mesinDenganStroke("A{$i}", 310000);
        }

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertLessThanOrEqual(5, $schedule->items()->count());
        $this->assertGreaterThan(0, $schedule->items()->count());

        // Nol dua item di minggu yang sama (kapasitas 1/minggu buat mesin).
        $perMinggu = $schedule->items()->get()->groupBy('minggu_ke');
        foreach ($perMinggu as $items) {
            $this->assertCount(1, $items, 'Kapasitas 1 mesin/minggu dilanggar.');
        }
    }

    public function test_urutan_prioritas_pct_paling_tinggi_dijadwalkan_duluan(): void
    {
        $this->mesinDenganStroke('LOW', 240000);  // 80% — warning
        $this->mesinDenganStroke('HIGH', 320000); // 106.7% — overdue, paling mendesak

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $item1 = $schedule->items()->orderBy('tanggal_rencana')->first();
        $this->assertSame('HIGH', $item1->mesin->id_mesin, 'Item paling mendesak (pct tertinggi) harus dapat slot pertama.');
    }

    public function test_mesin_berstatus_ok_tidak_ikut_dijadwalkan(): void
    {
        $this->mesinDenganStroke('OK', 100000); // 33% — ok, jauh dari threshold

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertSame(0, $schedule->items()->count());
    }

    public function test_tanggal_rencana_selalu_hari_kerja(): void
    {
        $this->mesinDenganStroke('A1', 310000);

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $item = $schedule->items()->first();
        $this->assertFalse($item->tanggal_rencana->isSunday(), 'tanggal_rencana jatuh di hari Minggu.');
    }

    public function test_generate_ulang_bulan_yang_sama_mempertahankan_nomor_dokumen(): void
    {
        $this->mesinDenganStroke('A1', 310000);

        $svc = app(PreventiveScheduleService::class);
        $pertama = $svc->generate('mesin', Carbon::parse('2026-08-01'));
        $nomorAwal = $pertama->nomor_dokumen;

        $keduaMesin = $this->mesinDenganStroke('A2', 320000); // ubah data, generate ulang bulan SAMA
        $kedua = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertSame($nomorAwal, $kedua->nomor_dokumen, 'Nomor dokumen berubah padahal cuma generate ulang bulan yg sama.');
        $this->assertSame(1, PreventiveSchedule::where('bulan', '2026-08-01')->where('jenis', 'mesin')->count(), 'Regenerate seharusnya UPDATE, bukan bikin baris jadwal baru.');
    }

    public function test_nomor_dokumen_format_dan_increment_per_tahun(): void
    {
        $this->mesinDenganStroke('A1', 310000);
        $svc = app(PreventiveScheduleService::class);

        $agustus = $svc->generate('mesin', Carbon::parse('2026-08-01'));
        $this->assertMatchesRegularExpression('#^PM-MESIN/1/VIII/2026$#', $agustus->nomor_dokumen);

        $this->mesinDenganStroke('B1', 310000);
        $september = $svc->generate('mesin', Carbon::parse('2026-09-01'));
        $this->assertMatchesRegularExpression('#^PM-MESIN/2/IX/2026$#', $september->nomor_dokumen);
    }

    public function test_jenis_tidak_dikenal_dilempar_sebagai_exception_bukan_menulis_data(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(PreventiveScheduleService::class)->generate('DIES', Carbon::parse('2026-08-01'));
    }

    /**
     * Item di bawah pct reaktif (80%) tapi laju produksinya tinggi sehingga
     * DIPROYEKSIKAN overdue sebelum generate bulan depan — harus tetap masuk
     * jadwal PROAKTIF, dan diurut proyeksi PALING DEKAT duluan (bukan pct
     * tertinggi duluan seperti sebelumnya).
     *
     * Angka dihitung manual supaya deterministik — baca komentar di tiap baris
     * sebelum mengubah nilai apa pun:
     *
     * EARLY: current_stroke 220.000 (73,3%) — di bawah 80%, TIDAK reaktif.
     *   Baris lama (di luar window 30 hari dari "sekarang" 2026-08-15): 50.000.
     *   Baris baru (dalam window, 2026-08-01): 170.000 → laju = 170.000/30 ≈ 5.666,67/hari.
     *   Sisa = 300.000 - 220.000 = 80.000. Hari = ceil(80.000/5.666,67) = 15.
     *   Proyeksi = 2026-08-15 + 15 hari = 2026-08-30 (SEBELUM cutoff 2026-09-01).
     *
     * LATE: current_stroke 236.000 (78,7%) — juga di bawah 80%, TIDAK reaktif.
     *   Baris lama: 116.000. Baris baru (2026-08-01): 120.000 → laju = 120.000/30 = 4.000/hari.
     *   Sisa = 300.000 - 236.000 = 64.000. Hari = ceil(64.000/4.000) = 16.
     *   Proyeksi = 2026-08-15 + 16 hari = 2026-08-31 (SEBELUM cutoff, TAPI setelah EARLY).
     *
     * FAR: current_stroke 100.000 (33,3%) — jauh dari threshold.
     *   Semua produksi dalam window: 100.000 → laju = 100.000/30 ≈ 3.333,33/hari.
     *   Sisa = 200.000. Hari = ceil(200.000/3.333,33) = 60.
     *   Proyeksi = 2026-08-15 + 60 hari = 2026-10-14 (SETELAH cutoff 2026-09-01) → TIDAK eligible.
     */
    public function test_item_dibawah_pct_reaktif_tapi_proyeksi_dekat_tetap_masuk_dan_urut_lebih_dulu(): void
    {
        Carbon::setTestNow('2026-08-15');

        $admin = User::factory()->create(['role' => 'admin']);

        $buatMesin = function (string $kode, int $strokeLama, int $strokeBaru) use ($admin) {
            $mesin = MasterMesin::create([
                'id_mesin' => $kode, 'nama_mesin' => "Press {$kode}", 'line' => '1',
                'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
                'tanggal_instalasi' => '2020-01-01',
            ]);
            $part = MasterPart::create(['part_no' => "P-{$kode}", 'part_name' => "Part {$kode}", 'customer' => 'Toyota']);
            $proses = MasterPartProses::create([
                'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
                'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
            ]);
            if ($strokeLama > 0) {
                TransaksiProduksi::create([
                    'tanggal_produksi' => '2026-06-01', 'id_shift' => $this->shift->id,
                    'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                    'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $strokeLama,
                    'actual_cycle_time' => 10, 'created_by' => $admin->id,
                ]);
            }
            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-08-01', 'id_shift' => $this->shift->id,
                'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $strokeBaru,
                'actual_cycle_time' => 10, 'created_by' => $admin->id,
            ]);

            return $mesin;
        };

        $buatMesin('EARLY', 50000, 170000);  // current_stroke 220.000, proyeksi 2026-08-30
        $buatMesin('LATE', 116000, 120000);  // current_stroke 236.000, proyeksi 2026-08-31
        $buatMesin('FAR', 0, 100000);        // current_stroke 100.000, proyeksi 2026-10-14 (excluded)

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $items = $schedule->items()->with('mesin')->get()->keyBy(fn ($i) => $i->mesin->id_mesin);

        $this->assertCount(2, $items, 'FAR seharusnya tidak eligible (proyeksi lewat cutoff).');
        $this->assertArrayNotHasKey('FAR', $items);
        $this->assertArrayHasKey('EARLY', $items);
        $this->assertArrayHasKey('LATE', $items);
        $this->assertTrue(
            $items['EARLY']->tanggal_rencana->lt($items['LATE']->tanggal_rencana),
            'EARLY (proyeksi lebih dekat) harus dapat slot lebih awal dari LATE, walau pct EARLY (73,3%) lebih rendah dari LATE (78,7%).'
        );

        Carbon::setTestNow();
    }

    /** Mesin cuma boleh PLAN Senin-Kamis — Jumat (14 jam) & Sabtu (10 jam) kependekan buat PM penuh. */
    public function test_plan_mesin_nol_pernah_jatuh_jumat_atau_sabtu(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->mesinDenganStroke("A{$i}", 310000);
        }

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        foreach ($schedule->items as $item) {
            $this->assertNotContains(
                $item->tanggal_rencana->isoWeekday(),
                [5, 6],
                "tanggal_rencana {$item->tanggal_rencana->toDateString()} jatuh di Jumat/Sabtu — mesin cuma boleh Senin-Kamis."
            );
        }
    }

    /**
     * Dies boleh sampai Jumat (kapasitas 5/minggu, hari diizinkan persis 5 —
     * Senin-Jumat — jadi minggu penuh otomatis makai SEMUA hari termasuk Jumat).
     * Agustus 2026: 1 Agustus jatuh Sabtu (dikeluarkan dari hari diizinkan
     * dies/mesin dua-duanya), jadi bucket minggu PERTAMA yang punya kandidat
     * hari diizinkan dies adalah Senin 3 - Jumat 7 Agustus, persis 5 hari kerja.
     */
    public function test_plan_dies_boleh_jumat_tapi_nol_sabtu_minggu(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->diesDenganStroke("D{$i}", 31000);
        }

        $schedule = app(PreventiveScheduleService::class)->generate('dies', Carbon::parse('2026-08-01'));

        $this->assertCount(5, $schedule->items, 'Setup salah: 5 dies overdue harus semua kebagian slot minggu pertama (kapasitas 5).');

        $weekdaysDipakai = $schedule->items->map(fn ($item) => $item->tanggal_rencana->isoWeekday())->sort()->values();

        $this->assertSame([1, 2, 3, 4, 5], $weekdaysDipakai->all(), 'Minggu penuh 5 dies harus makai persis Senin s.d. Jumat.');
        foreach ($schedule->items as $item) {
            $this->assertNotContains($item->tanggal_rencana->isoWeekday(), [6, 7], 'Dies nol boleh Sabtu/Minggu.');
        }
    }

    /** Rotasi: mesin di minggu berbeda nol boleh selalu jatuh hari yang sama (mis. Senin terus). */
    public function test_rotasi_hari_mesin_antar_minggu_nol_monoton(): void
    {
        $this->mesinDenganStroke('W1', 310000);
        $this->mesinDenganStroke('W2', 305000);

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertCount(2, $schedule->items);

        $terurut = $schedule->items->sortBy('tanggal_rencana')->values();
        $hariMinggu1 = $terurut[0]->tanggal_rencana->isoWeekday();
        $hariMinggu2 = $terurut[1]->tanggal_rencana->isoWeekday();

        $this->assertNotSame($hariMinggu1, $hariMinggu2, 'Rotasi nol jalan — dua mesin di minggu berbeda jatuh hari yang SAMA.');
    }
}
