# Backup Sistem — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Halaman admin untuk membuat, mengunduh, dan memangkas arsip backup OPTIMA — dipicu tombol maupun jadwal harian.

**Architecture:** `mysqldump` dijalankan lewat Symfony Process di balik antarmuka kecil supaya bisa dipalsukan di test. Satu backup = satu ZIP berisi `database.sql`, `manifest.json`, dan salinan berkas unggahan. Daftar dibaca dari filesystem + manifest — **tidak ada tabel dan tidak ada migrasi**, jadi tidak ada risiko lupa mendaftarkannya di `deploy.yml`.

**Tech Stack:** Laravel 13 · PHP 8.4 (CI/prod) / 8.5.4 (lokal) · `symfony/process` (bawaan Laravel) · `ext-zip` (sudah wajib lewat maatwebsite/excel) · MySQL prod, SQLite in-memory di test · cache store `database` (mendukung `Cache::lock`)

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-08-21-backup-sistem-design.md`
- **`admin` saja.** Bukan `manager`. Satu arsip memuat seluruh DB termasuk tabel `users`
- Berkas hidup di `storage/app/private/backups/` (disk `local`) — **tidak pernah** di `public/`
- **Kata sandi DB tidak boleh muncul di argumen perintah** — daftar proses terlihat seluruh penyewa di shared hosting. Pakai `--defaults-extra-file` mode `0600`, hapus di `finally`
- **`berkas/` di dalam arsip WAJIB mengecualikan `backups/`** — kalau tidak, tiap arsip memuat seluruh pendahulunya
- **Gagal = nol berkas.** `mysqldump` gagal tidak boleh meninggalkan ZIP separuh jadi atau 0 byte
- **Nama berkas dari URL wajib divalidasi** terhadap pola `optima-YYYY-MM-DD_HHmm.zip` sebelum menyentuh filesystem — direktori di atasnya memuat `.env` server
- Waktu yang dibaca manusia (nama berkas, kolom, kartu) memakai **WIB**; aplikasi berjalan UTC. Jangan set `APP_TIMEZONE`
- Halaman memakai pola `.erp-ws` dan didaftarkan di `ErpWorkspaceMarkupTest::workspacePages()`
- **Direktif Blade tidak boleh menempel karakter kata** (`Filter@if` → 500). Sapuan: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
- **Tidak ada migrasi di plan ini.** Kalau sebuah task terasa butuh tabel, berhenti dan tanya — bukan diam-diam menambahnya
- Test: `php artisan test` (SQLite in-memory). Format: `vendor/bin/pint app tests`
- Komentar, label, dan pesan flash: **Indonesia**

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `app/Contracts/PembuatDump.php` | Antarmuka satu method — titik palsu untuk test |
| `app/Services/Backup/MysqldumpPembuatDump.php` | Menyusun & menjalankan perintah `mysqldump` |
| `app/Services/BackupService.php` | Rakit arsip, daftar, hapus, pangkas, umur terakhir |
| `app/Http/Controllers/Sistem/BackupController.php` | Empat aksi + validasi nama berkas |
| `resources/views/sistem/backup/index.blade.php` | Halaman `.erp-ws` |
| `resources/views/layouts/app.blade.php` | Blok user topbar → dropdown |
| `app/Console/Commands/JalankanBackup.php` | `backup:jalankan` untuk jadwal |
| `.github/workflows/backup.yml` | Cron harian lewat SSH |
| `routes/web.php` | Grup `sistem` role:admin |
| `tests/Unit/MysqldumpPembuatDumpTest.php` | Task 1 |
| `tests/Unit/BackupServiceTest.php` | Task 2, 3 |
| `tests/Feature/Sistem/BackupHalamanTest.php` | Task 4, 5 |
| `tests/Feature/Sistem/BackupPerintahTest.php` | Task 6 |

**Batas keterujian yang harus diterima sejak awal:** `mysqldump` tidak akan pernah jalan di CI — tidak ada MySQL di sana. Seluruh test memasang `PembuatDump` palsu. Yang terbukti: perakitan arsip, retensi, manifest, penyusunan argumen perintah, dan otorisasi. Yang **tidak** terbukti: bahwa dump sungguhannya bisa di-restore. Itu satu langkah manual sesudah deploy.

---

### Task 1: Kontrak & pembuat dump

**Files:**
- Create: `app/Contracts/PembuatDump.php`
- Create: `app/Services/Backup/MysqldumpPembuatDump.php`
- Test: `tests/Unit/MysqldumpPembuatDumpTest.php`

**Interfaces:**
- Consumes: —
- Produces: `PembuatDump::keBerkas(string $tujuan): void` dan `MysqldumpPembuatDump::perintah(string $tujuan, string $berkasKonfig): array`

- [ ] **Step 1: Write the failing test**

Buat `tests/Unit/MysqldumpPembuatDumpTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\Backup\MysqldumpPembuatDump;
use Tests\TestCase;

