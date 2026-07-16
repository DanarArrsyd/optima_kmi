<?php
namespace Tests\Feature\Input;

use App\Imports\DandoriImport;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Import adalah pintu kedua ke bug tanggal yang sama seperti di form
 * (lihat DandoriDateValidationTest): parseDate() menerima tahun 4-digit apa pun,
 * jadi "0026-06-21" lolos dan barisnya hilang dari laporan VCB.
 */
class DandoriImportDateTest extends TestCase
{
    use RefreshDatabase;

    private function seedMasters(): array
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator Impor', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'IM-01', 'nama_mesin' => 'Press IM', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'IM-P1', 'part_name' => 'Import Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        MasterLosstime::create([
            'kode' => '9', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);

        return compact('admin', 'shift', 'karyawan', 'mesin', 'part', 'proses');
    }

    private function row(string $tanggal): array
    {
        return [
            'tanggal'        => $tanggal,
            'nama_shift'     => 'Shift 1',
            'nama_operator'  => 'Operator Impor',
            'id_mesin'       => 'IM-01',
            'part_no'        => 'IM-P1',
            'id_proses'      => '1',
            'kode_loss_time' => '9',
            'actual_menit'   => 40,
        ];
    }

    public function test_import_rejects_year_typo_and_reports_it(): void
    {
        $ctx = $this->seedMasters();
        $this->actingAs($ctx['admin']);

        $import = new DandoriImport();
        $import->collection(new Collection([$this->row('0026-06-21')]));

        $this->assertSame(0, $import->importedCount);
        $this->assertDatabaseCount('transaksi_dandori_rincian', 0);
        $this->assertNotEmpty($import->errors);
        $this->assertStringContainsString('tahun', strtolower(implode(' ', $import->errors)));
    }

    public function test_import_still_accepts_a_valid_date(): void
    {
        $ctx = $this->seedMasters();
        $this->actingAs($ctx['admin']);

        $import = new DandoriImport();
        $import->collection(new Collection([$this->row('2026-06-21')]));

        $this->assertSame([], $import->errors);
        $this->assertSame(1, $import->importedCount);
        $this->assertSame(
            '2026-06-21',
            TransaksiDandoriRincian::sole()->tanggal->format('Y-m-d'),
        );
    }
}
