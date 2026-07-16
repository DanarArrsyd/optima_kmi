<?php
namespace Tests\Feature\Master;

use App\Imports\MesinImport;
use App\Imports\PartImport;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * MesinImport & PartImport memuat record lama lewat firstOrNew()/first(), lalu menugaskan
 * hasil parseDate() ke tanggal_instalasi. parseDate() mengembalikan null untuk "sel kosong"
 * DAN "kolom tidak ada" — jadi re-import dengan template tanpa kolom itu menghapus tanggal
 * instalasi yang sudah ada, diam-diam.
 *
 * Yang rusak bukan cuma satu kolom: tanggal_instalasi adalah titik awal siklus maintenance
 * (`$cycleStart = $lastPreventive ?? $m->tanggal_instalasi`), jadi MTTR/MTBF/preventive
 * ikut salah hitung tanpa error apa pun.
 *
 * Perilaku yang diputuskan: kolom hilang ATAU sel kosong = jangan sentuh nilai lama.
 * Mengosongkan tanggal dilakukan lewat form edit, bukan import.
 */
class MesinImportWipeTest extends TestCase
{
    use RefreshDatabase;

    private function seedMesin(?string $tanggalInstalasi): void
    {
        MasterMesin::create([
            'id_mesin' => 'W-01', 'nama_mesin' => 'Press W', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => $tanggalInstalasi,
        ]);
    }

    private function importMesin(array $row): void
    {
        (new MesinImport())->collection(new Collection([$row]));
    }

    private function baseRow(): array
    {
        return [
            'id_mesin' => 'W-01', 'nama_mesin' => 'Press W', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ];
    }

    private function freshTanggal(): ?string
    {
        return MasterMesin::where('id_mesin', 'W-01')->sole()->tanggal_instalasi?->format('Y-m-d');
    }

    public function test_reimport_without_tanggal_instalasi_column_keeps_existing_value(): void
    {
        $this->seedMesin('2015-03-10');

        $this->importMesin($this->baseRow());

        $this->assertSame('2015-03-10', $this->freshTanggal());
    }

    public function test_reimport_with_blank_tanggal_instalasi_keeps_existing_value(): void
    {
        $this->seedMesin('2015-03-10');

        $this->importMesin($this->baseRow() + ['tanggal_instalasi' => '']);

        $this->assertSame('2015-03-10', $this->freshTanggal());
    }

    /** Nilai baru yang sah harus tetap menimpa — guard tidak boleh bikin kolom read-only. */
    public function test_reimport_with_a_real_date_still_updates(): void
    {
        $this->seedMesin('2015-03-10');

        $this->importMesin($this->baseRow() + ['tanggal_instalasi' => '2018-09-01']);

        $this->assertSame('2018-09-01', $this->freshTanggal());
    }

    public function test_first_import_still_sets_the_date(): void
    {
        $this->importMesin($this->baseRow() + ['tanggal_instalasi' => '2021-01-15']);

        $this->assertSame('2021-01-15', $this->freshTanggal());
    }

    public function test_part_import_without_tanggal_instalasi_column_keeps_existing_value(): void
    {
        MasterPart::create([
            'part_no' => 'W-P1', 'part_name' => 'Panel W',
            'customer' => 'Toyota', 'tanggal_instalasi' => '2016-05-20',
        ]);

        // PartImport tidak pakai WithHeadingRow: baris pertama = header, tiap row = Collection.
        (new PartImport())->collection(new Collection([
            collect(['part_no', 'part_name', 'customer']),
            collect(['W-P1', 'Panel W', 'Toyota']),
        ]));

        $this->assertSame(
            '2016-05-20',
            MasterPart::where('part_no', 'W-P1')->sole()->tanggal_instalasi?->format('Y-m-d'),
        );
    }
}
