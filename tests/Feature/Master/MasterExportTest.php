<?php

namespace Tests\Feature\Master;

use App\Imports\JenisNgImport;
use App\Imports\KaryawanImport;
use App\Imports\LosstimeImport;
use App\Imports\MesinImport;
use App\Imports\ShiftImport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ekspor halaman Master: isi file = isi tabel (filter aktif ikut), dan untuk
 * master berimpor, kolomnya SAMA dengan template impor — ekspor → sunting di
 * Excel → impor balik harus jalan tanpa galat.
 */
class MasterExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Admin Uji']));
    }

    /** [prefix rute, pembuat 2 baris (ALFA & BETA), query filter yang menyisakan ALFA, [kunci ALFA, kunci BETA], kelas import|null] */
    public static function master(): array
    {
        return [
            'mesin' => ['master.mesin', fn () => [
                MasterMesin::create(['id_mesin' => 'ALFA-1', 'nama_mesin' => 'PRESS ALFA', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 800000]),
                MasterMesin::create(['id_mesin' => 'BETA-1', 'nama_mesin' => 'PRESS BETA', 'line' => 'B', 'tipe_mesin' => 'TANDEM', 'kapasitas_max_stroke' => 900000]),
            ], ['filter_line' => 'A'], ['ALFA-1', 'BETA-1'], MesinImport::class],
            'karyawan' => ['master.karyawan', fn () => [
                MasterKaryawan::create(['nik' => 'N-ALFA', 'nama_karyawan' => 'ALFA SANTOSO', 'departemen' => 'Produksi', 'is_aktif' => true]),
                MasterKaryawan::create(['nik' => 'N-BETA', 'nama_karyawan' => 'BETA LESTARI', 'departemen' => 'QC', 'is_aktif' => true]),
            ], ['filter_dept' => 'Produksi'], ['N-ALFA', 'N-BETA'], KaryawanImport::class],
            'shift' => ['master.shift', fn () => [
                MasterShift::create(['nama_shift' => 'ALFA', 'jam_start' => '07:00', 'jam_finish' => '15:00']),
                MasterShift::create(['nama_shift' => 'BETA', 'jam_start' => '15:00', 'jam_finish' => '23:00']),
            ], ['q' => 'ALF'], ['ALFA', 'BETA'], ShiftImport::class],
            'loss time' => ['master.losstime', fn () => [
                MasterLosstime::create(['kode' => 'AL', 'jenis_losstime' => 'ALFA LOSS', 'std_menit_default' => 15, 'std_menit_progressive' => 10]),
                MasterLosstime::create(['kode' => 'BE', 'jenis_losstime' => 'BETA LOSS', 'std_menit_default' => 20, 'std_menit_progressive' => 12]),
            ], ['q' => 'ALFA'], ['AL', 'BE'], LosstimeImport::class],
            'jenis ng' => ['master.jenis-ng', fn () => [
                MasterJenisNg::create(['kode' => 'ALF', 'nama_ng' => 'ALFA NG', 'kategori' => 'Visual']),
                MasterJenisNg::create(['kode' => 'BET', 'nama_ng' => 'BETA NG', 'kategori' => 'Dimensi']),
            ], ['filter_kategori' => 'Visual'], ['ALF', 'BET'], JenisNgImport::class],
            'users' => ['master.users', fn () => [
                User::factory()->create(['name' => 'ALFA USER', 'email' => 'alfa@uji.test', 'role' => 'staff_qc']),
                User::factory()->create(['name' => 'BETA USER', 'email' => 'beta@uji.test', 'role' => 'manager']),
            ], ['filter_role' => 'staff_qc'], ['ALFA USER', 'BETA USER'], null],
            'proses repair' => ['master.proses-repair', function () {
                $p = MasterPart::create(['part_no' => 'PN-ALFA', 'part_name' => 'ALFA PART']);
                $q = MasterPart::create(['part_no' => 'PN-BETA', 'part_name' => 'BETA PART']);

                return [
                    MasterPartProsesRepair::create(['id_part' => $p->id, 'id_proses' => 1, 'nama_proses' => 'REPAIR ALFA', 'sph_standar_repair' => 100]),
                    MasterPartProsesRepair::create(['id_part' => $q->id, 'id_proses' => 1, 'nama_proses' => 'REPAIR BETA', 'sph_standar_repair' => 100]),
                ];
            }, ['q' => 'ALFA'], ['PN-ALFA', 'PN-BETA'], null],
            'proses repair produksi' => ['master.proses-repair-produksi', function () {
                $p = MasterPart::create(['part_no' => 'PN-ALFA', 'part_name' => 'ALFA PART']);
                $q = MasterPart::create(['part_no' => 'PN-BETA', 'part_name' => 'BETA PART']);

                return [
                    MasterPartProsesRepairProduksi::create(['id_part' => $p->id, 'id_proses' => 1, 'nama_proses' => 'RP ALFA', 'sph_standar_repair_produksi' => 100]),
                    MasterPartProsesRepairProduksi::create(['id_part' => $q->id, 'id_proses' => 1, 'nama_proses' => 'RP BETA', 'sph_standar_repair_produksi' => 100]),
                ];
            }, ['q' => 'ALFA'], ['PN-ALFA', 'PN-BETA'], null],
        ];
    }

    /** @return list<list<mixed>> */
    private function isi(TestResponse $res): array
    {
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));

        return IOFactory::load($res->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
    }

    private function semuaSel(array $isi): array
    {
        return array_map('strval', array_merge(...array_slice($isi, 1)));
    }

    #[DataProvider('master')]
    public function test_ekspor_menghormati_filter_aktif(string $prefix, \Closure $buat, array $filter, array $kunci, ?string $import): void
    {
        $buat();

        $semua = $this->isi($this->get(route("{$prefix}.export")));
        $saring = $this->isi($this->get(route("{$prefix}.export", $filter)));

        $this->assertGreaterThan(count($saring), count($semua), 'filter tidak memotong isi ekspor');
        [$ada, $tiada] = $kunci;
        $this->assertContains($ada, $this->semuaSel($saring));
        $this->assertNotContains($tiada, $this->semuaSel($saring));
        $this->assertContains($tiada, $this->semuaSel($semua));
    }

    #[DataProvider('master')]
    public function test_kolom_ekspor_sama_dengan_template_impor(string $prefix, \Closure $buat, array $filter, array $kunci, ?string $import): void
    {
        if ($import === null) {
            $this->assertTrue(true, 'master tanpa impor');

            return;
        }

        $ekspor = $this->isi($this->get(route("{$prefix}.export")))[0];
        $template = $this->isi($this->get(route("{$prefix}.import-template")))[0];

        $this->assertSame($template, $ekspor);
        $this->assertSame($import::KOLOM, $ekspor);
    }

    #[DataProvider('master')]
    public function test_ekspor_lalu_impor_balik_tanpa_galat_dan_tanpa_mengubah_data(string $prefix, \Closure $buat, array $filter, array $kunci, ?string $import): void
    {
        if ($import === null) {
            $this->assertTrue(true, 'master tanpa impor');

            return;
        }
        $buat();
        $sebelum = $this->sidik($prefix);

        $file = $this->get(route("{$prefix}.export"))->baseResponse->getFile()->getPathname();
        $this->post(route("{$prefix}.import"), ['file' => UploadedFile::fake()->createWithContent('e.xlsx', file_get_contents($file))]);

        $this->assertSame([], session('import_errors') ?? []);
        $this->assertSame($sebelum, $this->sidik($prefix));
    }

    /** Ringkasan isi tabel yang disentuh impor — harus identik sebelum & sesudah impor balik. */
    private function sidik(string $prefix): array
    {
        return match ($prefix) {
            'master.mesin' => MasterMesin::orderBy('id_mesin')->get(['id_mesin', 'nama_mesin', 'line', 'tipe_mesin', 'kapasitas_max_stroke'])->toArray(),
            'master.karyawan' => MasterKaryawan::orderBy('nik')->get(['nik', 'nama_karyawan', 'departemen'])->toArray(),
            'master.shift' => MasterShift::orderBy('nama_shift')->get()->map(fn ($s) => [$s->nama_shift, substr((string) $s->jam_start, 0, 5), substr((string) $s->jam_finish, 0, 5)])->all(),
            'master.losstime' => MasterLosstime::orderBy('kode')->get(['kode', 'jenis_losstime', 'std_menit_default', 'std_menit_progressive'])->toArray(),
            'master.jenis-ng' => MasterJenisNg::orderBy('kode')->get(['kode', 'nama_ng', 'deskripsi'])->toArray(),
        };
    }

    public function test_ekspor_users_tanpa_kolom_rahasia(): void
    {
        $isi = $this->isi($this->get(route('master.users.export')));
        $judul = array_map('strtolower', $isi[0]);

        foreach (['password', 'remember', 'two_factor', 'secret'] as $rahasia) {
            foreach ($judul as $j) {
                $this->assertStringNotContainsString($rahasia, $j);
            }
        }
    }
}
