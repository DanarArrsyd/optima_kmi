# Tanggal Nonaktif Karyawan & Penyaringan Operator VCB — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mencatat kapan seorang karyawan dinonaktifkan, lalu memakai tanggal itu untuk menyaring dropdown Operator VCB Produksi dan Inspector VCB Quality mengikuti rentang tanggal yang sedang dilihat.

**Architecture:** Satu kolom `date` nullable di `master_karyawan`, ditulis hanya lewat tombol Non-Aktifkan dan dikosongkan setiap kali orangnya aktif kembali. Dropdown VCB tetap dirender server-side lengkap, masing-masing `<option>` membawa `data-nonaktif`, dan JS membangun ulang daftarnya tiap `date_from` berubah. Tidak ada endpoint baru dan tidak ada perubahan pada perhitungan.

**Tech Stack:** Laravel 13 · PHP 8.4 (CI/prod) / 8.5.4 (lokal) · MySQL prod, SQLite in-memory di test · Blade + Bootstrap 5.3.3 · PHPUnit lewat `php artisan test`

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-08-21-karyawan-tanggal-nonaktif-design.md`
- **Kolom baru:** `master_karyawan.tanggal_nonaktif`, `date`, nullable, `after('is_aktif')`
- **Aturan invarian:** kolom hanya berarti saat `is_aktif = false`; setiap jalur yang mengaktifkan kembali WAJIB menyetelnya ke `null`
- **NULL = selalu tampil** (fail-open). Jangan pernah memperlakukan NULL sebagai "sudah lama nonaktif"
- **Tiap migration baru WAJIB didaftarkan di `.github/workflows/deploy.yml`** step "Migrate (targeted)" pada PR yang sama. Lupa = deploy hijau + `Nothing to migrate` + kode live tanpa kolomnya
- **Kolom baru wajib di-guard `Schema::hasColumn`** di pembacanya — kode dan schema tidak dijamin tiba bersamaan
- **Dilarang SQL khusus MySQL** (`GREATEST`, `IF`, `TIME_TO_SEC`) — suite jalan di SQLite, fitur jadi mustahil diuji
- **Perbandingan tanggal di query pakai `whereDate`**, bukan `whereBetween` pada kolom `date`
- **Direktif Blade tidak boleh menempel karakter kata** (`Filter@if` → halaman 500). Sapuan: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
- **Asersi filter dijangkarkan** ke elemen yang diuji, jangan ke seluruh HTML — nama karyawan muncul di banyak tempat di halaman yang sama
- **Format akhir:** `vendor/bin/pint app tests` (scope Pint hanya `app/` + `tests/`)
- **Komponen JS bersama hanya boleh punya SATU definisi**, di `resources/views/layouts/app.blade.php`, dan didaftarkan di `KomponenBersamaTest`. Menyalinnya ke view dilarang — `taField()` pernah jadi 18 berkas dengan 5 varian yang sudah menyimpang
- Bahasa komentar, pesan flash, dan judul kolom: **Indonesia**, mengikuti berkas sekitarnya

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php` | Kolom |
| `database/migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php` | Isi data lama dari transaksi terakhir |
| `.github/workflows/deploy.yml` | Daftar `--path` kedua migration |
| `app/Models/MasterKaryawan.php` | `$fillable` + `$casts` |
| `app/Http/Controllers/Master/KaryawanController.php` | Tulis & kosongkan tanggal, validasi, kirim flag kolom ke view |
| `resources/views/master/karyawan/index.blade.php` | Laci tanggal di tombol Non-Aktifkan + kolom "Nonaktif Sejak" |
| `app/Imports/KaryawanImport.php` | Kosongkan tanggal saat import mengaktifkan orang |
| `resources/views/layouts/app.blade.php` | **Satu-satunya** definisi `saringOrangNonaktif()` |
| `resources/views/vcb/produksi.blade.php` | `data-nonaktif` di option + pemanggilan penyaring |
| `resources/views/vcb/quality.blade.php` | Sama, sisi Inspector |
| `tests/Feature/Layout/KomponenBersamaTest.php` | Daftarkan komponen bersama kelima |
| `tests/Feature/Master/KaryawanNonaktifTest.php` | Task 1, 3, 4, 5 |
| `tests/Feature/Master/BackfillTanggalNonaktifTest.php` | Task 2 |
| `tests/Feature/Vcb/VcbOperatorAktifTest.php` | Task 6, 7 |

**Catatan keterujian yang harus diterima sejak awal:** aturan penyaringan berjalan di JavaScript, dan suite ini PHPUnit. Yang bisa diuji dari PHP: tiap `<option>` membawa `data-nonaktif` yang benar, dan penyaringnya benar-benar dipanggil di halaman. Perilaku penyaringnya sendiri diuji manual. Jangan menulis asersi yang seolah menguji perilaku JS — itu asersi yang tidak pernah bisa gagal.

---

### Task 1: Kolom `tanggal_nonaktif`

**Files:**
- Create: `database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php`
- Modify: `app/Models/MasterKaryawan.php:16-18`
- Modify: `.github/workflows/deploy.yml:157`
- Test: `tests/Feature/Master/KaryawanNonaktifTest.php`

