<?php

namespace Tests\Feature;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\TransaksiRepair;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Template import di-upload balik apa adanya.
 *
 * Dua kelas bug yang dijaga di sini:
 *  - Import LHP Produksi & QC membaca baris KETERANGAN (baris 2) sebagai data,
 *    jadi tiap upload template pasti gagal "Baris 2: format tanggal".
 *  - Baris CONTOH ikut terimpor ke tabel asli (M001, Shift Malam, Toyota
 *    BT-091A-3, breakdown "Motor overheat").
 */
class ImportTemplateRoundTripTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public static function template(): array
    {
        return [
            'master mesin' => ['master.mesin', MasterMesin::class],
            'master karyawan' => ['master.karyawan', MasterKaryawan::class],
            'master shift' => ['master.shift', MasterShift::class],
            'master loss time' => ['master.losstime', MasterLosstime::class],
            'master jenis ng' => ['master.jenis-ng', MasterJenisNg::class],
            'master part' => ['master.part', MasterPart::class],
            'breakdown mesin' => ['maintenance.breakdown', TransaksiBreakdownMesin::class],
            'breakdown dies' => ['maintenance.breakdown-dies', TransaksiBreakdownDies::class],
            'lhp produksi' => ['input.produksi', TransaksiProduksi::class],
            'lhp quality' => ['input.qc', TransaksiQc::class],
            'dandori' => ['input.dandori', TransaksiDandoriRincian::class],
            'lhr repair' => ['input.repair', TransaksiRepair::class],
            'lhr repair produksi' => ['input.repair-produksi', TransaksiRepairProduksi::class],
        ];
    }

    private function unduhTemplate(string $prefix): string
    {
        $res = $this->get(route("{$prefix}.import-template"));
        $res->assertOk();

        return $res->baseResponse->getFile()->getPathname();
    }

    #[DataProvider('template')]
    public function test_template_utuh_tidak_mengimpor_apa_pun_dan_tanpa_galat(string $prefix, string $model): void
    {
        $file = UploadedFile::fake()->createWithContent('t.xlsx', file_get_contents($this->unduhTemplate($prefix)));

        $this->post(route("{$prefix}.import"), ['file' => $file])->assertRedirect();

        $this->assertSame([], session('import_errors') ?? [], 'baris keterangan/contoh dibaca sebagai data');
        $this->assertSame(0, $model::withTrashed()->count(), 'baris contoh template ikut terimpor');
    }

    public function test_baris_asli_di_bawah_contoh_tetap_terimpor(): void
    {
        $buku = IOFactory::load($this->unduhTemplate('master.mesin'));
        $sheet = $buku->getActiveSheet();
        $sheet->fromArray([['X-77', 'Press Asli', 'Line Z', 'Progressive', 500000, '2025-02-01']], null, 'A'.($sheet->getHighestRow() + 1));
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($tmp);

        $this->post(route('master.mesin.import'), ['file' => UploadedFile::fake()->createWithContent('t.xlsx', file_get_contents($tmp))]);

        $this->assertSame(['X-77'], MasterMesin::pluck('id_mesin')->all());
    }

    /**
     * Judul template "Jam Start (Senin-Kamis)" di-slug jadi jam_start_senin_kamis,
     * sedangkan importer dulu membaca $row['jam_start'] dengan default '00:00' —
     * tiap shift yang diimpor lewat template tersimpan 00:00–00:00 tanpa galat,
     * dan Availability (penyebutnya baca master_shift LIVE) ikut rusak retroaktif.
     */
    public function test_impor_shift_lewat_template_menyimpan_jam_yang_diisi(): void
    {
        $buku = IOFactory::load($this->unduhTemplate('master.shift'));
        $sheet = $buku->getActiveSheet();
        $sheet->fromArray([['Shift Uji', '08:00', '16:00']], null, 'A'.($sheet->getHighestRow() + 1));
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($tmp);

        $this->post(route('master.shift.import'), ['file' => UploadedFile::fake()->createWithContent('t.xlsx', file_get_contents($tmp))]);

        $shift = MasterShift::where('nama_shift', 'Shift Uji')->firstOrFail();
        $this->assertSame('08:00', substr((string) $shift->jam_start, 0, 5));
        $this->assertSame('16:00', substr((string) $shift->jam_finish, 0, 5));
    }

    public function test_impor_shift_berjam_kosong_tidak_menimpa_jam_lama(): void
    {
        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00', 'jam_finish' => '15:00']);

        $buku = IOFactory::load($this->unduhTemplate('master.shift'));
        $sheet = $buku->getActiveSheet();
        $sheet->fromArray([['Shift 1', '', '']], null, 'A'.($sheet->getHighestRow() + 1));
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($tmp);

        $this->post(route('master.shift.import'), ['file' => UploadedFile::fake()->createWithContent('t.xlsx', file_get_contents($tmp))]);

        $this->assertSame('07:00', substr((string) MasterShift::first()->jam_start, 0, 5));
        $this->assertCount(1, session('import_errors'));
        $this->get(route('master.shift.index'))->assertSee('1 baris gagal diimpor');
    }

    /** Baris contoh yang DITIMPA data asli wajib tetap masuk — bukan dibuang per nomor baris. */
    public function test_baris_contoh_yang_ditimpa_tetap_terimpor_di_lhp_produksi(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift A', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        MasterKaryawan::create(['nik' => 'N1', 'nama_karyawan' => 'Budi Santoso', 'departemen' => 'Produksi', 'is_aktif' => true]);
        MasterMesin::create(['id_mesin' => 'A9', 'nama_mesin' => 'MESIN A9', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'PN-9', 'part_name' => 'PART 9']);
        MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 500]);

        $buku = IOFactory::load($this->unduhTemplate('input.produksi'));
        $buku->setActiveSheetIndex(0)->fromArray([['2026-08-20', 'Shift A', 'Budi Santoso', 'A9', 'PN-9', '1', '08:00', '16:00', 3000, '', '']], null, 'A3');
        $tmp = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($tmp);

        $this->post(route('input.produksi.import'), ['file' => UploadedFile::fake()->createWithContent('t.xlsx', file_get_contents($tmp))]);

        $this->assertSame([], session('import_errors') ?? []);
        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame($shift->id, TransaksiProduksi::first()->id_shift);
    }
}
