<?php

namespace Tests\Feature\Sistem;

use App\Contracts\PembuatDump;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Console\Output\BufferedOutput;
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
                // Diulang supaya melewati ambang UKURAN_DUMP_MINIMAL (1024 byte)
                // di BackupService — bukan aspek yang diuji test-test di berkas ini.
                file_put_contents($tujuan, str_repeat("-- dump uji\n", 100));
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

    public function test_keluar_dengan_kode_gagal_saat_lock_sedang_dipegang(): void
    {
        // Jadwal terjadwal yang tidak bisa mendapat lock (klik manual sedang
        // jalan) WAJIB berhenti dengan kode keluar bukan nol. Kalau tidak,
        // workflow GitHub Actions tetap hijau padahal malam itu nol backup
        // terjadi — persis kegagalan senyap yang jadi alasan fitur ini ada.
        $kunci = Cache::lock('backup-berjalan', 600);
        $this->assertTrue($kunci->get());

        try {
            $this->pasangDumper();

            $this->artisan('backup:jalankan')->assertFailed();

            $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
        } finally {
            $kunci->release();
        }
    }

    public function test_keluar_dengan_kode_gagal_saat_dump_gagal(): void
    {
        // Workflow harus MERAH kalau backup gagal, bukan hijau dengan galat di
        // keluarannya — jadwal yang gagal diam-diam persis masalah yang mau
        // dihindari fitur ini.
        $this->pasangDumper(gagal: true);

        $this->artisan('backup:jalankan')->assertFailed();
    }

    public function test_detail_galat_tidak_bocor_ke_keluaran_yang_dibaca_workflow(): void
    {
        // Keluaran perintah ini mengalir lewat SSH ke log run GitHub Actions,
        // yang bisa dibaca siapa pun dengan akses repo. Detail teknis
        // (di sini disimulasikan dengan nama host server palsu) harus berhenti
        // di Log::error(), bukan diteruskan ke layar/keluaran.
        $this->app->bind(PembuatDump::class, fn () => new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                throw new RuntimeException(
                    'mysqldump gagal (kode 2): koneksi ke db.internal-host-palsu.test:3306 ditolak'
                );
            }
        });

        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('backup:jalankan', [], $buffer);
        $keluaran = $buffer->fetch();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Backup gagal', $keluaran);
        $this->assertStringNotContainsString('db.internal-host-palsu.test', $keluaran);
    }
}
