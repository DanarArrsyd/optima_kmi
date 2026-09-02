<?php

namespace Tests\Feature\Maintenance;

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

class PreventiveScheduleManualEditTest extends TestCase
{
    use RefreshDatabase;

    private function setupMesinEligible(string $kode, int $stroke): MasterMesin
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Op', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => $kode, 'nama_mesin' => "Press {$kode}", 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => "P-{$kode}", 'part_name' => "Part {$kode}", 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => $stroke, 'actual_cycle_time' => 10, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        return $mesin;
    }

    public function test_admin_bisa_patch_tanggal_valid(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME1', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertOk();

        $item->refresh();
        $this->assertSame('2026-08-11', $item->tanggal_rencana->toDateString());
        $this->assertTrue($item->is_manual_override);
        $this->assertSame($admin->id, $item->overridden_by);
        $this->assertNotNull($item->overridden_at);
    }

    public function test_patch_tanggal_hari_libur_ditolak_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME2', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        // 2026-08-01 = Sabtu (bukan hari kerja).
        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-01'])
            ->assertStatus(422);
    }

    public function test_patch_tanggal_jumat_ditolak_buat_mesin_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME3', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        // 2026-08-07 = Jumat, mesin cuma Senin-Kamis.
        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-07'])
            ->assertStatus(422);
    }

    public function test_patch_bulan_lain_ditolak_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME4', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-09-03'])
            ->assertStatus(422);
    }

    public function test_patch_item_dies_lewat_route_mesin_ditolak_422(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create(['part_no' => 'PD1', 'part_name' => 'Part D1', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        $itemDies = PreventiveScheduleItem::create([
            'schedule_id' => PreventiveSchedule::create(['bulan' => '2026-08-01', 'jenis' => 'dies', 'nomor_dokumen' => 'X'])->id,
            'id_part_proses' => $proses->id, 'stroke_snapshot' => 1, 'pct_snapshot' => 100, 'threshold_snapshot' => 1,
            'tanggal_rencana' => '2026-08-04', 'minggu_ke' => 1,
        ]);

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $itemDies), ['tanggal_rencana' => '2026-08-05'])
            ->assertStatus(422);
    }

    public function test_patch_baris_backlog_keluar_dari_backlog(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $item = PreventiveScheduleItem::create([
            'schedule_id' => PreventiveSchedule::create(['bulan' => '2026-08-01', 'jenis' => 'mesin', 'nomor_dokumen' => 'X'])->id,
            'id_mesin' => $this->setupMesinEligible('ME5', 100)->id,
            'stroke_snapshot' => 1, 'pct_snapshot' => 100, 'threshold_snapshot' => 1,
            'tanggal_rencana' => null, 'minggu_ke' => null, 'backlog_since' => '2026-08-01',
        ]);

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertOk();

        $item->refresh();
        $this->assertSame('2026-08-11', $item->tanggal_rencana->toDateString());
        $this->assertNull($item->backlog_since, 'assign tanggal manual ke backlog harus keluarin dia dari backlog');
        $this->assertTrue($item->is_manual_override);
    }

    public function test_reset_balik_ke_backlog(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $item = PreventiveScheduleItem::create([
            'schedule_id' => PreventiveSchedule::create(['bulan' => '2026-08-01', 'jenis' => 'mesin', 'nomor_dokumen' => 'X'])->id,
            'id_mesin' => $this->setupMesinEligible('ME6', 100)->id,
            'stroke_snapshot' => 1, 'pct_snapshot' => 100, 'threshold_snapshot' => 1,
            'tanggal_rencana' => '2026-08-04', 'minggu_ke' => 1,
            'is_manual_override' => true, 'overridden_by' => $admin->id, 'overridden_at' => now(),
        ]);

        $this->actingAs($admin)
            ->postJson(route('maintenance.control-stroke-mesin.schedule-item.reset', $item))
            ->assertOk();

        $item->refresh();
        $this->assertFalse($item->is_manual_override);
        $this->assertNull($item->tanggal_rencana);
        $this->assertNull($item->minggu_ke);
        $this->assertNull($item->overridden_by);
        $this->assertSame('2026-08-20', $item->backlog_since->toDateString());
    }

    public function test_guest_tidak_boleh_patch_403(): void
    {
        Carbon::setTestNow('2026-08-20');
        $guest = User::factory()->create(['role' => 'guest']);
        $mesin = $this->setupMesinEligible('ME7', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($guest)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertStatus(403);
    }

    public function test_staff_produksi_tidak_boleh_patch_403(): void
    {
        Carbon::setTestNow('2026-08-20');
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $mesin = $this->setupMesinEligible('ME8', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($staff)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertStatus(403);
    }

    public function test_activitylog_kecatet_saat_patch(): void
    {
        Carbon::setTestNow('2026-08-20');
        $admin = User::factory()->create(['role' => 'admin']);
        $mesin = $this->setupMesinEligible('ME9', 305000);
        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $item = $schedule->items->first();

        $this->actingAs($admin)
            ->patchJson(route('maintenance.control-stroke-mesin.schedule-item.update', $item), ['tanggal_rencana' => '2026-08-11'])
            ->assertOk();

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => PreventiveScheduleItem::class,
            'subject_id' => $item->id,
            'causer_id' => $admin->id,
            'log_name' => 'preventive_schedule_item',
        ]);
    }
}
