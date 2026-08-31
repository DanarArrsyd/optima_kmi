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
}
