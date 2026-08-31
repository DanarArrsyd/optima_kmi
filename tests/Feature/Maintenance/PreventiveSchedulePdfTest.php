<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\MaintenanceDashboardService;
use App\Services\MaintenanceDiesDashboardService;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveSchedulePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_belum_ada_jadwal_tampil_pesan_bukan_500(): void
    {
        Carbon::setTestNow('2026-08-15');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin.schedule-pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Carbon::setTestNow();
    }

    public function test_view_belum_ada_jadwal_menampilkan_pesan(): void
    {
        $bulan = Carbon::parse('2026-08-01');

        $html = view('maintenance.preventive-schedule-pdf', [
            'jenis' => 'mesin',
            'schedule' => null,
            'bulan' => $bulan,
            'svc' => app(MaintenanceDashboardService::class),
        ])->render();

        $this->assertStringContainsString('Belum ada jadwal', $html);
    }

    public function test_generate_manual_admin_boleh_lalu_pdf_tampil(): void
    {
        Carbon::setTestNow('2026-08-15');
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'Press A1', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => 'P-A1', 'part_name' => 'Part A1', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 310000, 'actual_cycle_time' => 10, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('maintenance.control-stroke-mesin.schedule-generate'))
            ->assertRedirect();

        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'mesin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin.schedule-pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Carbon::setTestNow();
    }

    public function test_dies_pdf_stream_setelah_generate(): void
    {
        Carbon::setTestNow('2026-08-15');
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'M-D1', 'nama_mesin' => 'Press M-D1', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => 'D-1', 'part_name' => 'Dies 1', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 31000, 'actual_cycle_time' => 10, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('maintenance.control-stroke-dies.schedule-generate'))
            ->assertRedirect();

        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'dies']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-dies.schedule-pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Carbon::setTestNow();
    }

    public function test_dies_view_render_langsung_menampilkan_label_part_dies(): void
    {
        $bulan = Carbon::parse('2026-08-01');
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'M-D2', 'nama_mesin' => 'Press M-D2', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => 'D-2', 'part_name' => 'Dies 2', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 31000, 'actual_cycle_time' => 10, 'created_by' => $admin->id,
        ]);

        $schedule = app(PreventiveScheduleService::class)->generate('dies', $bulan);

        $html = view('maintenance.preventive-schedule-pdf', [
            'jenis' => 'dies',
            'schedule' => $schedule->load('items.partProses.part', 'items.partProses.preventiveLogs'),
            'bulan' => $bulan,
            'svc' => app(MaintenanceDiesDashboardService::class),
        ])->render();

        $this->assertStringContainsString('NAMA PART/DIES', $html);
        $this->assertStringContainsString('ID DIES', $html);
        $this->assertStringContainsString('plan-cell', $html);
    }

    /**
     * Satu part_no dies bisa punya beberapa baris (proses beda) dengan Nama/ID Dies
     * yang SAMA PERSIS — tanpa kolom PROSES, PIC MTC Dies nebak sendiri proses mana
     * yang dimaksud tiap baris.
     */
    public function test_dies_pdf_menampilkan_kolom_proses_untuk_bedakan_baris_part_sama(): void
    {
        $bulan = Carbon::parse('2026-08-01');
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'M-D3', 'nama_mesin' => 'Press M-D3', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => 'D-3', 'part_name' => 'Dies 3', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'FORMING', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 31000, 'actual_cycle_time' => 10, 'created_by' => $admin->id,
        ]);

        $schedule = app(PreventiveScheduleService::class)->generate('dies', $bulan);

        $html = view('maintenance.preventive-schedule-pdf', [
            'jenis' => 'dies',
            'schedule' => $schedule->load('items.partProses.part', 'items.partProses.preventiveLogs'),
            'bulan' => $bulan,
            'svc' => app(MaintenanceDiesDashboardService::class),
        ])->render();

        $this->assertStringContainsString('>PROSES<', $html);
        $this->assertStringContainsString('2', $html);
        $this->assertStringContainsString('FORMING', $html);
    }

    /** Mesin nol butuh kolom PROSES (1 mesin = 1 baris, nol ambigu) — nol boleh nongol. */
    public function test_mesin_pdf_nol_kolom_proses(): void
    {
        $bulan = Carbon::parse('2026-08-01');
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'M-P1', 'nama_mesin' => 'Press M-P1', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => 'P-P1', 'part_name' => 'Part P1', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 310000, 'actual_cycle_time' => 10, 'created_by' => $admin->id,
        ]);

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', $bulan);

        $html = view('maintenance.preventive-schedule-pdf', [
            'jenis' => 'mesin',
            'schedule' => $schedule->load('items.mesin.preventiveLogs'),
            'bulan' => $bulan,
            'svc' => app(MaintenanceDashboardService::class),
        ])->render();

        $this->assertStringContainsString('plan-cell', $html, 'Setup salah: baris mesin harusnya kerender.');
        $this->assertStringNotContainsString('>PROSES<', $html);
    }

    public function test_staff_qc_tidak_boleh_generate_manual(): void
    {
        $staffQc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($staffQc)
            ->post(route('maintenance.control-stroke-mesin.schedule-generate'))
            ->assertForbidden();
    }

    public function test_guest_boleh_lihat_pdf_tapi_tidak_boleh_generate(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-mesin.schedule-pdf'))
            ->assertOk();

        $this->actingAs($guest)
            ->post(route('maintenance.control-stroke-mesin.schedule-generate'))
            ->assertForbidden();
    }
}
