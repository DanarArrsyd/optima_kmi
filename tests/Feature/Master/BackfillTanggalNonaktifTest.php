<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill dijalankan langsung, bukan lewat RefreshDatabase: saat suite
 * memigrasi tabel masih kosong, jadi migrasinya harus dipanggil ulang setelah
 * data uji ada.
 */
class BackfillTanggalNonaktifTest extends TestCase
{
    use RefreshDatabase;

    private function jalankanBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php');
        $migration->up();
    }

    /**
     * transaksi_produksi & transaksi_dandori_rincian punya kolom NOT NULL lain
     * (id_shift, id_mesin, id_part_proses, created_by, dst) di luar yang
     * disebut brief — baris master ini sekadar melengkapi FK-nya supaya
     * insert tidak ditolak skema, bukan bagian dari yang diuji.
     */
    private function buatMasterPendukung(): array
    {
        $userId = DB::table('users')->insertGetId([
            'name' => 'Tester', 'email' => 'tester@example.com', 'password' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $shiftId = DB::table('master_shift')->insertGetId([
            'nama_shift' => 'S1', 'jam_start' => '08:00:00', 'jam_finish' => '16:00:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $mesinId = DB::table('master_mesin')->insertGetId([
            'id_mesin' => 'M-BF', 'nama_mesin' => 'Press BF', 'line' => '1',
            'tipe_mesin' => 'STAMPING', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $partId = DB::table('master_part')->insertGetId([
            'part_no' => 'PT-BF', 'part_name' => 'Part BF',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $partProsesId = DB::table('master_part_proses')->insertGetId([
            'id_part' => $partId, 'id_proses' => 'P1', 'nama_proses' => 'BLANKING',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $losstimeId = DB::table('master_losstime')->insertGetId([
            'kode' => 'SD-BF', 'jenis_losstime' => 'Setting_Dies',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('userId', 'shiftId', 'mesinId', 'partProsesId', 'losstimeId');
    }

    public function test_mengambil_tanggal_transaksi_terbaru_dari_tiga_tabel(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N1', 'nama_karyawan' => 'ORANG SATU',
            'departemen' => 'Produksi', 'is_aktif' => false,
        ]);
        $m = $this->buatMasterPendukung();

        DB::table('transaksi_produksi')->insert([
            'tanggal_produksi' => '2026-05-01', 'id_karyawan' => $k->id,
            'id_shift' => $m['shiftId'], 'id_mesin' => $m['mesinId'], 'id_part_proses' => $m['partProsesId'],
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 10,
            'created_by' => $m['userId'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('transaksi_dandori_rincian')->insert([
            'tanggal' => '2026-06-20', 'id_karyawan' => $k->id,
            'id_shift' => $m['shiftId'], 'id_mesin' => $m['mesinId'], 'id_part_proses' => $m['partProsesId'],
            'id_losstime' => $m['losstimeId'], 'actual_menit' => 10, 'std_menit' => 0,
            'created_by' => $m['userId'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->jalankanBackfill();

        $this->assertSame('2026-06-20', $k->fresh()->tanggal_nonaktif->toDateString());
    }

    public function test_karyawan_aktif_tidak_disentuh(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N2', 'nama_karyawan' => 'ORANG DUA',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $m = $this->buatMasterPendukung();

        DB::table('transaksi_produksi')->insert([
            'tanggal_produksi' => '2026-05-01', 'id_karyawan' => $k->id,
            'id_shift' => $m['shiftId'], 'id_mesin' => $m['mesinId'], 'id_part_proses' => $m['partProsesId'],
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 10,
            'created_by' => $m['userId'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->jalankanBackfill();

        $this->assertNull($k->fresh()->tanggal_nonaktif);
    }

    public function test_nonaktif_tanpa_transaksi_tetap_null(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N3', 'nama_karyawan' => 'ORANG TIGA',
            'departemen' => 'Produksi', 'is_aktif' => false,
        ]);

        $this->jalankanBackfill();

        $this->assertNull($k->fresh()->tanggal_nonaktif);
    }

    public function test_tanggal_yang_sudah_terisi_tidak_ditimpa(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N4', 'nama_karyawan' => 'ORANG EMPAT',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-01-15',
        ]);
        $m = $this->buatMasterPendukung();

        DB::table('transaksi_produksi')->insert([
            'tanggal_produksi' => '2026-07-01', 'id_karyawan' => $k->id,
            'id_shift' => $m['shiftId'], 'id_mesin' => $m['mesinId'], 'id_part_proses' => $m['partProsesId'],
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 10,
            'created_by' => $m['userId'], 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->jalankanBackfill();

        $this->assertSame('2026-01-15', $k->fresh()->tanggal_nonaktif->toDateString());
    }
}