/**
 * Yang diuji penyusunan argumennya, bukan eksekusinya — CI tidak punya MySQL
 * dan mysqldump tidak akan pernah jalan di sana.
 */
class MysqldumpPembuatDumpTest extends TestCase
{
    private function perintah(): array
    {
        config([
            'database.connections.mysql.host' => 'db.contoh',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'optima_db',
            'database.connections.mysql.username' => 'pengguna',
            'database.connections.mysql.password' => 'sandi-rahasia-sekali',
        ]);

        return (new MysqldumpPembuatDump)->perintah('/tmp/dump.sql', '/tmp/konfig.cnf');
    }

    public function test_sandi_tidak_pernah_masuk_argumen(): void
    {
        // Daftar proses terlihat seluruh penyewa di shared hosting.
        foreach ($this->perintah() as $arg) {
            $this->assertStringNotContainsString('sandi-rahasia-sekali', $arg);
        }
    }

    public function test_sandi_diberikan_lewat_defaults_extra_file(): void
    {
        $this->assertContains('--defaults-extra-file=/tmp/konfig.cnf', $this->perintah());
    }

    public function test_memakai_transaksi_tunggal_supaya_dump_konsisten(): void
    {
        // Tanpa ini dump bisa memotret dua tabel pada keadaan yang berbeda.
        $this->assertContains('--single-transaction', $this->perintah());
    }

