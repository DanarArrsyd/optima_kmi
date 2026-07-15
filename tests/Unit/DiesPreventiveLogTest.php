<?php
namespace Tests\Unit;

use App\Models\DiesPreventiveLog;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiesPreventiveLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $part     = MasterPart::create(['part_no' => 'DPL-P1', 'part_name' => 'DPL Part', 'customer' => 'Toyota']);
        $proses   = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        return compact('user', 'karyawan', 'part', 'proses');
    }

    public function test_can_create_log_with_all_fields_and_relations_resolve(): void
    {
        $f = $this->fixtures();

        $log = DiesPreventiveLog::create([
            'id_part_proses'       => $f['proses']->id,
            'tanggal_preventive'   => '2026-07-01',
            'id_karyawan'          => $f['karyawan']->id,
            'stroke_at_preventive' => 30450,
            'catatan'              => 'Ganti pisau potong',
            'created_by'           => $f['user']->id,
        ]);

        $this->assertSame('2026-07-01', $log->fresh()->tanggal_preventive->format('Y-m-d'));
        $this->assertSame(30450, $log->fresh()->stroke_at_preventive);
        $this->assertSame($f['proses']->id, $log->partProses->id);
        $this->assertSame('Teknisi A', $log->karyawan->nama_karyawan);
        $this->assertSame($f['user']->id, $log->createdBy->id);
    }

    public function test_master_part_proses_preventive_logs_relation_orders_newest_first(): void
    {
        $f = $this->fixtures();

        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30100, 'created_by' => $f['user']->id,
        ]);
        DiesPreventiveLog::create([
            'id_part_proses' => $f['proses']->id, 'tanggal_preventive' => '2026-07-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 30200, 'created_by' => $f['user']->id,
        ]);

        $logs = $f['proses']->fresh()->preventiveLogs;

        $this->assertCount(2, $logs);
        $this->assertSame('2026-07-01', $logs->first()->tanggal_preventive->format('Y-m-d'));
    }
}
