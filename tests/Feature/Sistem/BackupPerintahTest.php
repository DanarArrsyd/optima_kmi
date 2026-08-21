<?php

namespace Tests\Feature\Sistem;

use App\Contracts\PembuatDump;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupPerintahTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function pasangDumper(bool $gagal = false): void
    {
        $this->app->bind(PembuatDump::class, fn () => new class($gagal) implements PembuatDump
        {
            public function __construct(private bool $gagal) {}

            public function keBerkas(string $tujuan): void
            {
                if ($this->gagal) {
                    throw new RuntimeException('mysqldump gagal (kode 2): akses ditolak');
                }
                file_put_contents($tujuan, "-- dump uji\n");
            }
        });
    }

    public function test_menandai_sumber_terjadwal(): void
    {
        $this->pasangDumper();

        $this->artisan('backup:jalankan')->assertSuccessful();

        $berkas = Storage::disk('local')->files(BackupService::DIREKTORI);
        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path($berkas[0]));
        $manifest = json_decode($zip->getFromName('manifest.json'), true);
        $zip->close();

        $this->assertSame('terjadwal', $manifest['sumber']);
        $this->assertNull($manifest['dipicu_oleh']);
    }

    public function test_keluar_dengan_kode_gagal_saat_dump_gagal(): void
    {
        // Workflow harus MERAH kalau backup gagal, bukan hijau dengan galat di
        // keluarannya — jadwal yang gagal diam-diam persis masalah yang mau
        // dihindari fitur ini.
        $this->pasangDumper(gagal: true);

        $this->artisan('backup:jalankan')->assertFailed();
    }
}
