<?php

namespace Tests\Unit;

use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Services\MaintenanceDiesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Availability dies — basis jam kerja terjadwal, sama seperti Availability mesin.
 *
 * Kelas test ini BARU. Availability dies sebelumnya nol cakupan: penyebutnya
 * dipindah dari jam kalender ke jam shift dan seluruh suite tetap hijau, yang
 * artinya tidak ada satu test pun yang memeriksa angkanya. Perubahan tanpa test
 * yang gagal duluan itu perubahan yang tidak terbukti.
 */
class MaintenanceDiesDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hari_libur' => []]);
    }

    private function svc(): MaintenanceDiesDashboardService
    {
        return new MaintenanceDiesDashboardService;
    }

    private function tgl(string $s): Carbon
    {
        return Carbon::parse($s)->startOfDay();
    }

    /** Shift penentu jam terjadwal — penyebut Availability. */
    private function shiftKerja(): MasterShift
    {
        return MasterShift::firstOrCreate(
            ['nama_shift' => 'Shift 1'],
            ['jam_start' => '06:00', 'jam_finish' => '14:00']
        );
    }

    private function proses(?string $instalasi): MasterPartProses
    {
        $part = MasterPart::create([
            'part_no' => 'PT-DIES', 'part_name' => 'Bracket', 'customer' => 'Toyota',
            'tanggal_instalasi' => $instalasi,
        ]);

        return MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => 400, 'sph_standar_qc' => 200,
        ]);
    }

    private function breakdown(int $prosesId, string $tgl, float $repair, float $downtime): void
    {
        TransaksiBreakdownDies::create([
            'no_lkd' => uniqid(), 'id_part_proses' => $prosesId,
            'tanggal_breakdown' => $tgl, 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => $tgl, 'jam_mulai_perbaikan' => '08:00',
            'tanggal_selesai_perbaikan' => $tgl, 'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'Budi',
            'repair_time' => $repair, 'total_downtime' => $downtime,
        ]);
    }

    public function test_availability_dies_pakai_jam_terjadwal_bukan_kalender(): void
    {
        $this->shiftKerja();
        $pr = $this->proses('2026-01-01');
        // 2026-08-05 Rabu, satu hari kerja = 8 jam terjadwal.
        $this->breakdown($pr->id, '2026-08-05', repair: 2.0, downtime: 2.0);

        $rows = $this->svc()->compute($this->tgl('2026-08-05'), $this->tgl('2026-08-05'));

        // (8 − 2) / 8 = 75%. Basis kalender lama memberi (24 − 2)/24 = 91,67%.
        // Tanpa ?? — assertion dengan fallback lolos secara hampa kalau kuncinya
        // justru tidak ada, dan itu menyembunyikan bentuk baris yang berubah.
        $this->assertSame(8.0, $rows[0]['operating_hours']);
        $this->assertEqualsWithDelta(75.0, $rows[0]['availability_pct'], 0.01);
        $this->assertNotEqualsWithDelta(91.67, $rows[0]['availability_pct'], 0.5);
    }

    public function test_rentang_hanya_minggu_availability_null_bukan_seratus(): void
    {
        $this->shiftKerja();
        $pr = $this->proses('2026-01-01');
        $this->breakdown($pr->id, '2026-08-02', repair: 1.0, downtime: 1.0);

        // 2026-08-02 Minggu → nol jam terjadwal.
        $rows = $this->svc()->compute($this->tgl('2026-08-02'), $this->tgl('2026-08-02'));

        $this->assertNull($rows[0]['availability_pct'], '"Tidak ada jam kerja" bukan berarti "sempurna".');
    }

    public function test_hari_libur_config_juga_nol_jam(): void
    {
        config(['hari_libur' => ['2026-08-17']]);
        $this->shiftKerja();
        $pr = $this->proses('2026-01-01');
        $this->breakdown($pr->id, '2026-08-17', repair: 1.0, downtime: 1.0);

        $rows = $this->svc()->compute($this->tgl('2026-08-17'), $this->tgl('2026-08-17'));

        $this->assertNull($rows[0]['availability_pct']);
    }

    public function test_downtime_melebihi_jam_terjadwal_dijepit_dan_ditandai(): void
    {
        // Nyata, bukan teoretis: dies diperbaiki lewat dari jam kerja. Nol persen
        // tanpa penanda terbaca seperti angka biasa.
        $this->shiftKerja();
        $pr = $this->proses('2026-01-01');
        $this->breakdown($pr->id, '2026-08-05', repair: 12.0, downtime: 12.0);

        $rows = $this->svc()->compute($this->tgl('2026-08-05'), $this->tgl('2026-08-05'));

        $this->assertSame(0.0, $rows[0]['availability_pct']);
        $this->assertTrue($rows[0]['kerugian_melebihi_jam']);
    }

    public function test_shift_yang_dimatikan_tidak_masuk_penyebut_dies(): void
    {
        $this->shiftKerja();
        MasterShift::create([
            'nama_shift' => 'Shift 3', 'jam_start' => '22:00', 'jam_finish' => '06:00',
            'dihitung_availability' => false,
        ]);
        $pr = $this->proses('2026-01-01');
        $this->breakdown($pr->id, '2026-08-05', repair: 2.0, downtime: 2.0);

        $rows = $this->svc()->compute($this->tgl('2026-08-05'), $this->tgl('2026-08-05'));

        // Tetap 8 jam, bukan 16 — Shift 3 dimatikan. Kalau ia ikut terhitung,
        // availability-nya jadi 87,5% dan terlihat lebih baik dari kenyataan.
        $this->assertEqualsWithDelta(75.0, $rows[0]['availability_pct'], 0.01);
    }

    public function test_instalasi_belum_diset_availability_null(): void
    {
        $this->shiftKerja();
        $pr = $this->proses(null);
        $this->breakdown($pr->id, '2026-08-05', repair: 2.0, downtime: 2.0);

        $rows = $this->svc()->compute($this->tgl('2026-08-05'), $this->tgl('2026-08-05'));

        $this->assertFalse($rows[0]['instalasi_set']);
        $this->assertNull($rows[0]['availability_pct']);
        $this->assertSame(2.0, $rows[0]['mttr_jam'], 'MTTR tetap terhitung tanpa tanggal instalasi.');
    }
}
