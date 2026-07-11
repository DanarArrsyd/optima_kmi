<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\ChatbotTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
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

    public function test_get_problem_dies_with_no_period_returns_all_time_data(): void
    {
        $part = MasterPart::create(['part_no' => 'PDN-01', 'part_name' => 'NoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-NP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Old problem', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-NP2', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Recent problem', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_record']);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame('semua periode', $result['bulan_akhir']);
    }

    public function test_get_problem_dies_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getProblemDies('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_problem_dies_with_both_periods_still_behaves_as_before(): void
    {
        $part = MasterPart::create(['part_no' => 'PDN-02', 'part_name' => 'BothPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-BP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('2026-07', $result['bulan_mulai']);
        $this->assertSame('2026-07', $result['bulan_akhir']);
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

    public function test_get_problem_mesin_with_no_period_returns_all_time_data(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'PMN-01', 'nama_mesin' => 'Press NoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-NP1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Old problem', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getProblemMesin();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_record']);
        $this->assertSame('semua periode', $result['bulan_mulai']);
    }

    public function test_get_problem_mesin_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getProblemMesin(null, '2026-07');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
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

    public function test_get_ranking_breakdown_mesin_with_no_period_returns_all_time_data(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'RMN-01', 'nama_mesin' => 'Press RankNoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-RNP1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownMesin();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(1, $result['ranking'][0]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_mesin_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getRankingBreakdownMesin('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
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

    /**
     * Kunci pemetaan field bocor: diesDashboard->compute mengembalikan key ala-mesin
     * (nama_mesin/kode_mesin) yang di-remap ke part/part_no. Kalau service mengganti
     * nama key-nya, ranking dies akan diam-diam salah — test ini bikin break berisik.
     */
    public function test_get_ranking_breakdown_dies_maps_part_fields(): void
    {
        $part = MasterPart::create(['part_no' => 'MAP-01', 'part_name' => 'Mapped Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-MAP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownDies('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $top = $result['ranking'][0];
        $this->assertSame('Mapped Part', $top['part'], 'part harus dari part_name, bukan bocor jadi nama_mesin.');
        $this->assertSame('MAP-01', $top['part_no'], 'part_no harus dari part_no.');
        $this->assertSame('BLANKING', $top['nama_proses']);
        $this->assertArrayNotHasKey('nama_mesin', $top, 'Key mesin tidak boleh bocor ke output ranking dies.');
        $this->assertArrayNotHasKey('kode_mesin', $top, 'Key mesin tidak boleh bocor ke output ranking dies.');
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

    public function test_get_ranking_breakdown_dies_with_no_period_returns_all_time_data(): void
    {
        $part = MasterPart::create(['part_no' => 'RDN-01', 'part_name' => 'RankNoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-RNP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2020-01-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2020-01-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2020-01-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getRankingBreakdownDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(1, $result['ranking'][0]['jumlah_breakdown']);
    }

    public function test_get_ranking_breakdown_dies_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getRankingBreakdownDies(null, '2026-07');
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_rekap_dandori_groups_by_jenis_losstime_sorted_by_over_menit(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'DD-01', 'nama_mesin' => 'Press D1', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DD-P1', 'part_name' => 'Dandori Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $lossSetting = MasterLosstime::create([
            'kode' => 'LS-SET', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);
        $lossTrial = MasterLosstime::create([
            'kode' => 'LS-TRI', 'jenis_losstime' => 'Trial QC', 'std_menit_default' => 15, 'std_menit_progressive' => 15,
        ]);
        $user = User::factory()->create();

        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $lossSetting->id,
            'actual_menit' => 50, 'std_menit' => 30, 'over_menit' => 20, 'created_by' => $user->id,
        ]);
        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-06', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $lossTrial->id,
            'actual_menit' => 20, 'std_menit' => 15, 'over_menit' => 5, 'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getRekapDandori('2026-07', '2026-07');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(2, $result['total_kejadian']);
        $this->assertSame(25, $result['total_over_menit']);
        $this->assertSame('Setting Dies', $result['top_jenis_losstime'][0]['jenis_losstime']);
        $this->assertSame(20, $result['top_jenis_losstime'][0]['total_over_menit']);
        $this->assertSame('Trial QC', $result['top_jenis_losstime'][1]['jenis_losstime']);
    }

    public function test_get_rekap_dandori_filters_by_mesin(): void
    {
        $mesin1 = MasterMesin::create([
            'id_mesin' => 'DD-02', 'nama_mesin' => 'Press D2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesin2 = MasterMesin::create([
            'id_mesin' => 'DD-03', 'nama_mesin' => 'Press D3', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DD-P2', 'part_name' => 'Dandori Part 2', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-SET2', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);
        $user = User::factory()->create();

        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin1->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 50, 'std_menit' => 30, 'over_menit' => 20, 'created_by' => $user->id,
        ]);
        TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-06', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin2->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 60, 'std_menit' => 30, 'over_menit' => 30, 'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getRekapDandori('2026-07', '2026-07', 'DD-02');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_kejadian']);
        $this->assertSame(20, $result['total_over_menit']);
    }

    public function test_get_rekap_dandori_returns_error_for_unknown_mesin(): void
    {
        $result = (new ChatbotTools())->getRekapDandori('2026-07', '2026-07', 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_rekap_dandori_returns_empty_result_with_catatan_when_no_records(): void
    {
        $result = (new ChatbotTools())->getRekapDandori('2020-01', '2020-01');
        $this->assertArrayHasKey('catatan', $result);
        $this->assertSame(0, $result['total_kejadian']);
        $this->assertSame([], $result['top_jenis_losstime']);
    }

    public function test_get_rekap_dandori_with_no_period_returns_all_time_data(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'DDN-01', 'nama_mesin' => 'Press DandoriNoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DDN-P1', 'part_name' => 'Dandori NoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-DDN', 'jenis_losstime' => 'Setting Dies', 'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);
        $user = User::factory()->create();
        TransaksiDandoriRincian::create([
            'tanggal' => '2020-01-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 50, 'std_menit' => 30, 'over_menit' => 20, 'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getRekapDandori();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(1, $result['total_kejadian']);
    }

    public function test_get_rekap_dandori_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getRekapDandori('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_total_produksi_sums_across_month_range(): void
    {
        $part = MasterPart::create(['part_no' => 'TP-01', 'part_name' => 'Total Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'TP-M1', 'nama_mesin' => 'Press TP', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $user = User::factory()->create();

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-05-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 3000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-06-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getTotalProduksi('2026-05', '2026-06');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(7000, $result['total_qty_aktual']);
        // sph_aktual accessor: 3000/8h=375, 4000/8h=500 → avg 437.5 → round 438.
        // Kunci behavior avg agar refactor kolom-narrowing tidak menggeser hasil.
        $this->assertSame(438, $result['avg_sph_aktual']);
    }

    public function test_get_total_produksi_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getTotalProduksi('2020-01', '2020-02');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_produksi_returns_error_for_invalid_month_format(): void
    {
        $result = (new ChatbotTools())->getTotalProduksi('bulan-salah', '2026-07');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_total_produksi_with_no_period_returns_all_time_data(): void
    {
        $part = MasterPart::create(['part_no' => 'TPN-01', 'part_name' => 'TotalNoPeriod Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Test', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'TPN-M1', 'nama_mesin' => 'Press TotalNoPeriod', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $user = User::factory()->create();
        TransaksiProduksi::create([
            'tanggal_produksi' => '2020-05-15', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 2000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);

        $result = (new ChatbotTools())->getTotalProduksi();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertSame(2000, $result['total_qty_aktual']);
    }

    public function test_get_total_produksi_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getTotalProduksi('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_top_problem_dies_groups_by_normalized_problem_text(): void
    {
        $part = MasterPart::create(['part_no' => 'TOP-D1', 'part_name' => 'Top Problem Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOP1', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Motor overheat', 'penyebab_breakdown' => 'Bearing aus lama', 'tindakan_perbaikan' => 'Ganti bearing lama',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOP2', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => '  motor overheat  ', 'penyebab_breakdown' => 'Bearing aus baru', 'tindakan_perbaikan' => 'Ganti bearing baru',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOP3', 'id_part_proses' => $proses->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Baut kendor', 'penyebab_breakdown' => 'Vibrasi tinggi', 'tindakan_perbaikan' => 'Kencangkan baut',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(3, $result['total_kejadian']);
        $this->assertSame('semua periode', $result['bulan_mulai']);
        $this->assertCount(2, $result['top_problem']);
        $this->assertSame(2, $result['top_problem'][0]['jumlah_kejadian']);
        $this->assertSame('motor overheat', $result['top_problem'][0]['problem']);
        $this->assertSame('Bearing aus baru', $result['top_problem'][0]['contoh_penyebab']);
        $this->assertEqualsWithDelta(66.67, $result['top_problem'][0]['pct_dari_total'], 0.01);
        $this->assertSame(1, $result['top_problem'][1]['jumlah_kejadian']);
        $this->assertSame('Baut kendor', $result['top_problem'][1]['problem']);
    }

    public function test_get_top_problem_dies_filters_by_part(): void
    {
        $part1 = MasterPart::create(['part_no' => 'TOP-D2', 'part_name' => 'Filter Part A', 'customer' => 'Toyota']);
        $proses1 = MasterPartProses::create([
            'id_part' => $part1->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $part2 = MasterPart::create(['part_no' => 'TOP-D3', 'part_name' => 'Filter Part B', 'customer' => 'Honda']);
        $proses2 = MasterPartProses::create([
            'id_part' => $part2->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOPF1', 'id_part_proses' => $proses1->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Problem A', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownDies::create([
            'no_lkd' => 'LKD-TOPF2', 'id_part_proses' => $proses2->id,
            'tanggal_breakdown' => '2026-07-02', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-02', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-02', 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'Problem B', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemDies(null, null, 'TOP-D2');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_kejadian']);
        $this->assertSame('Problem A', $result['top_problem'][0]['problem']);
    }

    public function test_get_top_problem_dies_returns_error_for_unknown_part(): void
    {
        $result = (new ChatbotTools())->getTopProblemDies(null, null, 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_dies_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getTopProblemDies();
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_dies_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getTopProblemDies('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_get_top_problem_dies_caps_at_ten_groups(): void
    {
        $part = MasterPart::create(['part_no' => 'TOP-D4', 'part_name' => 'Cap Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        for ($i = 1; $i <= 12; $i++) {
            TransaksiBreakdownDies::create([
                'no_lkd' => "LKD-CAP{$i}", 'id_part_proses' => $proses->id,
                'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
                'problem_dies' => "Problem distinct {$i}", 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
                'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
                'repair_time' => 1.0, 'total_downtime' => 1.0,
            ]);
        }

        $result = (new ChatbotTools())->getTopProblemDies();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertCount(10, $result['top_problem']);
    }

    public function test_get_top_problem_mesin_groups_by_normalized_problem_text(): void
    {
        $mesin = MasterMesin::create([
            'id_mesin' => 'TOP-M1', 'nama_mesin' => 'Top Problem Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOP1', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Panel error', 'penyebab_breakdown' => 'Korsleting lama', 'tindakan_perbaikan' => 'Ganti panel lama',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOP2', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-10', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-10', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-10', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => '  panel error  ', 'penyebab_breakdown' => 'Korsleting baru', 'tindakan_perbaikan' => 'Ganti panel baru',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOP3', 'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-07-05', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-05', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-05', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Motor macet', 'penyebab_breakdown' => 'Oli habis', 'tindakan_perbaikan' => 'Isi oli',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemMesin();

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(3, $result['total_kejadian']);
        $this->assertCount(2, $result['top_problem']);
        $this->assertSame(2, $result['top_problem'][0]['jumlah_kejadian']);
        $this->assertSame('panel error', $result['top_problem'][0]['problem']);
        $this->assertSame('Korsleting baru', $result['top_problem'][0]['contoh_penyebab']);
    }

    public function test_get_top_problem_mesin_filters_by_mesin(): void
    {
        $mesin1 = MasterMesin::create([
            'id_mesin' => 'TOP-M2', 'nama_mesin' => 'Filter Mesin A', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $mesin2 = MasterMesin::create([
            'id_mesin' => 'TOP-M3', 'nama_mesin' => 'Filter Mesin B', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOPF1', 'id_mesin' => $mesin1->id,
            'tanggal_breakdown' => '2026-07-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Problem A', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);
        TransaksiBreakdownMesin::create([
            'no_lkm' => 'LKM-TOPF2', 'id_mesin' => $mesin2->id,
            'tanggal_breakdown' => '2026-07-02', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-02', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-02', 'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'Problem B', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Selesai', 'pic_maintenance' => 'Budi',
            'repair_time' => 1.0, 'total_downtime' => 1.0,
        ]);

        $result = (new ChatbotTools())->getTopProblemMesin(null, null, 'TOP-M2');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame(1, $result['total_kejadian']);
        $this->assertSame('Problem A', $result['top_problem'][0]['problem']);
    }

    public function test_get_top_problem_mesin_returns_error_for_unknown_mesin(): void
    {
        $result = (new ChatbotTools())->getTopProblemMesin(null, null, 'NOT-EXIST');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_mesin_returns_error_when_no_records(): void
    {
        $result = (new ChatbotTools())->getTopProblemMesin();
        $this->assertArrayHasKey('error', $result);
    }

    public function test_get_top_problem_mesin_with_only_one_period_arg_returns_error(): void
    {
        $result = (new ChatbotTools())->getTopProblemMesin('2026-07', null);
        $this->assertArrayHasKey('error', $result);
        $this->assertSame('Kalau isi salah satu periode, isi juga yang satunya.', $result['error']);
    }

    public function test_schema_definitions_still_returns_all_thirteen_tools_with_unique_names(): void
    {
        $names = collect((new ChatbotTools())->schemaDefinitions())->pluck('name');

        $this->assertCount(15, $names);
        $this->assertCount(15, $names->unique(), 'Schema split must not introduce a duplicate tool name across the grouped methods.');
        $this->assertTrue($names->contains('getMtbfMttrDies'));
        $this->assertTrue($names->contains('getRekapDandori'));
        $this->assertTrue($names->contains('describeSchema'));
    }

    /**
     * Kontrak dispatch: GroqChatbotService/GeminiChatbotService memanggil
     * $this->tools->{$name}(...$args) di mana $name berasal dari schema.
     * Kalau sebuah method di-rename tanpa update schema (atau sebaliknya),
     * tool putus DIAM saat runtime. Test ini mengunci pemetaan schema↔method.
     */
    public function test_every_schema_name_maps_to_a_public_invokable_method(): void
    {
        $tools = new ChatbotTools();

        foreach ($tools->schemaDefinitions() as $schema) {
            $name = $schema['name'] ?? null;
            $this->assertNotNull($name, 'Setiap schema wajib punya key "name".');

            $this->assertTrue(
                method_exists($tools, $name),
                "Schema \"{$name}\" tidak punya method yang cocok di ChatbotTools — kemungkinan method di-rename tanpa update schema."
            );

            // Dispatch dilakukan dari class LAIN, jadi method WAJIB public.
            // method_exists() true juga untuk method private, maka cek visibilitas eksplisit.
            $reflection = new \ReflectionMethod($tools, $name);
            $this->assertTrue(
                $reflection->isPublic(),
                "Method \"{$name}\" harus public agar bisa di-dispatch oleh provider chatbot."
            );
            $this->assertFalse(
                $reflection->isStatic(),
                "Method \"{$name}\" tidak boleh static — dispatch memakai instance (\$this->tools->{\$name})."
            );
        }
    }

    /**
     * Setiap parameter WAJIB di schema harus benar-benar ada di daftar properties,
     * dan cocok dengan parameter method (nama + wajib/opsional). Ini mencegah
     * schema mendeklarasikan argumen yang tidak diterima method — yang bikin
     * dispatch named-args (...$args) melempar Error dan ditelan try/catch.
     */
    public function test_every_schema_matches_its_method_signature(): void
    {
        $tools = new ChatbotTools();

        foreach ($tools->schemaDefinitions() as $schema) {
            $name       = $schema['name'];
            $properties = $schema['parameters']['properties'] ?? [];
            $required   = $schema['parameters']['required'] ?? [];

            $reflection    = new \ReflectionMethod($tools, $name);
            $methodParams  = collect($reflection->getParameters());
            $paramNames    = $methodParams->map(fn ($p) => $p->getName())->all();
            $requiredNames = $methodParams
                ->reject(fn ($p) => $p->isOptional())
                ->map(fn ($p) => $p->getName())
                ->all();

            // Semua property schema harus jadi parameter method yang sah.
            foreach (array_keys($properties) as $prop) {
                $this->assertContains(
                    $prop,
                    $paramNames,
                    "Schema \"{$name}\" mendeklarasikan properti \"{$prop}\" yang bukan parameter method."
                );
            }

            // Parameter wajib method harus ditandai required di schema, kalau tidak
            // LLM boleh memanggil tanpa argumen wajib → dispatch Error → gagal diam.
            foreach ($requiredNames as $req) {
                $this->assertContains(
                    $req,
                    $required,
                    "Parameter wajib \"{$req}\" pada method \"{$name}\" tidak ditandai required di schema."
                );
            }
        }
    }

    public function test_describe_schema_without_table_lists_business_tables(): void
    {
        $result = (new ChatbotTools())->describeSchema();

        $this->assertArrayHasKey('tables', $result);
        $names = array_column($result['tables'], 'table');
        $this->assertContains('master_karyawan', $names);
        $this->assertContains('transaksi_produksi', $names);
        $this->assertNotContains('users', $names);
        $this->assertNotContains('chat_messages', $names);
    }

    public function test_describe_schema_with_table_returns_real_columns(): void
    {
        $result = (new ChatbotTools())->describeSchema('master_mesin');

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('master_mesin', $result['table']);
        $cols = array_column($result['columns'], 'nama');
        $this->assertContains('id_mesin', $cols);
        $this->assertContains('nama_mesin', $cols);
    }

    public function test_describe_schema_rejects_non_whitelisted_table(): void
    {
        $result = (new ChatbotTools())->describeSchema('users');
        $this->assertArrayHasKey('error', $result);
    }

    public function test_describe_schema_returns_error_when_introspection_fails(): void
    {
        Schema::shouldReceive('getColumnListing')->andThrow(new \RuntimeException('boom'));

        $result = (new ChatbotTools())->describeSchema('master_mesin');

        $this->assertArrayHasKey('error', $result);
    }
}
