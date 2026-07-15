<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MesinPreventiveLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MesinPreventiveLogTest extends TestCase
{
    use RefreshDatabase;

    private function fixtures(): array
    {
        $user     = User::factory()->create(['role' => 'admin']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Teknisi A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin    = MasterMesin::create([
            'id_mesin' => 'MPL-01', 'nama_mesin' => 'MPL Mesin', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);

        return compact('user', 'karyawan', 'mesin');
    }

    public function test_can_create_log_with_all_fields_and_relations_resolve(): void
    {
        $f = $this->fixtures();

        $log = MesinPreventiveLog::create([
            'id_mesin'             => $f['mesin']->id,
            'tanggal_preventive'   => '2026-07-01',
            'id_karyawan'          => $f['karyawan']->id,
            'stroke_at_preventive' => 301500,
            'catatan'              => 'Ganti bearing',
            'created_by'           => $f['user']->id,
        ]);

        $this->assertSame('2026-07-01', $log->fresh()->tanggal_preventive->format('Y-m-d'));
        $this->assertSame(301500, $log->fresh()->stroke_at_preventive);
        $this->assertSame($f['mesin']->id, $log->mesin->id);
        $this->assertSame('Teknisi A', $log->karyawan->nama_karyawan);
        $this->assertSame($f['user']->id, $log->createdBy->id);
    }

    public function test_master_mesin_preventive_logs_relation_orders_newest_first(): void
    {
        $f = $this->fixtures();

        MesinPreventiveLog::create([
            'id_mesin' => $f['mesin']->id, 'tanggal_preventive' => '2026-05-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 300100, 'created_by' => $f['user']->id,
        ]);
        MesinPreventiveLog::create([
            'id_mesin' => $f['mesin']->id, 'tanggal_preventive' => '2026-07-01',
            'id_karyawan' => $f['karyawan']->id, 'stroke_at_preventive' => 300200, 'created_by' => $f['user']->id,
        ]);

        $logs = $f['mesin']->fresh()->preventiveLogs;

        $this->assertCount(2, $logs);
        $this->assertSame('2026-07-01', $logs->first()->tanggal_preventive->format('Y-m-d'));
    }
}
