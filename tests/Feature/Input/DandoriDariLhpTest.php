<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use App\Services\DandoriRincianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dandori dicatat dari form LHP Produksi. Yang digabungkan INPUT-nya, bukan
 * tabelnya: baris rincian tetap ditulis dengan bentuk yang sama persis, jadi
 * tidak satu pun perhitungan (Availability, OEE, Achievement, Loss Time)
 * membaca sesuatu yang berbeda.
 */
class DandoriDariLhpTest extends TestCase
{
    use RefreshDatabase;

    private $shift;

    private $karyawan;

    private $karyawanLain;

    private $mesin;

    private $proses;

    private $prosesProgressive;

    private $losstime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = MasterShift::create([
            'nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00',
        ]);
        $this->karyawan = MasterKaryawan::create([
            'nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->karyawanLain = MasterKaryawan::create([
            'nik' => 'K2', 'nama_karyawan' => 'OPERATOR DUA', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A',
            'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500,
        ]);
        $this->prosesProgressive = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500,
        ]);
        $this->losstime = MasterLosstime::create([
            'kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES',
            'std_menit_default' => 30, 'std_menit_progressive' => 45,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function sesi(?int $idProses = null): array
    {
        return [
            'tanggal' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $idProses ?? $this->proses->id,
        ];
    }

    public function test_menulis_satu_baris_per_rincian(): void
    {
        $jumlah = app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $this->assertSame(1, $jumlah);
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    public function test_std_menit_diambil_dari_master_dan_over_dihitung(): void
    {
        app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertEquals(30, $baris->std_menit);
        $this->assertEquals(20, $baris->over_menit);
    }

    public function test_proses_progressive_memakai_std_progressive(): void
    {
        // Cabang PROGRESSIVE sudah ada di DandoriController; ia harus ikut
        // pindah utuh, bukan disederhanakan.
        app(DandoriRincianService::class)->simpan($this->sesi($this->prosesProgressive->id), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertEquals(45, $baris->std_menit);
        $this->assertEquals(5, $baris->over_menit);
    }

    public function test_over_menit_tidak_pernah_negatif(): void
    {
        app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 10],
        ]);

        $this->assertEquals(0, TransaksiDandoriRincian::first()->over_menit);
    }

    public function test_rincian_kosong_tidak_menulis_apa_pun(): void
    {
        $jumlah = app(DandoriRincianService::class)->simpan($this->sesi(), []);

        $this->assertSame(0, $jumlah);
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }
}
