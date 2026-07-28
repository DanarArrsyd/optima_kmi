<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgLegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MasterShift $shift;

    private MasterKaryawan $karyawan;

    private MasterMesin $mesin;

    private MasterPartProses $proses;

    private MasterJenisNg $dented;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Legacy', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'LG-01', 'nama_mesin' => 'Press Legacy', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'LG-P1', 'part_name' => 'Legacy Part', 'customer' => 'Toyota']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
    }

    private function produksi(array $override): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
            'created_by' => $this->user->id,
            ...$override,
        ]);
    }

    private function qc(array $override): TransaksiQc
    {
        return TransaksiQc::create([
            'tgl_pengecekan' => '2026-07-20',
            'tgl_produksi_part' => '2026-07-19',
            'id_shift' => $this->shift->id,
            'id_karyawan_qc' => $this->karyawan->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_ok' => 90,
            'created_by' => $this->user->id,
            ...$override,
        ]);
    }

    /**
     * Menjalankan ulang logika migrasi data pada database test. Migrasi sudah
     * jalan saat RefreshDatabase (di atas tabel kosong), jadi test memanggil
     * up() lagi setelah data disiapkan.
     */
    private function runLegacyMigration(): void
    {
        $migration = require database_path('migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php');
        $migration->up();
    }

    public function test_baris_berjenis_dimigrasi_dan_baris_tanpa_jenis_dilewati(): void
    {
        $berjenis = $this->produksi(['qty_ng' => 5, 'id_jenis_ng' => $this->dented->id]);
        $tanpaJenis = $this->produksi(['qty_ng' => 3, 'id_jenis_ng' => null]);
        $tanpaNg = $this->produksi(['qty_ng' => 0, 'id_jenis_ng' => null]);

        TransaksiProduksiNg::query()->delete();

        $this->runLegacyMigration();

        $this->assertSame(1, TransaksiProduksiNg::where('id_transaksi_produksi', $berjenis->id)->count());
        $this->assertSame(5, (int) TransaksiProduksiNg::where('id_transaksi_produksi', $berjenis->id)->first()->qty);
        $this->assertSame(0, TransaksiProduksiNg::where('id_transaksi_produksi', $tanpaJenis->id)->count());
        $this->assertSame(0, TransaksiProduksiNg::where('id_transaksi_produksi', $tanpaNg->id)->count());
    }

    public function test_migrasi_tidak_menyentuh_qty_ng_induk(): void
    {
        $tanpaJenis = $this->produksi(['qty_ng' => 3, 'id_jenis_ng' => null]);
        TransaksiProduksiNg::query()->delete();

        $this->runLegacyMigration();

        $this->assertSame(3, (int) $tanpaJenis->fresh()->qty_ng);
    }

    public function test_sisi_qc_ikut_dimigrasi(): void
    {
        $berjenis = $this->qc(['qty_ng' => 4, 'id_jenis_ng' => $this->dented->id]);
        $tanpaJenis = $this->qc(['qty_ng' => 2, 'id_jenis_ng' => null]);

        TransaksiQcNg::query()->delete();

        $this->runLegacyMigration();

        $this->assertSame(1, TransaksiQcNg::where('id_transaksi_qc', $berjenis->id)->count());
        $this->assertSame(4, (int) TransaksiQcNg::where('id_transaksi_qc', $berjenis->id)->first()->qty);
        $this->assertSame(0, TransaksiQcNg::where('id_transaksi_qc', $tanpaJenis->id)->count());
    }

    public function test_induk_ter_soft_delete_ikut_dimigrasi(): void
    {
        $terhapus = $this->produksi(['qty_ng' => 7, 'id_jenis_ng' => $this->dented->id]);
        $terhapus->delete();

        TransaksiProduksiNg::query()->delete();

        $this->runLegacyMigration();

        $this->assertSame(1, TransaksiProduksiNg::where('id_transaksi_produksi', $terhapus->id)->count());
    }

    public function test_migrasi_idempoten_tidak_menggandakan_baris(): void
    {
        $berjenis = $this->produksi(['qty_ng' => 5, 'id_jenis_ng' => $this->dented->id]);
        TransaksiProduksiNg::query()->delete();

        $this->runLegacyMigration();
        $this->runLegacyMigration();

        $this->assertSame(1, TransaksiProduksiNg::where('id_transaksi_produksi', $berjenis->id)->count());
    }
}