    public function test_menulis_ke_berkas_tujuan_dan_database_yang_benar(): void
    {
        $perintah = $this->perintah();

        $this->assertContains('--result-file=/tmp/dump.sql', $perintah);
        $this->assertSame('optima_db', end($perintah));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/MysqldumpPembuatDumpTest.php`
Expected: FAIL — `Class "App\Services\Backup\MysqldumpPembuatDump" not found`

- [ ] **Step 3: Buat kontraknya**

`app/Contracts/PembuatDump.php`:

```php
<?php

namespace App\Contracts;

/**
 * Titik palsu untuk test: mysqldump tidak akan pernah jalan di CI, jadi seluruh
 * test memasang implementasi tiruan lewat antarmuka ini.
 */
interface PembuatDump
{
    /**
     * Menulis dump SQL penuh ke $tujuan. Melempar RuntimeException kalau gagal —
     * berkas separuh jadi tidak boleh dibiarkan.
     */
    public function keBerkas(string $tujuan): void;
}
```

- [ ] **Step 4: Buat implementasinya**

`app/Services/Backup/MysqldumpPembuatDump.php`:

```php
<?php

namespace App\Services\Backup;

use App\Contracts\PembuatDump;
use RuntimeException;
use Symfony\Component\Process\Process;

class MysqldumpPembuatDump implements PembuatDump
{
    public function __construct(private string $biner = '/usr/bin/mysqldump') {}

    /**
     * Argumen dipisah dari eksekusi supaya bisa diuji tanpa MySQL.
     *
     * Kata sandi TIDAK pernah masuk ke sini — ia dititipkan lewat berkas konfig
     * mode 0600, karena argumen perintah terlihat di daftar proses seluruh mesin
     * dan ini shared hosting.
     */
    public function perintah(string $tujuan, string $berkasKonfig): array
    {
        $k = config('database.connections.mysql');

        return [
            $this->biner,
            '--defaults-extra-file='.$berkasKonfig,
            '--host='.$k['host'],
            '--port='.$k['port'],
            '--user='.$k['username'],
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$tujuan,
            $k['database'],
        ];
    }

    public function keBerkas(string $tujuan): void
    {
        $konfig = tempnam(sys_get_temp_dir(), 'optima-dump-');
        chmod($konfig, 0600);
        file_put_contents($konfig, "[client]\npassword=\"".config('database.connections.mysql.password')."\"\n");

        try {
            $proses = new Process($this->perintah($tujuan, $konfig));
            $proses->setTimeout(600);
            $proses->run();

            if (! $proses->isSuccessful()) {
                // Yang dicatat pesan galat dan kode keluar — BUKAN barisnya,
                // supaya kredensial tidak pernah mendarat di log.
                throw new RuntimeException(
                    'mysqldump gagal (kode '.$proses->getExitCode().'): '.trim($proses->getErrorOutput())
                );
            }
        } finally {
            @unlink($konfig);
        }
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Unit/MysqldumpPembuatDumpTest.php`
Expected: PASS (4 test)

- [ ] **Step 6: Commit**

```bash
git add app/Contracts/PembuatDump.php app/Services/Backup/MysqldumpPembuatDump.php tests/Unit/MysqldumpPembuatDumpTest.php
git commit -m "feat(backup): kontrak PembuatDump + pembungkus mysqldump"
```

---

### Task 2: Merakit arsip

**Files:**
- Create: `app/Services/BackupService.php`
- Test: `tests/Unit/BackupServiceTest.php`

**Interfaces:**
- Consumes: `PembuatDump::keBerkas(string $tujuan): void`
- Produces: `BackupService::jalankan(string $sumber, ?int $userId = null): string` (mengembalikan nama berkas ZIP), konstanta `BackupService::DIREKTORI = 'backups'`

- [ ] **Step 1: Write the failing tests**

Buat `tests/Unit/BackupServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/BackupServiceTest.php`
Expected: FAIL — `Class "App\Services\BackupService" not found`

- [ ] **Step 3: Tulis layanannya**

`app/Services/BackupService.php`:

```php
<?php

namespace App\Services;

use App\Contracts\PembuatDump;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class BackupService
{
    public const DIREKTORI = 'backups';

    public function __construct(private PembuatDump $pembuatDump) {}

    /**
     * Merakit satu arsip. Mengembalikan nama berkasnya.
     *
     * Seluruh pekerjaan dilakukan di direktori sementara lebih dulu; ZIP baru
     * dipindahkan ke tujuan setelah utuh. Kalau ada langkah yang gagal, yang
     * tersisa nol berkas — bukan arsip separuh jadi yang terlihat sah di daftar.
     */
    public function jalankan(string $sumber, ?int $userId = null): string
    {
        $waktu = now('Asia/Jakarta');
        $nama = 'optima-'.$waktu->format('Y-m-d_Hi').'.zip';

        $kerja = storage_path('app/backup-sementara-'.uniqid());
        File::ensureDirectoryExists($kerja);

        try {
            $berkasDump = $kerja.'/database.sql';
            $this->pembuatDump->keBerkas($berkasDump);

            $manifest = [
                'waktu_wib' => $waktu->format('Y-m-d H:i:s'),
                'waktu_utc' => now()->format('Y-m-d H:i:s'),
                'sumber' => $sumber,
                'dipicu_oleh' => $userId,
                'jumlah_tabel' => $this->jumlahTabel(),
                'ukuran_dump' => filesize($berkasDump),
            ];
            File::put($kerja.'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

            $zipSementara = $kerja.'/arsip.zip';
            $this->rakitZip($zipSementara, $berkasDump, $kerja.'/manifest.json');

            Storage::disk('local')->makeDirectory(self::DIREKTORI);
            File::move($zipSementara, Storage::disk('local')->path(self::DIREKTORI.'/'.$nama));
        } finally {
            File::deleteDirectory($kerja);
        }

        return $nama;
    }

    private function rakitZip(string $tujuan, string $berkasDump, string $berkasManifest): void
    {
        $zip = new ZipArchive;
        if ($zip->open($tujuan, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak bisa membuat berkas ZIP.');
        }

        $zip->addFile($berkasDump, 'database.sql');
        $zip->addFile($berkasManifest, 'manifest.json');

        // Salin berkas unggahan, KECUALI direktori backup itu sendiri — kalau
        // ikut, tiap arsip memuat seluruh pendahulunya.
        $disk = Storage::disk('local');
        foreach ($disk->allFiles() as $relatif) {
            if (str_starts_with($relatif, self::DIREKTORI.'/')) {
                continue;
            }
            $zip->addFile($disk->path($relatif), 'berkas/'.$relatif);
        }

        $zip->close();
    }

    private function jumlahTabel(): int
    {
        try {
            return count(DB::connection()->getSchemaBuilder()->getTableListing());
        } catch (\Throwable) {
            return 0;
        }
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/BackupServiceTest.php`
Expected: PASS (5 test)

- [ ] **Step 5: Commit**

```bash
git add app/Services/BackupService.php tests/Unit/BackupServiceTest.php
git commit -m "feat(backup): merakit arsip ZIP berisi dump, manifest, dan berkas unggahan"
```

---

### Task 3: Daftar, retensi, hapus, umur terakhir

**Files:**
- Modify: `app/Services/BackupService.php`
- Test: `tests/Unit/BackupServiceTest.php` (tambah)

**Interfaces:**
- Consumes: `BackupService::jalankan()` dari Task 2
- Produces: `daftar(): array` (tiap entri: `nama`, `ukuran`, `waktu_wib`, `sumber`, `dipicu_oleh`, `jumlah_tabel`), `pangkas(int $simpan = 30): int`, `hapus(string $nama): void`, `umurJamTerakhir(): ?float`

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Unit/BackupServiceTest.php`:

```php
    public function test_pangkas_menyisakan_tiga_puluh_terbaru(): void
    {
        foreach (range(1, 33) as $i) {
            Storage::disk('local')->put(
                BackupService::DIREKTORI.'/optima-2026-07-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'_0100.zip',
                'isi'
            );
        }

        $terbuang = $this->layanan()->pangkas(30);

        $tersisa = Storage::disk('local')->files(BackupService::DIREKTORI);
        $this->assertSame(3, $terbuang);
        $this->assertCount(30, $tersisa);
        // Yang dibuang yang PALING LAMA, bukan yang paling baru.
        $this->assertStringNotContainsString('2026-07-01_0100', implode(',', $tersisa));
        $this->assertStringContainsString('2026-07-33_0100', implode(',', $tersisa));
    }

    public function test_jalankan_memangkas_otomatis(): void
    {
        foreach (range(1, 30) as $i) {
            Storage::disk('local')->put(
                BackupService::DIREKTORI.'/optima-2026-07-'.str_pad((string) $i, 2, '0', STR_PAD_LEFT).'_0100.zip',
                'isi'
            );
        }

        $this->layanan()->jalankan('manual', null);

        $this->assertCount(30, Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_daftar_membaca_manifest_dan_urut_terbaru_dulu(): void
    {
        $this->layanan()->jalankan('terjadwal', null);
        Storage::disk('local')->put(BackupService::DIREKTORI.'/optima-2020-01-01_0000.zip', 'lama');

        $daftar = $this->layanan()->daftar();

        $this->assertCount(2, $daftar);
        $this->assertSame('terjadwal', $daftar[0]['sumber']);
        // Arsip tanpa manifest yang bisa dibaca tetap tampil, tidak disembunyikan.
        $this->assertSame('optima-2020-01-01_0000.zip', $daftar[1]['nama']);
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/BackupServiceTest.php`
Expected: FAIL — `Call to undefined method App\Services\BackupService::pangkas()`

- [ ] **Step 3: Tambahkan methodnya**

Tambahkan ke `app/Services/BackupService.php`, dan panggil `$this->pangkas()` tepat sebelum `return $nama;` di `jalankan()`:

```php
    /**
     * Daftar arsip, terbaru dulu. Nama berkas memuat cap waktunya, jadi urutan
     * nama = urutan waktu; tidak perlu membuka satu pun ZIP untuk mengurutkan.
     */
    public function daftar(): array
    {
        $disk = Storage::disk('local');
        $berkas = $disk->files(self::DIREKTORI);
        rsort($berkas);

        return array_map(function (string $path) use ($disk) {
            $nama = basename($path);
            $manifest = $this->bacaManifest($disk->path($path));

            return [
                'nama' => $nama,
                'ukuran' => $disk->size($path),
                'waktu_wib' => $manifest['waktu_wib'] ?? null,
                'sumber' => $manifest['sumber'] ?? null,
                'dipicu_oleh' => $manifest['dipicu_oleh'] ?? null,
                'jumlah_tabel' => $manifest['jumlah_tabel'] ?? null,
            ];
        }, $berkas);
    }

    public function pangkas(int $simpan = 30): int
    {
        $disk = Storage::disk('local');
        $berkas = $disk->files(self::DIREKTORI);
        rsort($berkas);

        $buang = array_slice($berkas, $simpan);
        foreach ($buang as $path) {
            $disk->delete($path);
        }

        return count($buang);
    }

    public function hapus(string $nama): void
    {
        Storage::disk('local')->delete(self::DIREKTORI.'/'.$nama);
    }

    /** Umur arsip terbaru dalam jam, atau null kalau belum ada satu pun. */
    public function umurJamTerakhir(): ?float
    {
        $disk = Storage::disk('local');
        $berkas = $disk->files(self::DIREKTORI);
        if ($berkas === []) {
            return null;
        }
        rsort($berkas);

        return (time() - $disk->lastModified($berkas[0])) / 3600;
    }

    /** Arsip yang manifestnya tak terbaca tetap ditampilkan — menyembunyikannya menyembunyikan masalah. */
    private function bacaManifest(string $pathAbsolut): array
    {
        $zip = new ZipArchive;
        if ($zip->open($pathAbsolut) !== true) {
            return [];
        }
        $isi = $zip->getFromName('manifest.json');
        $zip->close();

        return is_string($isi) ? (json_decode($isi, true) ?: []) : [];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/BackupServiceTest.php`
Expected: PASS (10 test)

- [ ] **Step 5: Commit**

```bash
git add app/Services/BackupService.php tests/Unit/BackupServiceTest.php
git commit -m "feat(backup): daftar, retensi 30 terbaru, hapus, dan umur arsip"
```

---

### Task 4: Halaman & route

**Files:**
- Create: `app/Http/Controllers/Sistem/BackupController.php`
- Create: `resources/views/sistem/backup/index.blade.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Layout/ErpWorkspaceMarkupTest.php:41-69`
- Test: `tests/Feature/Sistem/BackupHalamanTest.php`

**Interfaces:**
- Consumes: `BackupService::daftar()`, `jalankan()`, `hapus()`, `umurJamTerakhir()`, konstanta `DIREKTORI`
- Produces: route bernama `sistem.backup.index`, `sistem.backup.jalankan`, `sistem.backup.unduh`, `sistem.backup.hapus`

- [ ] **Step 1: Write the failing tests**

Buat `tests/Feature/Sistem/BackupHalamanTest.php`:

```php
<?php

namespace Tests\Feature\Sistem;

use App\Contracts\PembuatDump;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BackupHalamanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->app->bind(PembuatDump::class, fn () => new class implements PembuatDump
        {
            public function keBerkas(string $tujuan): void
            {
                file_put_contents($tujuan, "-- dump uji\n");
            }
        });
    }

    public static function peranBukanAdmin(): array
    {
        return [
            'manager' => ['manager'],
            'staff_produksi' => ['staff_produksi'],
            'staff_qc' => ['staff_qc'],
            'staff_maintenance' => ['staff_maintenance'],
            'guest' => ['guest'],
        ];
    }

    #[DataProvider('peranBukanAdmin')]
    public function test_hanya_admin_yang_boleh_membuka(string $peran): void
    {
        // Satu arsip memuat seluruh DB termasuk tabel users.
        $u = User::factory()->create(['role' => $peran]);

        $this->actingAs($u)->get(route('sistem.backup.index'))->assertForbidden();
        $this->actingAs($u)->post(route('sistem.backup.jalankan'))->assertForbidden();
    }

    public function test_admin_bisa_membuka(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('sistem.backup.index'))->assertOk();
    }

    public function test_tombol_membuat_arsip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('sistem.backup.jalankan'))->assertRedirect();

        $this->assertCount(1, Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_nama_berkas_di_luar_pola_ditolak(): void
    {
        // Direktori di atas backups/ memuat .env server.
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get('/sistem/backup/'.urlencode('../../.env').'/unduh')
            ->assertNotFound();
    }

    public function test_unduh_mengirim_arsip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nama = app(BackupService::class)->jalankan('manual', $admin->id);

        $this->actingAs($admin)
            ->get(route('sistem.backup.unduh', $nama))
            ->assertOk()
            ->assertDownload($nama);
    }

    public function test_hapus_membuang_arsip(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $nama = app(BackupService::class)->jalankan('manual', $admin->id);

        $this->actingAs($admin)->delete(route('sistem.backup.hapus', $nama))->assertRedirect();

        $this->assertSame([], Storage::disk('local')->files(BackupService::DIREKTORI));
    }

    public function test_kartu_umur_memberi_peringatan_saat_belum_pernah_backup(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('sistem.backup.index'))
            ->assertSee('Belum pernah', false);
    }

    public function test_kartu_umur_merah_setelah_48_jam(): void
    {
        // Kartu ini yang memberi tahu kalau jadwalnya berhenti — kalau ambangnya
        // salah, satu-satunya alarm fitur ini ikut mati.
        $admin = User::factory()->create(['role' => 'admin']);
        $nama = app(BackupService::class)->jalankan('terjadwal', null);

        // Mundurkan waktu ubah berkasnya ke 50 jam lalu.
        touch(
            Storage::disk('local')->path(BackupService::DIREKTORI.'/'.$nama),
            time() - (50 * 3600)
        );

        $this->actingAs($admin)
            ->get(route('sistem.backup.index'))
            ->assertSee('#b91c1c', false);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Sistem/BackupHalamanTest.php`
Expected: FAIL — `Route [sistem.backup.index] not defined.`

- [ ] **Step 3: Ikat kontrak ke implementasinya**

`app/Providers/AppServiceProvider.php`, di dalam `register()`:

```php
        $this->app->bind(\App\Contracts\PembuatDump::class, \App\Services\Backup\MysqldumpPembuatDump::class);
```

- [ ] **Step 4: Tulis controllernya**

`app/Http/Controllers/Sistem/BackupController.php`:

```php
<?php

namespace App\Http\Controllers\Sistem;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class BackupController extends Controller
{
    /** Nama berkas dari URL tidak pernah dipercaya — direktori di atasnya memuat .env server. */
    private const POLA_NAMA = '/^optima-\d{4}-\d{2}-\d{2}_\d{4}\.zip$/';

    public function __construct(private BackupService $backup) {}

    public function index()
    {
        return view('sistem.backup.index', [
            'daftar' => $this->backup->daftar(),
            'umurJam' => $this->backup->umurJamTerakhir(),
        ]);
    }

    public function jalankan()
    {
        $kunci = Cache::lock('backup-berjalan', 600);

        if (! $kunci->get()) {
            return back()->with('error', 'Backup sedang berjalan. Tunggu sampai selesai.');
        }

        try {
            $nama = $this->backup->jalankan('manual', auth()->id());

            return back()->with('success', "Backup {$nama} berhasil dibuat.");
        } catch (Throwable $e) {
            Log::error('Backup manual gagal: '.$e->getMessage());

            return back()->with('error', 'Backup gagal: '.$e->getMessage());
        } finally {
            $kunci->release();
        }
    }

    public function unduh(string $berkas)
    {
        $this->pastikanNamaWajar($berkas);

        $path = BackupService::DIREKTORI.'/'.$berkas;
        if (! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException;
        }

        return response()->download(Storage::disk('local')->path($path), $berkas);
    }

    public function hapus(string $berkas)
    {
        $this->pastikanNamaWajar($berkas);
        $this->backup->hapus($berkas);

        return back()->with('success', "Backup {$berkas} dihapus.");
    }

    private function pastikanNamaWajar(string $berkas): void
    {
        if (preg_match(self::POLA_NAMA, $berkas) !== 1) {
            throw new NotFoundHttpException;
        }
    }
}
```

- [ ] **Step 5: Daftarkan routenya**

`routes/web.php`, tambahkan grup baru tepat setelah grup `master` ditutup:

```php
    // --- SISTEM (admin only) — satu arsip memuat seluruh DB termasuk tabel users ---
    Route::middleware('role:admin')->prefix('sistem')->name('sistem.')->group(function () {
        Route::get('backup', [\App\Http\Controllers\Sistem\BackupController::class, 'index'])->name('backup.index');
        Route::post('backup/jalankan', [\App\Http\Controllers\Sistem\BackupController::class, 'jalankan'])->name('backup.jalankan');
        Route::get('backup/{berkas}/unduh', [\App\Http\Controllers\Sistem\BackupController::class, 'unduh'])->name('backup.unduh');
        Route::delete('backup/{berkas}', [\App\Http\Controllers\Sistem\BackupController::class, 'hapus'])->name('backup.hapus');
    });
```

- [ ] **Step 6: Tulis halamannya**

`resources/views/sistem/backup/index.blade.php` — ikuti susunan `.erp-ws` yang dipakai halaman Master. Kerangkanya:

```blade
@extends('layouts.app')
@section('title', 'Backup Sistem')
@section('main_mod', 'erp-flush')

@section('content')
<div class="erp-ws">
    <div class="erp-tb">
        <form method="POST" action="{{ route('sistem.backup.jalankan') }}" style="margin:0"
              onsubmit="return confirm('Jalankan backup sekarang?')">
            @csrf
            <button type="submit" class="erp-btn is-pri">
                <i class="bi bi-download me-1"></i>Backup Now
            </button>
        </form>
    </div>

    <div class="erp-ws-body">
        <div class="erp-ws-main">
            <div class="erp-scroll erp-scroll-pad">
                @php
                    $bahaya = $umurJam === null || $umurJam > 48;
                @endphp
                <div style="background:#fff;border:1px solid {{ $bahaya ? '#b91c1c' : '#d1d5db' }};border-radius:2px;padding:12px 14px;margin-bottom:10px">
                    <div style="font-size:9.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
                        Backup Terakhir
                    </div>
                    <div style="font-size:22px;font-weight:700;line-height:1.1;color:{{ $bahaya ? '#b91c1c' : '#111827' }}">
                        @if($umurJam === null)
                            Belum pernah
                        @else
                            {{ number_format($umurJam, 1) }} jam lalu
                        @endif
                    </div>
                </div>

                <table class="erp-grid">
                    <thead>
                        <tr>
                            <th style="width:150px">Waktu (WIB)</th>
                            <th style="width:100px">Sumber</th>
                            <th style="width:100px">Ukuran</th>
                            <th style="width:100px">Jumlah Tabel</th>
                            <th>Nama Berkas</th>
                            <th style="width:180px">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($daftar as $b)
                        <tr>
                            <td>{{ $b['waktu_wib'] ?? '—' }}</td>
                            <td>{{ $b['sumber'] ?? '—' }}</td>
                            <td>{{ number_format($b['ukuran'] / 1048576, 2) }} MB</td>
                            <td>{{ $b['jumlah_tabel'] ?? '—' }}</td>
                            <td style="font-family:monospace">{{ $b['nama'] }}</td>
                            <td>
                                <a href="{{ route('sistem.backup.unduh', $b['nama']) }}" class="btn btn-sm btn-primary">Unduh</a>
                                <form method="POST" action="{{ route('sistem.backup.hapus', $b['nama']) }}"
                                      style="display:inline-block"
                                      onsubmit="return confirm('Hapus arsip {{ $b['nama'] }}? Tidak bisa dibatalkan.')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;padding:14px">Belum ada arsip backup.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="erp-status">
        <span>{{ count($daftar) }} arsip</span>
        <span>Retensi 30 terbaru</span>
        <span>{{ auth()->user()->name }}</span>
    </div>
</div>
@endsection
```

⚠️ Sebelum menyalin, **buka `resources/views/master/mesin/index.blade.php` dan samakan nama kelas grid, struktur `.erp-status`, dan cara memuat tabelnya** — kalau berbeda, yang benar adalah yang ada di berkas itu, bukan kerangka di atas. Jaga spasi sebelum tiap direktif Blade.

- [ ] **Step 7: Daftarkan ke penjaga tata letak**

`tests/Feature/Layout/ErpWorkspaceMarkupTest.php`, tambahkan ke `workspacePages()`:

```php
            'Backup Sistem' => ['sistem.backup.index', 'admin', true],
```

- [ ] **Step 8: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Sistem/BackupHalamanTest.php`
Expected: PASS (11 test)

Run: `php artisan test tests/Feature/Layout/ErpWorkspaceMarkupTest.php`
Expected: PASS — halaman baru ikut terjaga

Run: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
Expected: tidak ada keluaran

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Sistem/BackupController.php resources/views/sistem/backup/index.blade.php routes/web.php app/Providers/AppServiceProvider.php tests/Feature/Sistem/BackupHalamanTest.php tests/Feature/Layout/ErpWorkspaceMarkupTest.php
git commit -m "feat(backup): halaman Backup Sistem, admin only"
```

---

### Task 5: Dropdown topbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1294-1320`
- Test: `tests/Feature/Sistem/BackupHalamanTest.php` (tambah)

**Interfaces:**
- Consumes: route `sistem.backup.index` dari Task 4
- Produces: —

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Sistem/BackupHalamanTest.php`:

```php
    public function test_admin_melihat_tautan_backup_di_topbar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertSee(route('sistem.backup.index'), false);
    }

    public function test_bukan_admin_tidak_melihat_tautan_backup(): void
    {
        // Kenyamanan, bukan pengaman — pengamannya role:admin di route.
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertDontSee(route('sistem.backup.index'), false);
    }

    public function test_guest_tetap_melihat_penanda_mode_tamu(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)
            ->get(route('dashboard'))
            ->assertSee('MODE TAMU', false);
    }

    public function test_logout_tetap_form_post(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('dashboard'))
            ->assertSee('action="'.route('logout').'"', false);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Sistem/BackupHalamanTest.php --filter topbar`
Expected: FAIL — tautan backup tidak ada di halaman

- [ ] **Step 3: Ubah blok user jadi dropdown**

`resources/views/layouts/app.blade.php` — ganti blok `<div class="topbar-item user-block">…</div>` beserta `<form>` logout di bawahnya menjadi:

```blade
        <div class="topbar-item user-block" x-data="{ buka: false }" style="position:relative;cursor:pointer">
            <div @click="buka = ! buka" style="display:flex;align-items:center;gap:8px">
                <i class="bi bi-person-circle"></i>
                <div class="user-info">
                    <span class="name">{{ $u->name }}</span>
                    <span class="role">{{ str_replace('_', ' ', $u->role) }}</span>
                </div>
                <i class="bi bi-chevron-down" style="font-size:11px"></i>
            </div>

            <div x-show="buka" x-cloak @click.outside="buka = false"
                 style="position:absolute;right:0;top:calc(100% + 6px);min-width:190px;background:#fff;border:1px solid var(--border);border-radius:4px;box-shadow:0 6px 20px rgba(0,0,0,.14);z-index:1200">
                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    <i class="bi bi-person me-2"></i>Profil
                </a>
                @if($u->role === 'admin')
                <a href="{{ route('sistem.backup.index') }}" class="dropdown-item">
                    <i class="bi bi-download me-2"></i>Backup Sistem
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}" style="margin:0">
                    @csrf
                    <button type="submit" class="dropdown-item" style="width:100%;text-align:left;border:0;background:none">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </form>
            </div>
        </div>
```

Penanda "MODE TAMU — READ ONLY" untuk `guest` **tetap di tempatnya**, di luar dropdown.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Sistem/BackupHalamanTest.php`
Expected: PASS (15 test)

Run: `php artisan test tests/Feature/Layout/`
Expected: PASS — termasuk `GuestUiTest` dan `ChatbotCanvasTest` yang membaca layout ini

Run: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
Expected: tidak ada keluaran

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/Sistem/BackupHalamanTest.php
git commit -m "feat(backup): blok user topbar jadi dropdown berisi Profil, Backup, Logout"
```

---

### Task 6: Perintah artisan & jadwal harian

**Files:**
- Create: `app/Console/Commands/JalankanBackup.php`
- Create: `.github/workflows/backup.yml`
- Test: `tests/Feature/Sistem/BackupPerintahTest.php`

**Interfaces:**
- Consumes: `BackupService::jalankan(string $sumber, ?int $userId)` dari Task 2
- Produces: perintah artisan `backup:jalankan`

- [ ] **Step 1: Write the failing tests**

Buat `tests/Feature/Sistem/BackupPerintahTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Sistem/BackupPerintahTest.php`
Expected: FAIL — `The command "backup:jalankan" does not exist.`

- [ ] **Step 3: Tulis perintahnya**

`app/Console/Commands/JalankanBackup.php`:

```php
<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class JalankanBackup extends Command
{
    protected $signature = 'backup:jalankan';

    protected $description = 'Membuat satu arsip backup (dump database + berkas unggahan)';

    public function handle(BackupService $backup): int
    {
        try {
            $nama = $backup->jalankan('terjadwal', null);
            $this->info("Backup selesai: {$nama}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Kode keluar bukan nol supaya workflow-nya MERAH, bukan hijau
            // dengan pesan galat yang tidak dibaca siapa pun.
            $this->error('Backup gagal: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
```

- [ ] **Step 4: Buat workflow jadwalnya**

`.github/workflows/backup.yml`:

```yaml
name: Backup harian

# Memanggil artisan backup:jalankan di server lewat SSH, sejalur dengan
# deploy.yml dan debug-log.yml.
#
# ⚠️ Jadwal GitHub Actions tidak dijamin tepat waktu — bisa telat puluhan menit
# saat antrean padat — dan DINONAKTIFKAN OTOMATIS setelah 60 hari repositori
# tidak ada aktivitas. Ketelatan tidak masalah untuk backup harian;
# penonaktifan masalah, dan yang memberi tahu adalah kartu umur backup di
# halaman Backup Sistem, bukan orang yang kebetulan curiga.
on:
  schedule:
    - cron: '0 16 * * *'   # 16:00 UTC = 23:00 WIB, sesudah shift 2 selesai
  workflow_dispatch:

env:
  REMOTE_PATH: /home/u518638233/domains/optima.kencomanufactur.co.id/public_html
  REMOTE_PHP: /opt/alt/php84/usr/bin/php

jobs:
  backup:
    runs-on: ubuntu-latest
    steps:
      - name: Setup SSH key
        run: |
          mkdir -p ~/.ssh
          printf '%s\n' "${{ secrets.SSH_PRIVATE_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          ssh-keyscan -p "${{ secrets.SSH_PORT }}" "${{ secrets.SSH_HOST }}" >> ~/.ssh/known_hosts 2>/dev/null

      # Tanpa -e, kode keluar artisan tidak sampai ke step ini dan jadwal yang
      # gagal akan tampak hijau.
      - name: Jalankan backup
        run: |
          set -e
          ssh -p "${{ secrets.SSH_PORT }}" -i ~/.ssh/deploy_key \
            "${{ secrets.SSH_USERNAME }}@${{ secrets.SSH_HOST }}" \
            "cd ${{ env.REMOTE_PATH }} && ${{ env.REMOTE_PHP }} artisan backup:jalankan"
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Sistem/BackupPerintahTest.php`
Expected: PASS (2 test)

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/JalankanBackup.php .github/workflows/backup.yml tests/Feature/Sistem/BackupPerintahTest.php
git commit -m "feat(backup): perintah backup:jalankan + jadwal harian 23:00 WIB"
```

---

### Task 7: Gerbang akhir & PR

**Files:** seluruh suite

- [ ] **Step 1: Jalankan seluruh gerbang**

```bash
php artisan test
```
Expected: PASS — 889 test lama + 31 test baru, nol gagal

```bash
vendor/bin/pint app tests
```
Expected: `PASS`

```bash
grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/
```
Expected: tidak ada keluaran

```bash
./scripts/check-migration-coverage.sh
```
Expected: `✓ Cakupan migrasi lengkap` — **jumlahnya tidak berubah**, plan ini tidak menambah migrasi

- [ ] **Step 2: Buktikan penjaganya menangkap**

Kembalikan tiap bug sebentar, pastikan penjaganya jatuh, lalu kembalikan lagi:

- Buang `if (str_starts_with($relatif, self::DIREKTORI.'/')) { continue; }` di `rakitZip()` → `test_berkas_mengecualikan_direktori_backup` harus MERAH
- Ganti `pastikanNamaWajar()` jadi tubuh kosong → `test_nama_berkas_di_luar_pola_ditolak` harus MERAH
- Ganti `role:admin` jadi `role:admin,manager` di grup `sistem` → `test_hanya_admin_yang_boleh_membuka` harus MERAH untuk `manager`

Penjaga yang tidak pernah terlihat merah belum terbukti menjaga apa pun.

- [ ] **Step 3: Push dan buka PR**

```bash
git push -u origin feat/backup-sistem
```

Badan PR wajib memuat: tautan ke spec, hasil probe prod yang mendasari keputusannya, keempat risiko keamanan beserta penutupnya (sandi tak lewat argumen · `backups/` dikecualikan · nama berkas divalidasi · admin saja), dan pernyataan eksplisit bahwa **restore belum pernah diuji** — itu langkah manual sesudah deploy.

- [ ] **Step 4: Tunggu CI hijau sebelum merge**

```bash
gh pr checks <nomor>
```
Expected: `test pass`

---

## Verifikasi Manual Setelah Deploy

Yang tidak bisa dibuktikan suite, urut dari yang paling penting:

1. **Unduh satu arsip, buka ZIP-nya, dan restore `database.sql` ke database UJI** — bukan produksi. Ini satu-satunya bukti bahwa backupnya benar-benar backup. Sampai langkah ini dilakukan, yang dimiliki cuma berkas yang ukurannya masuk akal.
2. Periksa `manifest.json`: `jumlah_tabel` harus **31**, sesuai hasil probe.
3. Tekan Backup Now dua kali cepat → yang kedua menjawab "Backup sedang berjalan", bukan membuat arsip kedua.
4. Buka `/sistem/backup/optima-2020-01-01_0000.zip/unduh` untuk berkas yang tidak ada → 404, bukan galat server.
5. Masuk sebagai `manager` → tautan Backup Sistem tidak ada di dropdown, dan membuka `/sistem/backup` langsung memberi 403.
6. Jalankan workflow `Backup harian` lewat **Run workflow** manual sekali → hijau, dan arsip baru muncul di halaman dengan sumber `terjadwal`.
7. Sesudah 31 arsip terkumpul, pastikan yang tertua hilang sendiri.
