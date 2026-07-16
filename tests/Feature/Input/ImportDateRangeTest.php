<?php
namespace Tests\Feature\Input;

use App\Imports\BreakdownMesinImport;
use App\Imports\ProduksiImport;
use App\Imports\QcImport;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Pintu kedua ke bug tanggal yang sama seperti di form (DateRangeGuardTest): tiap importer
 * punya salinan parseDate() sendiri — 6 salinan total — dan semuanya meloloskan tahun
 * 4-digit apa pun. Baris "0026-06-21" masuk diam-diam lalu hilang dari laporan VCB.
 *
 * Import lebih berbahaya daripada form: satu file bisa membawa ribuan baris sekaligus.
 *
 * Master di-seed lengkap supaya baris uji SEHARUSNYA sukses — dengan begitu satu-satunya
 * alasan baris ditolak adalah tanggalnya, bukan lookup master yang meleset.
 */
class ImportDateRangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator X', 'departemen' => 'Produksi', 'is_aktif' => true]);
        MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Inspector X', 'departemen' => 'QC', 'is_aktif' => true]);
        MasterMesin::create([
            'id_mesin' => 'X-01', 'nama_mesin' => 'Press X', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'X-P1', 'part_name' => 'Panel X', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    private function produksiRow(string $tanggal): array
    {
        return [
            'tanggal_produksi' => $tanggal,
            'nama_shift'       => 'Shift 1',
            'nama_operator'    => 'Operator X',
            'id_mesin'         => 'X-01',
            'part_no'          => 'X-P1',
            'id_proses'        => '1',
            'jam_start'        => '08:00',
            'jam_finish'       => '16:00',
            'qty_aktual'       => 100,
            'qty_ng'           => 0,
        ];
    }

    private function qcRow(string $tanggal): array
    {
        return [
            'tgl_pengecekan'    => $tanggal,
            'tgl_produksi_part' => '2026-06-20',
            'nama_shift'        => 'Shift 1',
            'nama_inspector'    => 'Inspector X',
            'part_no'           => 'X-P1',
            'id_proses'         => '1',
            'jam_start'         => '08:00',
            'jam_finish'        => '16:00',
            'qty_ok'            => 100,
            'qty_ng'            => 0,
        ];
    }

    /** Baris kontrol: tanggal wajar harus benar-benar masuk, membuktikan seed master cukup. */
    public function test_produksi_import_accepts_a_sane_date(): void
    {
        $import = new ProduksiImport();
        $import->collection(new Collection([$this->produksiRow('2026-06-21')]));

        $this->assertSame([], $import->errors);
        $this->assertDatabaseCount('transaksi_produksi', 1);
    }

    public function test_produksi_import_rejects_year_typo(): void
    {
        $import = new ProduksiImport();
        $import->collection(new Collection([$this->produksiRow('0026-06-21')]));

        $this->assertDatabaseCount('transaksi_produksi', 0);
        $this->assertNotEmpty($import->errors);
        $this->assertStringContainsString('tanggal', strtolower(implode(' ', $import->errors)));
    }

    public function test_qc_import_accepts_a_sane_date(): void
    {
        $import = new QcImport();
        $import->collection(new Collection([$this->qcRow('2026-06-21')]));

        $this->assertSame([], $import->errors);
        $this->assertDatabaseCount('transaksi_qc', 1);
    }

    public function test_qc_import_rejects_year_typo(): void
    {
        $import = new QcImport();
        $import->collection(new Collection([$this->qcRow('0026-06-21')]));

        $this->assertDatabaseCount('transaksi_qc', 0);
        $this->assertNotEmpty($import->errors);
    }

    private function breakdownRow(string $tanggal): array
    {
        return [
            'nomor_urut'                => 1,
            'id_mesin'                  => 'X-01',
            'tanggal_breakdown'         => $tanggal,
            'jam_breakdown'             => '08:00',
            'tanggal_mulai_perbaikan'   => $tanggal,
            'jam_mulai_perbaikan'       => '09:00',
            'tanggal_selesai_perbaikan' => $tanggal,
            'jam_selesai_perbaikan'     => '10:00',
            'problem_mesin'             => 'Bearing aus',
            'penyebab_breakdown'        => 'Umur pakai',
            'tindakan_perbaikan'        => 'Ganti bearing',
            'pic_maintenance'           => 'Teknisi X',
            'status_breakdown'          => 'Ringan',
        ];
    }

    public function test_breakdown_mesin_import_rejects_year_typo(): void
    {
        $import = app(BreakdownMesinImport::class);
        $import->collection(new Collection([$this->breakdownRow('0026-06-21')]));

        $this->assertDatabaseCount('transaksi_breakdown_mesin', 0);
    }

    public function test_breakdown_mesin_import_accepts_a_sane_date(): void
    {
        $import = app(BreakdownMesinImport::class);
        $import->collection(new Collection([$this->breakdownRow('2026-06-21')]));

        $this->assertDatabaseCount('transaksi_breakdown_mesin', 1);
    }
}
