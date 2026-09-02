<?php

namespace Tests\Feature;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleBacklogPdfFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_mesin_nol_render_baris_backlog(): void
    {
        Carbon::setTestNow('2026-08-20');
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        // Create 15 machines to exceed monthly capacity (August 2026 has ~4 working weeks, capacity 1 per week = 4 slots)
        for ($i = 1; $i <= 15; $i++) {
            $mesin = MasterMesin::create([
                'id_mesin' => "PDF{$i}", 'nama_mesin' => "Press {$i}", 'line' => '1',
                'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
                'tanggal_instalasi' => '2020-01-01',
            ]);
            $part = MasterPart::create(['part_no' => "P-PDF{$i}", 'part_name' => "Part {$i}", 'customer' => 'Toyota']);
            $proses = MasterPartProses::create([
                'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
                'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
            ]);
            // qty_aktual = 250,000 to get pct >= 80 (stroke 250,000 / threshold 300,000 = 83.3%)
            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-07-01', 'id_shift' => $shift->id,
                'id_karyawan' => $karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 250000,
                'actual_cycle_time' => 10, 'created_by' => $admin->id,
            ]);
        }

        app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $schedule = PreventiveSchedule::where('jenis', 'mesin')->where('bulan', '2026-08-01')->first();
        $this->assertTrue($schedule->items()->whereNull('tanggal_rencana')->exists(), 'prasyarat: harus ada baris backlog');

        $response = $this->actingAs($admin)->get(route('maintenance.control-stroke-mesin.schedule-pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
