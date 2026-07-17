<?php
namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kolom persist durasi_menit + accessor waktu_kerja_jam: satu sumber logika
 * (hitungDurasiMenit), diisi otomatis tiap simpan, tetap sinkron saat jam diedit.
 */
class TransaksiProduksiDurasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_hitung_durasi_menit_normal_shift(): void
    {
        $this->assertSame(480, TransaksiProduksi::hitungDurasiMenit('08:00', '16:00'));
    }

    public function test_hitung_durasi_menit_overnight_shift(): void
    {
        // 22:00 → 06:00 = 8 jam (lewat tengah malam).
        $this->assertSame(480, TransaksiProduksi::hitungDurasiMenit('22:00', '06:00'));
    }

    public function test_hitung_durasi_menit_invalid_returns_zero(): void
    {
        $this->assertSame(0, TransaksiProduksi::hitungDurasiMenit('bukan-jam', null));
    }

    private function lhp(string $start, string $finish): TransaksiProduksi
    {
        $part   = MasterPart::create(['part_no' => 'P-D', 'part_name' => 'X', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 400, 'sph_standar_qc' => 200]);
        $shift  = MasterShift::create(['nama_shift' => 'S', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $kar    = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin  = MasterMesin::create(['id_mesin' => 'M-D', 'nama_mesin' => 'Press', 'line' => '1', 'tipe_mesin' => 'BLANK', 'kapasitas_max_stroke' => 100]);
        $user   = User::factory()->create();

        return TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-15', 'id_shift' => $shift->id, 'id_karyawan' => $kar->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => $start, 'jam_finish' => $finish, 'qty_aktual' => 1000, 'qty_ng' => 0,
            'created_by' => $user->id,
        ]);
    }

    public function test_saving_hook_fills_durasi_menit(): void
    {
        $lhp = $this->lhp('08:00', '16:00');
        $this->assertSame(480, $lhp->fresh()->durasi_menit);
    }

    public function test_editing_jam_recomputes_durasi_menit(): void
    {
        $lhp = $this->lhp('08:00', '16:00');
        $lhp->update(['jam_finish' => '12:00']); // 08:00 → 12:00 = 4 jam
        $this->assertSame(240, $lhp->fresh()->durasi_menit);
    }

    public function test_accessor_reads_stored_durasi_menit(): void
    {
        $lhp = $this->lhp('08:00', '16:00');
        $this->assertSame(8.0, $lhp->fresh()->waktu_kerja_jam);
    }
}
