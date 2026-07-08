<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatbotToolsTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_mtbf_mttr_dies_returns_real_numbers_for_known_part(): void
    {
        $part = MasterPart::create([
            'part_no' => 'BT-091A-3', 'part_name' => 'Bracket', 'customer' => 'Toyota',
            'tanggal_instalasi' => '2026-01-01',
        ]);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 800, 'sph_standar_qc' => 400,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '11:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 2.0, 'total_downtime' => 3.0,
        ]);

        $result = (new ChatbotTools())->getMtbfMttrDies('BT-091A-3', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result[0]['count']);
        $this->assertEqualsWithDelta(2.0, $result[0]['mttr_jam'], 0.01);
    }

    public function test_get_mtbf_mttr_dies_returns_error_for_unknown_part(): void
    {
        $result = (new ChatbotTools())->getMtbfMttrDies('NOT-EXIST', '2026-07');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_mtbf_mttr_mesin_returns_real_numbers_for_known_mesin(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-01', 'nama_mesin' => 'Press 1', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => '2026-01-01',
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.5,
        ]);

        $result = (new ChatbotTools())->getMtbfMttrMesin('M-01', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['count']);
        $this->assertEqualsWithDelta(1.0, $result['mttr_jam'], 0.01);
    }

    public function test_get_rekap_ng_sums_qty_ng_for_proses_and_periode(): void
    {
        $part = MasterPart::create(['part_no' => 'PT-01', 'part_name' => 'Cover', 'customer' => 'Honda']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 600, 'sph_standar_qc' => 300,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $user = User::factory()->create();
        TransaksiQc::create([
            'tgl_pengecekan' => '2026-07-10', 'tgl_produksi_part' => '2026-07-10',
            'id_shift' => $shift->id, 'id_karyawan_qc' => $karyawan->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_ok' => 90, 'qty_ng' => 10,
            'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getRekapNg('BENDING', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(10, $result['total_ng']);
        $this->assertSame(100, $result['total_inspeksi']);
    }

    public function test_bandingkan_produksi_returns_both_periods_and_delta(): void
    {
        $part = MasterPart::create(['part_no' => 'PT-02', 'part_name' => 'Plate', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press Test', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $user = User::factory()->create();
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 3000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->bandingkanProduksi('2026-06', '2026-07');

        $this->assertSame(3000, $result['periode1']['total_qty_aktual']);
        $this->assertSame(4000, $result['periode2']['total_qty_aktual']);
        $this->assertSame(1000, $result['delta_qty_aktual']);
    }

    public function test_get_total_breakdown_dies_sums_across_month_range(): void
    {
        $part1 = MasterPart::create(['part_no' => 'PT-D1', 'part_name' => 'A', 'customer' => 'Toyota']);
        $proses1 = MasterPartProses::create([
            'id_part' => $part1->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $part2 = MasterPart::create(['part_no' => 'PT-D2', 'part_name' => 'B', 'customer' => 'Honda']);
        $proses2 = MasterPartProses::create([
            'id_part' => $part2->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-A', 'id_part_proses' => $proses1->id,
            'tanggal_breakdown' => '2026-06-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-B', 'id_part_proses' => $proses2->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTotalBreakdownDies('2026-06', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_breakdown']);
        $this->assertSame(2, $result['jumlah_dies_terdampak']);
    }

    public function test_get_total_breakdown_dies_returns_error_for_invalid_month_format(): void
    {
        $result = (new ChatbotTools())->getTotalBreakdownDies('bulan-salah', '2026-07');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_breakdown_dies_returns_error_when_start_after_end(): void
    {
        $result = (new ChatbotTools())->getTotalBreakdownDies('2026-07', '2026-06');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_breakdown_mesin_sums_across_month_range(): void
    {
        $mesin1 = MasterMesin::create([
            'id_mesin' => 'M-X1', 'nama_mesin' => 'Press X1', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesin2 = MasterMesin::create([
            'id_mesin' => 'M-X2', 'nama_mesin' => 'Press X2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-A', 'id_mesin' => $mesin1->id,
            'tanggal_breakdown' => '2026-06-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-06-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-06-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Ringan', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-B', 'id_mesin' => $mesin2->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTotalBreakdownMesin('2026-06', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_breakdown']);
        $this->assertSame(2, $result['jumlah_mesin_terdampak']);
    }

    public function test_get_problem_dies_returns_raw_list_with_part_filter(): void
    {
        $part = MasterPart::create(['part_no' => 'PD-01', 'part_name' => 'Frame', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $otherPart = MasterPart::create(['part_no' => 'PD-02', 'part_name' => 'Cover', 'customer' => 'Honda']);
        $otherProses = MasterPartProses::create([
            'id_part' => $otherPart->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-P1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '11:00',
            'problem_dies' => 'Motor overheat', 'penyebab_breakdown' => 'Bearing aus', 'tindakan_perbaikan' => 'Ganti bearing',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 2.0, 'total_downtime' => 3.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-P2', 'id_part_proses' => $otherProses->id,
            'tanggal_breakdown' => '2026-07-06', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-06', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-06', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Baut kendor', 'penyebab_breakdown' => 'Vibrasi tinggi', 'tindakan_perbaikan' => 'Kencangkan baut',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07', 'PD-01');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
        $this->assertCount(1, $result['data']);
        $this->assertSame('Motor overheat', $result['data'][0]['problem']);
        $this->assertSame('Bearing aus', $result['data'][0]['penyebab']);
        $this->assertSame('Ganti bearing', $result['data'][0]['tindakan']);
        $this->assertSame('Frame', $result['data'][0]['part']);
    }

    public function test_get_problem_dies_without_part_filter_returns_all(): void
    {
        $part = MasterPart::create(['part_no' => 'PD-03', 'part_name' => 'Bracket', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-P3', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Sensor error', 'penyebab_breakdown' => 'Kabel putus', 'tindakan_perbaikan' => 'Ganti kabel',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
    }

    public function test_get_problem_dies_returns_error_for_unknown_part(): void
    {
        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07', 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_problem_dies_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getProblemDies('2020-01', '2020-01');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_problem_dies_truncates_at_150_rows_with_note(): void
    {
        $part = MasterPart::create(['part_no' => 'PD-04', 'part_name' => 'Panel', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        for ($i = 1; $i <= 151; $i++) {
            TransaksiBreakdownDies::create([
                'no_lkd' => "LKD-BULK-{$i}", 'id_part_proses' => $proses->id,
                'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => "Problem {$i}", 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(151, $result['total_record']);
        $this->assertCount(150, $result['data']);
        $this->assertArrayHasKey('note', $result);
    }

    public function test_get_problem_mesin_returns_raw_list_with_mesin_filter(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'PM-01', 'nama_mesin' => 'Press A', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $otherMesin = MasterMesin::create([
            'id_mesin' => 'PM-02', 'nama_mesin' => 'Press B', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-P1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Motor macet', 'penyebab_breakdown' => 'Oli habis', 'tindakan_perbaikan' => 'Isi oli',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-P2', 'id_mesin' => $otherMesin->id,
            'tanggal_breakdown' => '2026-07-06', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-06', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-06', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Panel error', 'penyebab_breakdown' => 'Korsleting', 'tindakan_perbaikan' => 'Ganti panel',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemMesin('2026-07', '2026-07', 'PM-01');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
        $this->assertSame('Motor macet', $result['data'][0]['problem']);
        $this->assertSame('Oli habis', $result['data'][0]['penyebab']);
        $this->assertSame('Isi oli', $result['data'][0]['tindakan']);
        $this->assertSame('Press A', $result['data'][0]['mesin']);
    }

    public function test_get_problem_mesin_returns_error_for_unknown_mesin(): void
    {
        $result = (new ChatbotTools())->getProblemMesin('2026-07', '2026-07', 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_problem_mesin_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getProblemMesin('2020-01', '2020-01');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_ranking_breakdown_mesin_sorts_by_count_desc(): void
    {
        $mesinLow = MasterMesin::create([
            'id_mesin' => 'RM-01', 'nama_mesin' => 'Press Low', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesinHigh = MasterMesin::create([
            'id_mesin' => 'RM-02', 'nama_mesin' => 'Press High', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-R1', 'id_mesin' => $mesinLow->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        foreach (['LKM-R2', 'LKM-R3'] as $i => $noLkm) {
            TransaksiBreakdownMesin::create([
                'no_lkm' => $noLkm, 'id_mesin' => $mesinHigh->id,
                'tanggal_breakdown' => '2026-07-0'.($i + 6), 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-0'.($i + 6), 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-0'.($i + 6), 'jam_selesai_perbaikan' => '10:00',
                'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getRankingBreakdownMesin('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Press High', $result['ranking'][0]['mesin']);
        $this->assertSame(2, $result['ranking'][0]['jumlah_breakdown']);
        $this->assertSame('Press Low', $result['ranking'][1]['mesin']);
        $this->assertSame(1, $result['ranking'][1]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_mesin_returns_error_when_no_breakdowns(): void
    {
        MasterMesin::create([
            'id_mesin' => 'RM-03', 'nama_mesin' => 'Press Idle', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownMesin('2026-07', '2026-07');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_ranking_breakdown_dies_sorts_by_count_desc(): void
    {
        $partLow = MasterPart::create(['part_no' => 'RD-01', 'part_name' => 'Low Part', 'customer' => 'Toyota']);
        $prosesLow = MasterPartProses::create([
            'id_part' => $partLow->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $partHigh = MasterPart::create(['part_no' => 'RD-02', 'part_name' => 'High Part', 'customer' => 'Honda']);
        $prosesHigh = MasterPartProses::create([
            'id_part' => $partHigh->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-R1', 'id_part_proses' => $prosesLow->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        foreach (['LKD-R2', 'LKD-R3'] as $i => $noLkd) {
            TransaksiBreakdownDies::create([
                'no_lkd' => $noLkd, 'id_part_proses' => $prosesHigh->id,
                'tanggal_breakdown' => '2026-07-0'.($i + 6), 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-0'.($i + 6), 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-0'.($i + 6), 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getRankingBreakdownDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['ranking'][0]['jumlah_breakdown']);
        $this->assertSame(1, $result['ranking'][1]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_dies_returns_error_when_no_breakdowns(): void
    {
        $part = MasterPart::create(['part_no' => 'RD-03', 'part_name' => 'Idle Part', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownDies('2026-07', '2026-07');

        $this->assertArrayHasKey('error', $result);
    }
}
