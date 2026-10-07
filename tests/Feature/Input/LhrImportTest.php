<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiRepair;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Impor Excel LHR Repair & LHR Repair Produksi. Aturan baris SAMA dengan
 * input batch (diambil dari FormRequest batch-nya) dan ditulis lewat
 * WriteService yang sama — impor tidak boleh jadi pintu belakang aturan.
 */
class LhrImportTest extends TestCase
{
    use RefreshDatabase;

    /** [prefix rute, model transaksi, model proses, kolom fk proses, kolom tanggal] */
    public static function jenis(): array
    {
        return [
            'repair' => ['input.repair', TransaksiRepair::class, MasterPartProsesRepair::class, 'id_part_proses_repair', 'tanggal_repair', 'sph_standar_repair'],
            'repair produksi' => ['input.repair-produksi', TransaksiRepairProduksi::class, MasterPartProsesRepairProduksi::class, 'id_part_proses_repair_produksi', 'tanggal_repair_produksi', 'sph_standar_repair_produksi'],
        ];
    }

    private function master(string $modelProses, string $kolomSph): array
    {
        $shift = MasterShift::create(['nama_shift' => 'Shift A', 'jam_start' => '07:00', 'jam_finish' => '15:00']);
        $op = MasterKaryawan::create(['nik' => 'R1', 'nama_karyawan' => 'Rina Maharani', 'departemen' => 'Repair', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'PN-77', 'part_name' => 'BRACKET 77', 'customer' => 'HONDA']);
        $proses = $modelProses::create(['id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'GERINDA', $kolomSph => 120]);

        return compact('shift', 'op', 'part', 'proses');
    }

    /** Unduh template, isi baris mulai baris 3 (menimpa contoh), lalu unggah. */
    private function impor(string $prefix, array $baris): void
    {
        $res = $this->get(route("{$prefix}.import-template"))->assertOk();
        $buku = IOFactory::load($res->baseResponse->getFile()->getPathname());
        $buku->setActiveSheetIndex(0)->fromArray($baris, null, 'A3');
        $tmp = tempnam(sys_get_temp_dir(), 'lhr').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($tmp);

        $this->post(route("{$prefix}.import"), ['file' => UploadedFile::fake()->createWithContent('lhr.xlsx', file_get_contents($tmp))])
            ->assertRedirect(route("{$prefix}.index"));
    }

    #[DataProvider('jenis')]
    public function test_baris_valid_tersimpan_dengan_id_terurai_dan_cycle_time(string $prefix, string $model, string $modelProses, string $fk, string $kolomTgl, string $kolomSph): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $m = $this->master($modelProses, $kolomSph);

        $this->impor($prefix, [['2026-09-14', 'Shift A', 'Rina Maharani', 'PN-77', '2', '08:00', '10:00', 240]]);

        $this->assertSame([], session('import_errors'));
        $t = $model::sole();
        $this->assertSame($m['proses']->id, $t->{$fk});
        $this->assertSame($m['shift']->id, $t->id_shift);
        $this->assertSame($m['op']->id, $t->id_karyawan);
        $this->assertSame('2026-09-14', substr((string) $t->{$kolomTgl}, 0, 10));
        $this->assertEquals(30.0, $t->actual_cycle_time); // 7200 dtk / 240
    }

    #[DataProvider('jenis')]
    public function test_baris_salah_dilaporkan_per_nomor_dan_baris_valid_tetap_masuk(string $prefix, string $model, string $modelProses, string $fk, string $kolomTgl, string $kolomSph): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $this->master($modelProses, $kolomSph);

        $this->impor($prefix, [
            ['2026-09-14', 'Shift A', 'Rina Maharani', 'PN-77', '2', '08:00', '10:00', 240],
            ['2026-09-14', 'Shift Z', 'Rina Maharani', 'PN-77', '2', '08:00', '10:00', 240],
            ['2026-09-14', 'Shift A', 'Rina Maharani', 'PN-TIDAK-ADA', '2', '08:00', '10:00', 240],
            ['2026-09-14', 'Shift A', 'Rina Maharani', 'PN-77', '2', '09:00', '09:00', 240],
        ]);

        $galat = session('import_errors');
        $this->assertCount(3, $galat);
        $this->assertStringStartsWith('Baris 4:', $galat[0]);
        $this->assertStringContainsString('Shift Z', $galat[0]);
        $this->assertStringStartsWith('Baris 5:', $galat[1]);
        $this->assertStringContainsString('PN-TIDAK-ADA', $galat[1]);
        $this->assertStringStartsWith('Baris 6:', $galat[2]);
        $this->assertStringContainsString('jam mulai dan jam selesai tidak boleh sama', $galat[2]);
        $this->assertSame(1, $model::count());
    }

    public function test_hak_akses_impor_mengikuti_hak_tulis_batch(): void
    {
        $this->master(MasterPartProsesRepair::class, 'sph_standar_repair');
        $file = fn () => UploadedFile::fake()->create('x.xlsx', 1);

        foreach (['staff_produksi', 'staff_qc', 'manager', 'guest'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('input.repair.import'), ['file' => $file()])->assertForbidden();
        }
        foreach (['staff_qc', 'manager', 'guest'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))
                ->post(route('input.repair-produksi.import'), ['file' => $file()])->assertForbidden();
        }
        $this->actingAs(User::factory()->create(['role' => 'staff_repair']))
            ->get(route('input.repair.import-template'))->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']))
            ->get(route('input.repair-produksi.import-template'))->assertOk();
    }
}
