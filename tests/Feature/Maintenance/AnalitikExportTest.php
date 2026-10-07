<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ekspor Excel halaman analitik maintenance. Isi file diambil dari data()
 * controller yang sama dengan tabel layar — tidak ada rumus kedua. Control
 * Stroke menyaring di klien, jadi ekspornya menerapkan saringan yang sama
 * dari parameter yang dikirim tombolnya.
 */
class AnalitikExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'PRESS ALFA', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 800000]);
        MasterMesin::create(['id_mesin' => 'B1', 'nama_mesin' => 'PRESS BETA', 'line' => 'B', 'tipe_mesin' => 'TANDEM', 'kapasitas_max_stroke' => 800000]);
        $p = MasterPart::create(['part_no' => 'PN-ALFA', 'part_name' => 'DIES ALFA', 'customer' => 'HONDA']);
        $q = MasterPart::create(['part_no' => 'PN-BETA', 'part_name' => 'DIES BETA', 'customer' => 'TOYOTA']);
        $prA = MasterPartProses::create(['id_part' => $p->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 500]);
        $prB = MasterPartProses::create(['id_part' => $q->id, 'id_proses' => 1, 'nama_proses' => 'BEND', 'sph_standar_produksi' => 500]);

        // Halaman dies cuma menampilkan dies bertanggal instalasi / ber-breakdown,
        // dan Control Stroke Dies cuma dies yang pernah diproduksi.
        $p->forceFill(['tanggal_instalasi' => '2025-01-01'])->save();
        $q->forceFill(['tanggal_instalasi' => '2025-01-01'])->save();
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00']);
        $op = MasterKaryawan::create(['nik' => 'P1', 'nama_karyawan' => 'OP', 'departemen' => 'Produksi', 'is_aktif' => true]);
        foreach ([$prA, $prB] as $pr) {
            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $op->id,
                'id_mesin' => MasterMesin::first()->id, 'id_part_proses' => $pr->id,
                'jam_start' => '07:00', 'jam_finish' => '15:00', 'qty_aktual' => 1000, 'created_by' => auth()->id(),
            ]);
        }
    }

    /** [rute, judul kolom pertama yang diharapkan, judul kolom terakhir] */
    public static function halaman(): array
    {
        return [
            'mttr' => ['maintenance.mttr', 'Mesin', 'MTTR (jam)'],
            'mtbf' => ['maintenance.mtbf', 'Mesin', 'MTBF (hari kerja)'],
            'availability' => ['maintenance.availability', 'Mesin', 'Availability %'],
            'mttr dies' => ['maintenance.mttr-dies', 'Part No', 'MTTR (jam)'],
            'mtbf dies' => ['maintenance.mtbf-dies', 'Part No', 'MTBF (hari kerja)'],
            'availability dies' => ['maintenance.availability-dies', 'Part No', 'Availability %'],
            'control stroke mesin' => ['maintenance.control-stroke-mesin', 'Mesin', 'PIC Preventive'],
            'control stroke dies' => ['maintenance.control-stroke-dies', 'Part No', 'PIC Preventive'],
        ];
    }

    /** @return list<list<mixed>> */
    private function isi(TestResponse $res): array
    {
        $res->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $res->headers->get('content-type'));

        return IOFactory::load($res->baseResponse->getFile()->getPathname())->getActiveSheet()->toArray();
    }

    #[DataProvider('halaman')]
    public function test_ekspor_sama_dengan_tabel_data(string $rute, string $kolomAwal, string $kolomAkhir): void
    {
        $param = ['date_from' => '2026-01-01', 'date_to' => '2026-10-07'];
        $isi = $this->isi($this->get(route("{$rute}.export", $param)));
        $rows = $this->getJson(route("{$rute}.data", $param))->json('rows');

        $this->assertSame($kolomAwal, $isi[0][0]);
        $this->assertSame($kolomAkhir, end($isi[0]));
        $this->assertCount(count($rows) + 1, $isi);
        $this->assertGreaterThan(0, count($rows), 'fixture tidak menghasilkan baris');
        $this->assertEqualsCanonicalizing(array_column($rows, 'kode_mesin'), array_column(array_slice($isi, 1), 0));
    }

    #[DataProvider('halaman')]
    public function test_halaman_punya_tombol_ekspor_yang_diisi_pemuat(string $rute, string $kolomAwal, string $kolomAkhir): void
    {
        $html = $this->get(route($rute))->assertOk()->getContent();

        $this->assertStringContainsString('id="exportExcel"', $html);
        $this->assertStringContainsString(route("{$rute}.export"), $html);
    }

    public function test_control_stroke_mesin_menerapkan_saringan_klien(): void
    {
        $isi = $this->isi($this->get(route('maintenance.control-stroke-mesin.export', ['line' => 'B'])));
        $this->assertSame(['B1'], array_column(array_slice($isi, 1), 0));

        $isi = $this->isi($this->get(route('maintenance.control-stroke-mesin.export', ['q' => 'alfa'])));
        $this->assertSame(['A1'], array_column(array_slice($isi, 1), 0));
    }

    public function test_control_stroke_dies_menerapkan_saringan_klien(): void
    {
        $isi = $this->isi($this->get(route('maintenance.control-stroke-dies.export', ['customer' => 'TOYOTA'])));
        $this->assertSame(['PN-BETA'], array_column(array_slice($isi, 1), 0));
    }

    public function test_guest_tidak_boleh_ekspor(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        foreach (self::halaman() as [$rute]) {
            $this->get(route("{$rute}.export"))->assertForbidden();
        }
    }
}