**Interfaces:**
- Consumes: —
- Produces: kolom `master_karyawan.tanggal_nonaktif`; atribut model `tanggal_nonaktif` yang ter-cast ke `Carbon`

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/Master/KaryawanNonaktifTest.php`:

```php
<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * tanggal_nonaktif hanya berarti saat is_aktif = false. Tiap jalur yang
 * mengaktifkan kembali seseorang wajib mengosongkannya — tanggal basi yang
 * menempel pada orang aktif akan menyaringnya keluar dari dropdown VCB tanpa
 * ada yang sadar.
 */
class KaryawanNonaktifTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_tanggal_nonaktif_tersimpan_dan_ter_cast(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N9',
            'nama_karyawan' => 'ORANG KELUAR',
            'departemen' => 'Produksi',
            'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $this->assertSame('2026-06-12', $k->fresh()->tanggal_nonaktif->toDateString());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: FAIL — `no such column: tanggal_nonaktif`

- [ ] **Step 3: Buat migration**

`database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->date('tanggal_nonaktif')->nullable()->after('is_aktif')
                ->comment('Tanggal karyawan dinonaktifkan; NULL = masih aktif atau tidak diketahui');
        });
    }

    public function down(): void
    {
        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->dropColumn('tanggal_nonaktif');
        });
    }
};
```

- [ ] **Step 4: Daftarkan model**

`app/Models/MasterKaryawan.php` — ganti dua baris ini:

```php
    protected $fillable = ['nik', 'nama_karyawan', 'departemen', 'is_aktif'];

    protected $casts = ['is_aktif' => 'boolean'];
```

menjadi:

```php
    protected $fillable = ['nik', 'nama_karyawan', 'departemen', 'is_aktif', 'tanggal_nonaktif'];

    protected $casts = ['is_aktif' => 'boolean', 'tanggal_nonaktif' => 'date'];
```

- [ ] **Step 5: Daftarkan migration di deploy.yml**

`.github/workflows/deploy.yml` — baris terakhir daftar `--path` saat ini berakhir dengan tanda kutip:

```
              --path=database/migrations/2026_08_04_000001_add_dihitung_availability_to_master_shift.php"
```

Ubah menjadi (tanda kutip pindah ke baris terakhir yang baru):

```
              --path=database/migrations/2026_08_04_000001_add_dihitung_availability_to_master_shift.php \
              --path=database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php"
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: PASS (1 test)

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap` — jumlah "dijalankan saat deploy" naik satu

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php app/Models/MasterKaryawan.php .github/workflows/deploy.yml tests/Feature/Master/KaryawanNonaktifTest.php
git commit -m "feat(karyawan): kolom tanggal_nonaktif di master_karyawan"
```

---

### Task 2: Backfill data lama

**Files:**
- Create: `database/migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php`
- Modify: `.github/workflows/deploy.yml` (baris `--path` baru)
- Test: `tests/Feature/Master/BackfillTanggalNonaktifTest.php`

**Interfaces:**
- Consumes: kolom dari Task 1
- Produces: `tanggal_nonaktif` terisi untuk karyawan nonaktif yang punya transaksi

Tanggal diambil dari yang **terbaru** di antara tiga tabel:

| Tabel | Kolom tanggal | Kolom karyawan |
|---|---|---|
| `transaksi_produksi` | `tanggal_produksi` | `id_karyawan` |
| `transaksi_qc` | `tgl_pengecekan` | `id_karyawan_qc` |
| `transaksi_dandori_rincian` | `tanggal` | `id_karyawan` |

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/Master/BackfillTanggalNonaktifTest.php`:

```php
<?php

namespace Tests\Feature\Master;

use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Backfill dijalankan langsung, bukan lewat RefreshDatabase: saat suite
 * memigrasi tabel masih kosong, jadi migrasinya harus dipanggil ulang setelah
 * data uji ada.
 */
class BackfillTanggalNonaktifTest extends TestCase
{
    use RefreshDatabase;

    private function jalankanBackfill(): void
    {
        $migration = require database_path('migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php');
        $migration->up();
    }

    public function test_mengambil_tanggal_transaksi_terbaru_dari_tiga_tabel(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N1', 'nama_karyawan' => 'ORANG SATU',
            'departemen' => 'Produksi', 'is_aktif' => false,
        ]);

        DB::table('transaksi_produksi')->insert([
            'tanggal_produksi' => '2026-05-01', 'id_karyawan' => $k->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 10,
        ]);
        DB::table('transaksi_dandori_rincian')->insert([
            'tanggal' => '2026-06-20', 'id_karyawan' => $k->id,
        ]);

        $this->jalankanBackfill();

        $this->assertSame('2026-06-20', $k->fresh()->tanggal_nonaktif->toDateString());
    }

    public function test_karyawan_aktif_tidak_disentuh(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N2', 'nama_karyawan' => 'ORANG DUA',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        DB::table('transaksi_produksi')->insert([
            'tanggal_produksi' => '2026-05-01', 'id_karyawan' => $k->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 10,
        ]);

        $this->jalankanBackfill();

        $this->assertNull($k->fresh()->tanggal_nonaktif);
    }

    public function test_nonaktif_tanpa_transaksi_tetap_null(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N3', 'nama_karyawan' => 'ORANG TIGA',
            'departemen' => 'Produksi', 'is_aktif' => false,
        ]);

        $this->jalankanBackfill();

        $this->assertNull($k->fresh()->tanggal_nonaktif);
    }

    public function test_tanggal_yang_sudah_terisi_tidak_ditimpa(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N4', 'nama_karyawan' => 'ORANG EMPAT',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-01-15',
        ]);

        DB::table('transaksi_produksi')->insert([
            'tanggal_produksi' => '2026-07-01', 'id_karyawan' => $k->id,
            'jam_start' => '08:00:00', 'jam_finish' => '16:00:00', 'qty_aktual' => 10,
        ]);

        $this->jalankanBackfill();

        $this->assertSame('2026-01-15', $k->fresh()->tanggal_nonaktif->toDateString());
    }
}
```

Catatan: kalau `transaksi_produksi` menolak insert karena kolom NOT NULL lain (mis. `id_shift`, `id_part_proses`), lengkapi payload-nya sesuai skema — jangan melonggarkan asersinya.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Master/BackfillTanggalNonaktifTest.php`
Expected: FAIL — `Failed to open stream: No such file or directory` pada `require`

- [ ] **Step 3: Buat migration backfill**

`database/migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Mengisi tanggal_nonaktif karyawan yang sudah terlanjur nonaktif sebelum kolom
 * ini ada, memakai tanggal transaksi terakhirnya — orang berhenti bekerja ya
 * berhenti menyetor LHP.
 *
 * Tiga max() terpisah lalu dibandingkan di PHP, BUKAN GREATEST() di SQL:
 * GREATEST tidak ada di SQLite dan akan membuat migrasi ini mustahil diuji.
 * Jumlah karyawan nonaktif puluhan, jadi tidak ada yang ditukar selain SQL yang
 * lebih pintar dari yang dibutuhkan.
 *
 * Yang tanggalnya SUDAH terisi tidak ditimpa, dan karyawan nonaktif tanpa satu
 * pun transaksi sengaja dibiarkan NULL — NULL berarti "selalu tampil", jauh
 * lebih murah daripada operator yang hilang diam-diam dari dropdown.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('master_karyawan', 'tanggal_nonaktif')) {
            return;
        }

        $sumber = [
            ['transaksi_produksi', 'tanggal_produksi', 'id_karyawan'],
            ['transaksi_qc', 'tgl_pengecekan', 'id_karyawan_qc'],
            ['transaksi_dandori_rincian', 'tanggal', 'id_karyawan'],
        ];

        DB::table('master_karyawan')
            ->where('is_aktif', false)
            ->whereNull('tanggal_nonaktif')
            ->orderBy('id')
            ->each(function ($karyawan) use ($sumber) {
                $terakhir = null;

                foreach ($sumber as [$tabel, $kolomTanggal, $kolomKaryawan]) {
                    if (! Schema::hasTable($tabel)) {
                        continue;
                    }

                    $maks = DB::table($tabel)
                        ->where($kolomKaryawan, $karyawan->id)
                        ->max($kolomTanggal);

                    if ($maks !== null && ($terakhir === null || $maks > $terakhir)) {
                        $terakhir = $maks;
                    }
                }

                if ($terakhir !== null) {
                    DB::table('master_karyawan')
                        ->where('id', $karyawan->id)
                        ->update(['tanggal_nonaktif' => substr((string) $terakhir, 0, 10)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('master_karyawan', 'tanggal_nonaktif')) {
            DB::table('master_karyawan')->update(['tanggal_nonaktif' => null]);
        }
    }
};
```

`substr(..., 0, 10)` disengaja: MySQL mengembalikan `2026-06-20` untuk kolom `date`, SQLite bisa mengembalikan `2026-06-20 00:00:00` kalau nilainya pernah ditulis dengan bagian jam. Memotongnya membuat kedua dialek menghasilkan nilai yang sama.

- [ ] **Step 4: Daftarkan di deploy.yml**

Tambahkan satu baris `--path` lagi, tetap menjaga tanda kutip di baris terakhir:

```
              --path=database/migrations/2026_08_21_000001_add_tanggal_nonaktif_to_master_karyawan.php \
              --path=database/migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php"
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test tests/Feature/Master/BackfillTanggalNonaktifTest.php`
Expected: PASS (4 test)

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap`

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_21_000002_backfill_tanggal_nonaktif_karyawan.php .github/workflows/deploy.yml tests/Feature/Master/BackfillTanggalNonaktifTest.php
git commit -m "feat(karyawan): backfill tanggal_nonaktif dari transaksi terakhir"
```

---

### Task 3: Controller menulis & mengosongkan tanggal

**Files:**
- Modify: `app/Http/Controllers/Master/KaryawanController.php:48-58`
- Test: `tests/Feature/Master/KaryawanNonaktifTest.php` (tambah)

**Interfaces:**
- Consumes: kolom + cast dari Task 1
- Produces: route `master.karyawan.toggle-aktif` (PATCH) menerima field opsional `tanggal_nonaktif`

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Master/KaryawanNonaktifTest.php` (dan tambahkan `use App\Models\User;` di bagian import):

```php
    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function karyawanAktif(): MasterKaryawan
    {
        return MasterKaryawan::create([
            'nik' => 'N1', 'nama_karyawan' => 'ORANG SATU',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
    }

    public function test_menonaktifkan_menyimpan_tanggal_yang_dikirim(): void
    {
        $k = $this->karyawanAktif();

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k), ['tanggal_nonaktif' => '2026-06-12'])
            ->assertRedirect();

        $k->refresh();
        $this->assertFalse($k->is_aktif);
        $this->assertSame('2026-06-12', $k->tanggal_nonaktif->toDateString());
    }

    public function test_mengaktifkan_kembali_mengosongkan_tanggal(): void
    {
        $k = MasterKaryawan::create([
            'nik' => 'N2', 'nama_karyawan' => 'ORANG DUA',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k))
            ->assertRedirect();

        $k->refresh();
        $this->assertTrue($k->is_aktif);
        $this->assertNull($k->tanggal_nonaktif);
    }

    public function test_tanggal_masa_depan_ditolak(): void
    {
        $k = $this->karyawanAktif();

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k), [
                'tanggal_nonaktif' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('tanggal_nonaktif');

        $this->assertTrue($k->fresh()->is_aktif);
    }

    public function test_tanpa_tanggal_jatuh_ke_hari_ini(): void
    {
        $k = $this->karyawanAktif();

        $this->actingAs($this->admin())
            ->patch(route('master.karyawan.toggle-aktif', $k))
            ->assertRedirect();

        $this->assertSame(today()->toDateString(), $k->fresh()->tanggal_nonaktif->toDateString());
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: FAIL — tanggal tidak tersimpan / tidak dikosongkan / validasi tidak ada

- [ ] **Step 3: Ubah controller**

`app/Http/Controllers/Master/KaryawanController.php` — ganti seluruh method `toggleAktif`:

```php
    public function toggleAktif(Request $request, MasterKaryawan $karyawan)
    {
        $data = $request->validate([
            'tanggal_nonaktif' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        try {
            if ($karyawan->is_aktif) {
                // Tanggal boleh dimundurkan: laporan karyawan keluar sering telat
                // berhari-hari, dan tanggal klik akan membuat dropdown VCB tetap
                // memunculkannya sepanjang selisih itu.
                $karyawan->update([
                    'is_aktif' => false,
                    'tanggal_nonaktif' => $data['tanggal_nonaktif'] ?? today()->toDateString(),
                ]);
                $status = 'dinonaktifkan';
            } else {
                // Aktif kembali WAJIB mengosongkan tanggal — tanggal basi pada orang
                // aktif akan menyaringnya keluar dari dropdown VCB tanpa ada yang sadar.
                $karyawan->update(['is_aktif' => true, 'tanggal_nonaktif' => null]);
                $status = 'diaktifkan kembali';
            }

            return redirect()->back()->with('success', "Karyawan {$karyawan->nama_karyawan} berhasil {$status}.");
        } catch (QueryException) {
            return redirect()->back()->with('error', 'Fitur ini memerlukan update database. Jalankan SQL ALTER TABLE di phpMyAdmin terlebih dahulu.');
        }
    }
```

Pastikan `use Illuminate\Http\Request;` sudah ada di berkas itu; kalau belum, tambahkan.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: PASS (5 test)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Master/KaryawanController.php tests/Feature/Master/KaryawanNonaktifTest.php
git commit -m "feat(karyawan): toggle nonaktif menyimpan tanggal, aktif kembali mengosongkannya"
```

---

### Task 4: Master Karyawan — laci tanggal & kolom "Nonaktif Sejak"

**Files:**
- Modify: `app/Http/Controllers/Master/KaryawanController.php` (method `index`)
- Modify: `resources/views/master/karyawan/index.blade.php:225-270`
- Test: `tests/Feature/Master/KaryawanNonaktifTest.php` (tambah)

**Interfaces:**
- Consumes: `toggleAktif` dari Task 3
- Produces: view menerima `$adaKolomTanggal` (bool)

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Master/KaryawanNonaktifTest.php`:

```php
    /** Ambil isi grid saja — nama karyawan juga muncul di dock filter dan laci. */
    private function grid(string $html): string
    {
        $awal = strpos($html, 'class="erp-scroll');
        $this->assertNotFalse($awal, 'Wadah .erp-scroll tidak ditemukan.');

        return substr($html, $awal, strpos($html, '</table>', $awal) - $awal);
    }

    public function test_kolom_nonaktif_sejak_tampil_di_tabel(): void
    {
        MasterKaryawan::create([
            'nik' => 'N7', 'nama_karyawan' => 'ORANG TUJUH',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('master.karyawan.index'))->assertOk()->getContent();

        $grid = $this->grid($html);
        $this->assertStringContainsString('Nonaktif Sejak', $grid);
        $this->assertStringContainsString('12/06/2026', $grid);
    }

    public function test_karyawan_aktif_menampilkan_strip(): void
    {
        MasterKaryawan::create([
            'nik' => 'N8', 'nama_karyawan' => 'ORANG DELAPAN',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $html = $this->actingAs($this->admin())
            ->get(route('master.karyawan.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('12/06/2026', $this->grid($html));
    }

    public function test_form_nonaktif_menyediakan_input_tanggal(): void
    {
        $this->karyawanAktif();

        $html = $this->actingAs($this->admin())
            ->get(route('master.karyawan.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="tanggal_nonaktif"', $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: FAIL — "Nonaktif Sejak" tidak ada di grid

- [ ] **Step 3: Kirim flag kolom dari controller**

`app/Http/Controllers/Master/KaryawanController.php`, di method `index`, tepat sebelum `return view(...)`:

```php
        // Kode bisa live sebelum migrasi menyusul — halaman tetap harus hidup.
        $adaKolomTanggal = Schema::hasColumn('master_karyawan', 'tanggal_nonaktif');
```

lalu ubah baris `return`-nya menjadi:

```php
        return view('master.karyawan.index', compact('karyawan', 'filter', 'search', 'filterDept', 'deptList', 'adaKolomTanggal'));
```

Tambahkan import `use Illuminate\Support\Facades\Schema;` kalau belum ada.

- [ ] **Step 4: Tambah kolom tabel di view**

`resources/views/master/karyawan/index.blade.php` — di `<thead>`, setelah `<th>` Status:

```blade
                @if($adaKolomTanggal)
                <th style="width:120px;white-space:nowrap">Nonaktif Sejak</th>
                @endif
```

Di `<tbody>`, setelah `<td>` Status:

```blade
                @if($adaKolomTanggal)
                <td style="white-space:nowrap">
                    {{ $k->tanggal_nonaktif ? $k->tanggal_nonaktif->format('d/m/Y') : '—' }}
                </td>
                @endif
```

⚠️ Jaga spasi sebelum tiap `@if`/`@endif`. Direktif Blade yang menempel karakter kata tidak dikompilasi, tapi `@endif`-nya iya — hasilnya halaman 500 dengan `unexpected token "endif"`.

- [ ] **Step 5: Ganti confirm() dengan laci tanggal**

Di kolom Aksi, ganti seluruh blok `<form method="POST" action="{{ route('master.karyawan.toggle-aktif', $k) }}" onsubmit="return confirm(...)"> … </form>` menjadi:

```blade
                        <form method="POST" action="{{ route('master.karyawan.toggle-aktif', $k) }}"
                              x-data="{ buka: false }" style="display:inline-block">
                            @csrf @method('PATCH')
                            @if($k->is_aktif)
                                <button type="button" class="btn btn-sm"
                                        style="background:#4b5563;color:#fff;border:none"
                                        x-on:click="buka = ! buka">
                                    <i class="bi bi-person-slash me-1"></i>Non-Aktifkan
                                </button>
                                <span x-show="buka" x-cloak style="display:inline-flex;gap:4px;align-items:center;margin-left:4px">
                                    <input type="date" name="tanggal_nonaktif" class="erp-in"
                                           value="{{ date('Y-m-d') }}" max="{{ date('Y-m-d') }}"
                                           style="width:140px">
                                    <button type="submit" class="btn btn-sm btn-danger">Simpan</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            x-on:click="buka = false">Batal</button>
                                </span>
                            @else
                                <button type="submit" class="btn btn-sm btn-success"
                                        onclick="return confirm('Aktifkan kembali karyawan {{ addslashes($k->nama_karyawan) }}? Tanggal nonaktifnya akan dikosongkan.')">
                                    <i class="bi bi-person-check me-1"></i>Aktifkan
                                </button>
                            @endif
                        </form>
```

`max="{{ date('Y-m-d') }}"` menutup tanggal masa depan di peramban; validasi `before_or_equal:today` di Task 3 tetap penjaga sebenarnya.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: PASS (8 test)

Run: `php artisan test tests/Feature/Layout/ErpWorkspaceMarkupTest.php`
Expected: PASS — susunan `.erp-ws` halaman ini tidak rusak

Run: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
Expected: tidak ada keluaran

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Master/KaryawanController.php resources/views/master/karyawan/index.blade.php tests/Feature/Master/KaryawanNonaktifTest.php
git commit -m "feat(karyawan): laci tanggal saat menonaktifkan + kolom Nonaktif Sejak"
```

---

### Task 5: Import tidak boleh meninggalkan tanggal basi

**Files:**
- Modify: `app/Imports/KaryawanImport.php:32`
- Test: `tests/Feature/Master/KaryawanNonaktifTest.php` (tambah)

**Interfaces:**
- Consumes: kolom dari Task 1
- Produces: —

- [ ] **Step 1: Write the failing test**

Tambahkan ke `tests/Feature/Master/KaryawanNonaktifTest.php` (tambahkan `use App\Imports\KaryawanImport;` dan `use Illuminate\Support\Collection;` di import):

```php
    public function test_import_mengaktifkan_sekaligus_mengosongkan_tanggal(): void
    {
        MasterKaryawan::create([
            'nik' => 'N5', 'nama_karyawan' => 'ORANG LIMA',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        (new KaryawanImport)->collection(new Collection([
            new Collection(['nik' => 'N5', 'nama_karyawan' => 'ORANG LIMA', 'departemen' => 'Produksi']),
        ]));

        $k = MasterKaryawan::where('nama_karyawan', 'ORANG LIMA')->first();
        $this->assertTrue($k->is_aktif);
        $this->assertNull($k->tanggal_nonaktif);
    }
```

Kalau `KaryawanImport` mengharapkan tiap baris berupa array biasa dan bukan `Collection`, sesuaikan pembungkusnya — yang tidak boleh berubah adalah asersinya.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php --filter import_mengaktifkan`
Expected: FAIL — `tanggal_nonaktif` masih `2026-06-12` padahal orangnya sudah aktif

- [ ] **Step 3: Tutup celahnya**

`app/Imports/KaryawanImport.php` — setelah baris `$record->is_aktif = true;`, sisipkan:

```php
            $record->tanggal_nonaktif = null;   // aktif tidak boleh menyimpan tanggal nonaktif
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Master/KaryawanNonaktifTest.php`
Expected: PASS (9 test)

- [ ] **Step 5: Commit**

```bash
git add app/Imports/KaryawanImport.php tests/Feature/Master/KaryawanNonaktifTest.php
git commit -m "fix(import): import karyawan mengosongkan tanggal_nonaktif saat mengaktifkan"
```

---

### Task 6: Komponen penyaring bersama + VCB Produksi

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (blok `<script>` komponen bersama)
- Modify: `resources/views/vcb/produksi.blade.php` (select operator, drill-down, handler bulan)
- Modify: `tests/Feature/Layout/KomponenBersamaTest.php:20-25`
- Test: `tests/Feature/Vcb/VcbOperatorAktifTest.php`

**Interfaces:**
- Consumes: kolom dari Task 1, backfill dari Task 2
- Produces: `saringOrangNonaktif(selectId, dateFromId, noteId)` → mengembalikan fungsi `saring()` tanpa argumen. Task 7 memanggil fungsi yang sama.

Controller **tidak diubah** — `index()` sudah memuat seluruh karyawan produksi dan `->get()` membawa kolom baru apa adanya.

Komponennya **didefinisikan sekali di layout**, seperti `taField`, `filterSearchComp`, `partGroupComp`, dan `erpToggleDock`. Halaman menyediakan DATANYA (`data-nonaktif` di tiap `<option>`), bukan definisi fungsinya.

- [ ] **Step 1: Write the failing tests**

Buat `tests/Feature/Vcb/VcbOperatorAktifTest.php`:

```php
<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan penyaringannya sendiri berjalan di JavaScript dan tidak bisa dijalankan
 * dari PHPUnit. Yang diuji di sini: tiap <option> membawa data-nonaktif yang
 * benar, dan penyaringnya benar-benar dipanggil di halaman. Jangan menulis
 * asersi yang seolah menguji perilaku JS — itu asersi yang tak pernah bisa gagal.
 */
class VcbOperatorAktifTest extends TestCase
{
    use RefreshDatabase;

    /** Ambil isi <select> operator saja, bukan seluruh halaman. */
    private function selectOperator(string $html): string
    {
        $awal = strpos($html, 'id="one_karyawan_id"');
        $this->assertNotFalse($awal, 'Select operator tidak ditemukan.');

        return substr($html, $awal, strpos($html, '</select>', $awal) - $awal);
    }

    private function html(string $route): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
    }

    public function test_operator_aktif_membawa_data_nonaktif_kosong(): void
    {
        MasterKaryawan::create([
            'nik' => 'P1', 'nama_karyawan' => 'OPERATOR AKTIF',
            'departemen' => 'Produksi', 'is_aktif' => true,
        ]);

        $select = $this->selectOperator($this->html('vcb.produksi'));

        $this->assertStringContainsString('OPERATOR AKTIF', $select);
        $this->assertStringContainsString('data-nonaktif=""', $select);
    }

    public function test_operator_nonaktif_membawa_tanggalnya(): void
    {
        MasterKaryawan::create([
            'nik' => 'P2', 'nama_karyawan' => 'OPERATOR KELUAR',
            'departemen' => 'Produksi', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-06-12',
        ]);

        $select = $this->selectOperator($this->html('vcb.produksi'));

        $this->assertStringContainsString('data-nonaktif="2026-06-12"', $select);
    }

    public function test_penyaring_dipanggil_di_halaman_produksi(): void
    {
        $html = $this->html('vcb.produksi');

        $this->assertStringContainsString("saringOrangNonaktif('one_karyawan_id'", $html);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Vcb/VcbOperatorAktifTest.php`
Expected: FAIL — `data-nonaktif` tidak ada di `<select>`

- [ ] **Step 3: Definisikan komponen di layout**

`resources/views/layouts/app.blade.php` — di dalam blok `<script>` tempat `taField`, `filterSearchComp`, `partGroupComp`, dan `erpToggleDock` sudah didefinisikan, tambahkan:

```javascript
/**
 * Menyaring daftar orang di sebuah <select> mengikuti tanggal mulai yang dipilih.
 *
 * Halaman menyediakan datanya: tiap <option> membawa data-nonaktif="YYYY-MM-DD"
 * (kosong = masih aktif). Kembaliannya fungsi tanpa argumen yang membangun ulang
 * <select>-nya. Membangun ulang, BUKAN display:none pada <option> — perilaku
 * display:none di <option> tidak seragam antar peramban.
 *
 * Daftar lengkapnya di-snapshot sekali saat dipasang, karena pembangunan ulang
 * yang pertama akan menghapus sumbernya dari DOM.
 */
function saringOrangNonaktif(selectId, dateFromId, noteId) {
    const sel = document.getElementById(selectId);
    const semua = Array.from(sel.querySelectorAll('option'))
        .filter(o => o.value !== '')
        .map(o => ({ id: o.value, nama: o.textContent, nonaktif: o.dataset.nonaktif || '' }));
    const placeholder = sel.querySelector('option[value=""]');
    const placeholderHtml = placeholder ? placeholder.outerHTML : '';

    return function saring() {
        const dari     = document.getElementById(dateFromId).value;
        const terpilih = sel.value;
        const note     = document.getElementById(noteId);

        // Tanggal ISO aman dibandingkan sebagai string.
        // nonaktif kosong = selalu tampil: kolom kosong tidak boleh menghilangkan orang.
        const lolos = semua.filter(o => !o.nonaktif || o.nonaktif >= dari);

        sel.innerHTML = placeholderHtml;
        lolos.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.id;
            opt.textContent = o.nama;
            opt.dataset.nonaktif = o.nonaktif;
            sel.appendChild(opt);
        });

        if (terpilih && lolos.some(o => o.id === terpilih)) {
            sel.value = terpilih;
            if (note) note.textContent = '';

            return;
        }

        if (terpilih) {
            // Mengosongkan diam-diam akan membuat grafik menampilkan orang lain
            // tanpa penjelasan.
            const hilang = semua.find(o => o.id === terpilih);
            if (note) note.textContent = hilang ? `${hilang.nama} tidak aktif pada periode ini.` : '';
            sel.value = '';
        }
    };
}
```

- [ ] **Step 4: Daftarkan sebagai komponen bersama kelima**

`tests/Feature/Layout/KomponenBersamaTest.php` — tambahkan satu baris ke konstanta `KOMPONEN`:

```php
    private const KOMPONEN = [
        'function taField(',
        'function filterSearchComp(',
        'function partGroupComp(',
        'function erpToggleDock(',
        'function saringOrangNonaktif(',
    ];
```

- [ ] **Step 5: Tempelkan data-nonaktif ke tiap option**

`resources/views/vcb/produksi.blade.php` — ganti blok `@foreach` di dalam `<select id="one_karyawan_id">`:

```blade
                    @foreach($karyawan as $k)
                        <option value="{{ $k->id }}"
                                data-nonaktif="{{ $k->tanggal_nonaktif ? $k->tanggal_nonaktif->format('Y-m-d') : '' }}">{{ $k->nama_karyawan }}</option>
                    @endforeach
```

- [ ] **Step 6: Tambahkan tempat pesannya**

Tepat setelah `</select>` milik `one_karyawan_id`, di dalam `.erp-grp` yang sama:

```blade
                <small id="one_operator_note" style="color:#b91c1c;font-size:10px"></small>
```

- [ ] **Step 7: Pasang penyaringnya**

Di `@push('scripts')` halaman itu, sebelum `loadOneOperator()` didefinisikan:

```javascript
const saringOperator = saringOrangNonaktif('one_karyawan_id', 'one_date_from', 'one_operator_note');

document.getElementById('one_date_from').addEventListener('change', saringOperator);
saringOperator();   // tampilan pertama harus sudah bersih, bukan setelah tanggal disentuh
```

- [ ] **Step 8: Sambungkan ke dua jalur lain yang mengubah tanggal**

`applyBulanFilter` menyetel `.value` secara programatis, dan itu **tidak** memicu event `change`. Ganti pemanggilnya:

```javascript
document.getElementById('one_bulan_filter').addEventListener('change', () =>
    applyBulanFilter('one_bulan_filter', 'one_date_from', 'one_date_to', () => { saringOperator(); loadOneOperator(); }));
```

Jalur drill-down dari grafik menyetel operator SEBELUM tanggal, sehingga daftarnya masih daftar lama. Urutkan ulang menjadi tanggal → saring → operator:

```javascript
        document.getElementById('one_date_from').value    = document.getElementById('all_date_from').value;
        document.getElementById('one_date_to').value      = document.getElementById('all_date_to').value;
        saringOperator();
        document.getElementById('one_karyawan_id').value  = karyawanId;
```

- [ ] **Step 9: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Vcb/VcbOperatorAktifTest.php`
Expected: PASS (3 test)

Run: `php artisan test tests/Feature/Layout/KomponenBersamaTest.php`
Expected: PASS (5 test) — komponen kelima ada di layout, nol penyalin

Run: `php artisan test tests/Feature/Vcb/`
Expected: PASS — termasuk `VcbAchievedCounterTest` yang juga membaca halaman ini

- [ ] **Step 10: Commit**

```bash
git add resources/views/layouts/app.blade.php resources/views/vcb/produksi.blade.php tests/Feature/Layout/KomponenBersamaTest.php tests/Feature/Vcb/VcbOperatorAktifTest.php
git commit -m "feat(vcb): dropdown operator menyaring ikut rentang tanggal"
```

---

### Task 7: Dropdown Inspector VCB Quality

**Files:**
- Modify: `resources/views/vcb/quality.blade.php` (select inspector, drill-down, handler bulan)
- Test: `tests/Feature/Vcb/VcbOperatorAktifTest.php` (tambah)

**Interfaces:**
- Consumes: `saringOrangNonaktif(selectId, dateFromId, noteId)` dari Task 6 — sudah ada di layout, **jangan didefinisikan ulang di view mana pun**
- Produces: —

Bug kembar. Repo ini sudah berkali-kali membetulkan satu tempat dan meninggalkan pasangannya — terakhir pada penghitung achieved (PR #95). Id elemennya sama persis dengan halaman produksi (`one_karyawan_id`, `one_date_from`, `one_bulan_filter`); yang berbeda hanya nama fungsi pemuatnya, `loadOneInspector()`.

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Vcb/VcbOperatorAktifTest.php`:

```php
    public function test_inspector_nonaktif_membawa_tanggalnya(): void
    {
        MasterKaryawan::create([
            'nik' => 'Q1', 'nama_karyawan' => 'INSPEKTOR KELUAR',
            'departemen' => 'QC', 'is_aktif' => false,
            'tanggal_nonaktif' => '2026-05-30',
        ]);

        $select = $this->selectOperator($this->html('vcb.quality'));

        $this->assertStringContainsString('data-nonaktif="2026-05-30"', $select);
    }

    public function test_penyaring_dipanggil_di_halaman_quality(): void
    {
        $html = $this->html('vcb.quality');

        $this->assertStringContainsString("saringOrangNonaktif('one_karyawan_id'", $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Vcb/VcbOperatorAktifTest.php`
Expected: FAIL — dua test baru gagal, tiga test Task 6 tetap hijau

- [ ] **Step 3: Tempelkan data-nonaktif ke tiap option**

`resources/views/vcb/quality.blade.php` — ganti blok `@foreach` di dalam `<select id="one_karyawan_id">`:

```blade
                    @foreach($karyawan as $k)
                        <option value="{{ $k->id }}"
                                data-nonaktif="{{ $k->tanggal_nonaktif ? $k->tanggal_nonaktif->format('Y-m-d') : '' }}">{{ $k->nama_karyawan }}</option>
                    @endforeach
```

- [ ] **Step 4: Tambahkan tempat pesannya**

Tepat setelah `</select>` milik `one_karyawan_id`, di dalam `.erp-grp` yang sama:

```blade
                <small id="one_operator_note" style="color:#b91c1c;font-size:10px"></small>
```

- [ ] **Step 5: Pasang penyaringnya**

Di `@push('scripts')` halaman itu, sebelum `loadOneInspector()` didefinisikan:

```javascript
const saringOperator = saringOrangNonaktif('one_karyawan_id', 'one_date_from', 'one_operator_note');

document.getElementById('one_date_from').addEventListener('change', saringOperator);
saringOperator();   // tampilan pertama harus sudah bersih, bukan setelah tanggal disentuh
```

- [ ] **Step 6: Sambungkan ke dua jalur lain yang mengubah tanggal**

Callback bulan — perhatikan namanya `loadOneInspector`, bukan `loadOneOperator`:

```javascript
document.getElementById('one_bulan_filter').addEventListener('change', () =>
    applyBulanFilter('one_bulan_filter', 'one_date_from', 'one_date_to', () => { saringOperator(); loadOneInspector(); }));
```

Jalur drill-down dari grafik (sekitar baris 295) menyetel inspector SEBELUM tanggal. Urutkan ulang menjadi tanggal → saring → inspector:

```javascript
        document.getElementById('one_date_from').value   = document.getElementById('all_date_from').value;
        document.getElementById('one_date_to').value     = document.getElementById('all_date_to').value;
        saringOperator();
        document.getElementById('one_karyawan_id').value = karyawanId;
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Vcb/VcbOperatorAktifTest.php`
Expected: PASS (5 test)

Run: `php artisan test tests/Feature/Layout/KomponenBersamaTest.php`
Expected: PASS — nol penyalin `saringOrangNonaktif` di berkas view

- [ ] **Step 8: Commit**

```bash
git add resources/views/vcb/quality.blade.php tests/Feature/Vcb/VcbOperatorAktifTest.php
git commit -m "feat(vcb): dropdown inspector quality menyaring ikut rentang tanggal"
```

---

### Task 8: Gerbang akhir & PR

**Files:**
- Test: seluruh suite

- [ ] **Step 1: Jalankan seluruh gerbang**

```bash
php artisan test
```
Expected: PASS — 862 test lama + 18 test baru, nol gagal

```bash
./scripts/check-migration-coverage.sh
```
Expected: `✓ Cakupan migrasi lengkap` dengan **14** migrasi dijalankan saat deploy (naik dua dari 12)

```bash
vendor/bin/pint app tests
```
Expected: `PASS`

```bash
grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/
```
Expected: tidak ada keluaran

- [ ] **Step 2: Buktikan penjaganya menangkap**

Kembalikan satu bug sebentar, pastikan penjaganya jatuh, lalu kembalikan lagi:

- Hapus `$record->tanggal_nonaktif = null;` di `KaryawanImport` → `test_import_mengaktifkan_sekaligus_mengosongkan_tanggal` harus MERAH
- Hapus `data-nonaktif` di `vcb/produksi.blade.php` → `test_operator_nonaktif_membawa_tanggalnya` harus MERAH

Penjaga yang tidak pernah terlihat merah belum terbukti menjaga apa pun.

- [ ] **Step 3: Push dan buka PR**

```bash
git push -u origin feat/karyawan-tanggal-nonaktif
```

Badan PR wajib memuat:
- Tautan ke spec
- Tabel keputusan (batas ikut rentang tanggal · backfill dari transaksi terakhir · tanggal diminta saat menonaktifkan · Quality ikut)
- **Kedua migration beserta baris `deploy.yml`-nya** — bagian ini yang harus diperiksa reviewer paling dulu
- Catatan bahwa perilaku penyaringan berjalan di JS dan diverifikasi manual, bukan oleh suite

- [ ] **Step 4: Tunggu CI hijau sebelum merge**

```bash
gh pr checks <nomor>
```
Expected: `test pass`

---

## Verifikasi Manual Setelah Deploy

Yang tidak bisa dibuktikan suite:

1. Master Karyawan → Non-Aktifkan → laci tanggal muncul terisi hari ini, tidak bisa dimajukan
2. Kolom "Nonaktif Sejak" terisi untuk yang nonaktif, `—` untuk yang aktif
3. VCB Produksi → set Dari Tanggal ke bulan **setelah** seorang operator nonaktif → namanya hilang dari dropdown
4. Mundurkan Dari Tanggal ke sebelum tanggal nonaktifnya → namanya muncul lagi, drill-down-nya utuh
5. Pilih operator, lalu majukan tanggal sampai dia keluar rentang → pilihan kosong + pesan "tidak aktif pada periode ini", bukan diam-diam berganti orang
6. Ulangi butir 3–5 di VCB Quality
7. Import master karyawan berisi orang yang sedang nonaktif → dia aktif kembali **dan** kolom Nonaktif Sejak kembali `—`
