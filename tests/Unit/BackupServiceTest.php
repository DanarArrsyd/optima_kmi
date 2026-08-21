<?php

namespace Tests\Unit;

use App\Contracts\PembuatDump;
use App\Services\BackupService;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupServiceTest extends TestCase
{
    private function layanan(?PembuatDump $pembuat = null): BackupService
    {
        return new BackupService($pembuat ?? new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                file_put_contents($tujuan, "-- dump uji\nCREATE TABLE contoh (id INT);\n");
            }
        });
    }

    private function isiZip(string $nama): array
    {
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path(BackupService::DIREKTORI.'/'.$nama));
        $isi = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $isi[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $isi;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_menyusun_arsip_lengkap(): void
    {
        $nama = $this->layanan()->jalankan('manual', 7);

        $isi = $this->isiZip($nama);
        $this->assertContains('database.sql', $isi);
        $this->assertContains('manifest.json', $isi);
    }

    public function test_nama_berkas_memakai_pola_baku(): void
    {
        $this->assertMatchesRegularExpression(
            '/^optima-\d{4}-\d{2}-\d{2}_\d{4}\.zip$/',
            $this->layanan()->jalankan('manual', 7)
        );
    }

    public function test_berkas_mengecualikan_direktori_backup(): void
    {
        // Tanpa pengecualian ini arsip ke-N memuat N-1 pendahulunya dan
        // ukurannya menggelembung berlipat sampai disknya penuh.
        Storage::disk('local')->put(BackupService::DIREKTORI.'/optima-2020-01-01_0000.zip', 'arsip lama');
        Storage::disk('local')->put('checksheet/contoh.pdf', 'isi pdf');

        $isi = $this->isiZip($this->layanan()->jalankan('manual', null));

        $this->assertContains('berkas/checksheet/contoh.pdf', $isi);
        foreach ($isi as $entri) {
            $this->assertStringNotContainsString('optima-2020-01-01_0000.zip', $entri);
        }
    }

    public function test_dump_gagal_tidak_meninggalkan_berkas(): void
    {
        // Arsip 0 byte yang terlihat sah di daftar lebih buruk daripada nol arsip:
        // orang mengira punya titik pulih sampai hari ia dibutuhkan.
        $gagal = new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                throw new RuntimeException('mysqldump gagal (kode 2): akses ditolak');
            }
        };

        try {
            $this->layanan($gagal)->jalankan('manual', null);
            $this->fail('Seharusnya melempar RuntimeException.');
        } catch (RuntimeException) {
            // diharapkan
        }

        $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_manifest_memuat_sumber_dan_pemicu(): void
    {
        $nama = $this->layanan()->jalankan('terjadwal', null);

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path(BackupService::DIREKTORI.'/'.$nama));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame('terjadwal', $manifest['sumber']);
        $this->assertNull($manifest['dipicu_oleh']);
        $this->assertArrayHasKey('jumlah_tabel', $manifest);
        $this->assertArrayHasKey('waktu_wib', $manifest);
    }
}
