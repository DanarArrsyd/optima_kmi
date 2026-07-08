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
}
