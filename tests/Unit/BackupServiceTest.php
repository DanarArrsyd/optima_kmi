<?php

namespace Tests\Unit;

use App\Contracts\PembuatDump;
use App\Exceptions\BackupSedangBerjalanException;
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
                // Diulang supaya melewati ambang UKURAN_DUMP_MINIMAL (1024 byte) —
                // dump "wajar" di test-test lain di berkas ini, BUKAN yang diuji
                // test_dump_terlalu_kecil_ditolak() di bawah.
                file_put_contents($tujuan, str_repeat("-- dump uji\nCREATE TABLE contoh (id INT);\n", 40));
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
        Storage::disk('local')->put(BackupService::DIREKTORI.'/optima-2020-01-01_000000.zip', 'arsip lama');
        Storage::disk('local')->put('checksheet/contoh.pdf', 'isi pdf');

        $isi = $this->isiZip($this->layanan()->jalankan('manual', null));

        $this->assertContains('berkas/checksheet/contoh.pdf', $isi);
        foreach ($isi as $entri) {
            $this->assertStringNotContainsString('optima-2020-01-01_000000.zip', $entri);
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
        } catch (BackupSedangBerjalanException $e) {
            // BackupSedangBerjalanException JUGA turunan RuntimeException — lock
            // 'backup-berjalan' yang nyasar/stale bisa lolos catch(RuntimeException)
            // polos di bawah tanpa pernah menyentuh jalur dump-gagal yang diuji
            // test ini. Gagalkan eksplisit supaya test ini tidak pernah lulus
            // trivial karena sebab yang salah.
            $this->fail('Melempar BackupSedangBerjalanException (lock), bukan galat dump yang diuji: '.$e->getMessage());
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

    public function test_dump_janggal_kecil_ditolak(): void
    {
        // mysqldump yang keluar SUKSES tapi cuma menulis header (koneksi
        // putus tepat setelah proses mulai, akses tabel dicabut sebagian di
        // tengah jalan) lolos dari pengecekan exit code — arsipnya jadi ZIP
        // yang kelihatan sah (kartu umur hijau, ukuran ZIP masuk akal) tapi
        // tidak me-restore apa-apa. Itu tepat kasus yang harus ditolak di sini.
        $kecil = new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                file_put_contents($tujuan, "-- cuma header\n");
            }
        };

        try {
            $this->layanan($kecil)->jalankan('manual', null);
            $this->fail('Seharusnya melempar RuntimeException untuk dump yang janggal kecil.');
        } catch (BackupSedangBerjalanException $e) {
            // Lihat catatan yang sama di test_dump_gagal_tidak_meninggalkan_berkas():
            // lock nyasar/stale bisa lolos catch(RuntimeException) polos tanpa
            // pernah menyentuh jalur ambang ukuran dump yang diuji test ini.
            $this->fail('Melempar BackupSedangBerjalanException (lock), bukan galat ukuran dump yang diuji: '.$e->getMessage());
        } catch (RuntimeException) {
            // diharapkan
        }

        // Nol arsip tersisa — bukan arsip nyaris kosong yang tampak sah di daftar.
        $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
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

    public function test_hapus_menolak_nama_di_luar_pola_arsip(): void
    {
        // Flysystem cuma menolak keluar dari root disk, bukan keluar dari
        // subdirektori backups/ — '../checksheet/rahasia.pdf' menormalisasi jadi
        // 'checksheet/rahasia.pdf' dan diam-diam menghapus berkas nyata (disk
        // private yang sama dipakai checksheet PDF Preventive Dies).
        Storage::disk('local')->put('checksheet/rahasia.pdf', 'isi rahasia');

        try {
            $this->layanan()->hapus('../checksheet/rahasia.pdf');
            $this->fail('Seharusnya melempar InvalidArgumentException.');
        } catch (\InvalidArgumentException) {
            // diharapkan
        }

        $this->assertTrue(Storage::disk('local')->exists('checksheet/rahasia.pdf'));
    }

    public function test_umur_jam_terakhir_membaca_arsip_paling_baru(): void
    {
        $disk = Storage::disk('local');
        $disk->put(BackupService::DIREKTORI.'/optima-2026-06-01_010000.zip', 'lama');
        $disk->put(BackupService::DIREKTORI.'/optima-2026-06-10_010000.zip', 'baru');

        // touch() langsung ke path fisik di disk fake — teknik yang sama dipakai
        // test lain di repo ini untuk mengontrol mtime deterministik.
        touch($disk->path(BackupService::DIREKTORI.'/optima-2026-06-01_010000.zip'), time() - 48 * 3600);
        touch($disk->path(BackupService::DIREKTORI.'/optima-2026-06-10_010000.zip'), time() - 2 * 3600);

        $umur = $this->layanan()->umurJamTerakhir();

        // Arsip "baru" berumur ~2 jam. Kalau urutnya kebalik (arsip "lama" yang
        // dibaca), umurnya akan ~48 jam — jauh di luar toleransi ini.
        $this->assertEqualsWithDelta(2.0, $umur, 0.1);
    }
}
