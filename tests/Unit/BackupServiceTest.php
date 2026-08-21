<?php

namespace Tests\Unit;

use App\Contracts\PembuatDump;
use App\Services\BackupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class BackupServiceTest extends TestCase
{
    /**
     * Direktori kerja sementara ada di filesystem SUNGGUHAN (storage_path()),
     * bukan di dalam Storage::fake('local'), jadi dibersihkan manual di sini
     * supaya tidak bocor antar test dan tetap bisa diperiksa deterministik.
     */
    private function direktoriSementara(): string
    {
        return storage_path(BackupService::DIREKTORI_KERJA);
    }

    private function jumlahDirektoriSementaraTersisa(): int
    {
        $dir = $this->direktoriSementara();

        return File::isDirectory($dir) ? count(File::directories($dir)) : 0;
    }

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
        File::deleteDirectory($this->direktoriSementara());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->direktoriSementara());
        parent::tearDown();
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
            '/^optima-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/',
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

        // Bukti nyata bahwa `finally { File::deleteDirectory($kerja); }` benar-benar
        // jalan: direktori kerja sementara di storage_path() tidak boleh tersisa.
        $this->assertSame(0, $this->jumlahDirektoriSementaraTersisa());

        // Jalur sukses juga wajib bebas sisa direktori kerja, bukan cuma jalur gagal.
        $this->layanan()->jalankan('manual', null);
        $this->assertSame(0, $this->jumlahDirektoriSementaraTersisa());
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

    public function test_pangkas_menyisakan_tiga_puluh_terbaru(): void
    {
        // Tanggal sungguhan, bukan 2026-07-33: nama berkas ini juga yang dibaca
        // manusia di halaman, dan fixture yang mustahil menyembunyikan salah urut.
        $mulai = Carbon::parse('2026-06-01');
        foreach (range(0, 32) as $i) {
            Storage::disk('local')->put(
                BackupService::DIREKTORI.'/optima-'.$mulai->copy()->addDays($i)->format('Y-m-d').'_010000.zip',
                'isi'
            );
        }

        $terbuang = $this->layanan()->pangkas(30);

        $tersisa = implode(',', Storage::disk('local')->files(BackupService::DIREKTORI));
        $this->assertSame(3, $terbuang);
        $this->assertCount(30, Storage::disk('local')->files(BackupService::DIREKTORI));
        // Yang dibuang yang PALING LAMA, bukan yang paling baru.
        $this->assertStringNotContainsString('optima-2026-06-01_0100', $tersisa);
        $this->assertStringNotContainsString('optima-2026-06-03_0100', $tersisa);
        $this->assertStringContainsString('optima-2026-07-03_0100', $tersisa);
    }

    public function test_jalankan_memangkas_otomatis(): void
    {
        $mulai = Carbon::parse('2026-06-01');
        foreach (range(0, 29) as $i) {
            Storage::disk('local')->put(
                BackupService::DIREKTORI.'/optima-'.$mulai->copy()->addDays($i)->format('Y-m-d').'_010000.zip',
                'isi'
            );
        }

        $this->layanan()->jalankan('manual', null);

        $this->assertCount(30, Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_daftar_membaca_manifest_dan_urut_terbaru_dulu(): void
    {
        $this->layanan()->jalankan('terjadwal', null);
        Storage::disk('local')->put(BackupService::DIREKTORI.'/optima-2020-01-01_000000.zip', 'lama');

        $daftar = $this->layanan()->daftar();

        $this->assertCount(2, $daftar);
        $this->assertSame('terjadwal', $daftar[0]['sumber']);
        // Arsip tanpa manifest yang bisa dibaca tetap tampil, tidak disembunyikan.
        $this->assertSame('optima-2020-01-01_000000.zip', $daftar[1]['nama']);
        $this->assertNull($daftar[1]['sumber']);
    }

    public function test_hapus_membuang_satu_arsip(): void
    {
        $nama = $this->layanan()->jalankan('manual', null);

        $this->layanan()->hapus($nama);

        $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_umur_null_saat_belum_ada_backup(): void
    {
        $this->assertNull($this->layanan()->umurJamTerakhir());
    }
}
