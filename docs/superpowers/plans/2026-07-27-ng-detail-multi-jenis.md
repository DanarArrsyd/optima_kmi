# Fondasi Data NG Multi-Jenis — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Membuat satu sesi produksi/pengecekan bisa mencatat beberapa jenis NG beserta qty masing-masing, tanpa merusak 16 file yang membaca `qty_ng`.

**Architecture:** Dua tabel detail terpisah (`transaksi_produksi_ng`, `transaksi_qc_ng`) dengan FK asli ke induk dan ke `master_jenis_ng`. `qty_ng` di tabel induk dipertahankan sebagai angka turunan `SUM(detail)`, ditulis hanya oleh `NgDetailService`. Form memakai baris berulang Alpine mengikuti pola form Dandori.

**Tech Stack:** Laravel 13, PHP 8.5.4, MySQL (prod) / SQLite in-memory (test), Blade + Bootstrap 5.3.3 + Alpine.js 3.14.1, maatwebsite/excel 4.x.

**Spec:** `docs/superpowers/specs/2026-07-27-ng-detail-multi-jenis-design.md`

## Global Constraints

- **Setiap migrasi baru WAJIB didaftarkan di `.github/workflows/deploy.yml`** pada langkah "Migrate (targeted) di server", di PR yang sama. Lupa = gagal senyap: deploy hijau, `Nothing to migrate`, kode live tanpa tabelnya.
- **Migrasi baru TIDAK BOLEH ditaruh di `database/migrations/.deploy-baseline`.**
- Semua pembacaan tabel detail dibungkus `Schema::hasTable(...)` — kode dan skema tidak dijamin tiba bersamaan di produksi.
- Tabel detail **tanpa** `SoftDeletes` (penyimpangan sengaja dari konvensi repo — lihat spec).
- Invarian yang harus dijaga di seluruh task: **`qty_ng = SUM(detail.qty)` untuk setiap baris yang disimpan setelah rilis.**
- `qty_ng` tidak boleh pernah menjadi 0 secara diam-diam ketika nilai sebelumnya > 0.
- Format Excel import **tidak berubah**.
- Pint hanya men-scope `app/` dan `tests/`: `vendor/bin/pint app tests`.
- Test suite: `php artisan test` (SQLite in-memory, tidak butuh DB lokal).
- Timestamp migrasi memakai prefiks `2026_07_28_*` (migrasi terakhir yang ada: `2026_07_27_000001`).

---

## File Structure

**Dibuat:**

| File | Tanggung jawab |
|---|---|
| `database/migrations/2026_07_28_000001_add_kategori_to_master_jenis_ng.php` | kolom `kategori` nullable |
| `database/migrations/2026_07_28_000002_create_transaksi_produksi_ng_table.php` | tabel detail produksi |
| `database/migrations/2026_07_28_000003_create_transaksi_qc_ng_table.php` | tabel detail QC |
| `database/migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php` | pindah data lama |
| `app/Models/Concerns/NgDetailRow.php` | trait bersama dua model detail |
| `app/Models/TransaksiProduksiNg.php` | model detail produksi |
| `app/Models/TransaksiQcNg.php` | model detail QC |
| `app/Services/NgDetailService.php` | satu-satunya penulis detail + `qty_ng` |
| `resources/views/input/partials/ng-rows.blade.php` | komponen baris NG, dipakai 4 form |
| `tests/Unit/NgDetailServiceTest.php` | unit test service |
| `tests/Feature/Input/NgMultiJenisProduksiTest.php` | feature test form produksi |
| `tests/Feature/Input/NgMultiJenisQcTest.php` | feature test form QC |
| `tests/Feature/Input/NgLegacyMigrationTest.php` | test migrasi data lama + baris legacy |

**Diubah:**

| File | Perubahan |
|---|---|
| `app/Models/TransaksiProduksi.php` | relasi `ngDetails()` |
| `app/Models/TransaksiQc.php` | relasi `ngDetails()` |
| `app/Http/Requests/StoreProduksiRequest.php` | aturan `ng.*`, buang `qty_ng`/`id_jenis_ng` |
| `app/Http/Requests/UpdateProduksiRequest.php` | idem + guard baris legacy |
| `app/Http/Requests/StoreQcRequest.php` | aturan `ng.*`, total dari baris |
| `app/Http/Requests/UpdateQcRequest.php` | idem + guard baris legacy |
| `app/Http/Controllers/Input/ProduksiController.php` | panggil `NgDetailService` |
| `app/Http/Controllers/Input/QcController.php` | panggil `NgDetailService` |
| `resources/views/input/produksi/create.blade.php` | ganti blok NG |
| `resources/views/input/produksi/edit.blade.php` | ganti blok NG + prefill legacy |
| `resources/views/input/qc/create.blade.php` | ganti blok NG |
| `resources/views/input/qc/edit.blade.php` | ganti blok NG + prefill legacy |
| `app/Imports/ProduksiImport.php` | tulis lewat service |
| `app/Imports/QcImport.php` | tulis lewat service |
| `app/Exports/LhpProduksiExport.php` | kolom jenis NG jadi gabungan |
| `app/Exports/LhpQcExport.php` | idem |
| `.github/workflows/deploy.yml` | daftarkan 4 migrasi |

---

## Task 0: Probe & deduplikasi `master_jenis_ng` (MANUAL — kerjakan lebih dulu)

**Files:**
- Jalankan: `docs/probes/ng-data-audit.sql` (read-only, tidak mengubah apa pun)
- Ubah data (bukan kode): baris `master_jenis_ng` di produksi lewat menu Master > Jenis NG

**Interfaces:**
- Consumes: —
- Produces: angka sebaran data lama untuk label peringatan Tahap 2; daftar jenis NG yang bersih sebelum tabel detail terisi.

Task ini **tidak menghasilkan commit kode.** Dikerjakan manusia, bukan agen, karena penggabungan baris master menyentuh data produksi dan keputusan "dua nama ini sebenarnya satu masalah" butuh orang yang paham lapangan.

- [ ] **Step 1: Jalankan probe**

```bash
mysql -u <user> -p <nama_db> < docs/probes/ng-data-audit.sql
```

Expected: tujuh blok hasil. Yang wajib dicatat dan dibawa ke Tahap 2:
- Q1 → `pct_tanpa_jenis` per sumber (angka untuk label peringatan dashboard)
- Q5 → daftar kandidat duplikat nama
- Q7 → semua kolom harus `0`; bila bukan nol, ada jalur input bocor yang perlu dibereskan lebih dulu

- [ ] **Step 2: Rapikan duplikat dari hasil Q5**

Untuk tiap kelompok duplikat, pilih satu baris sebagai kanonik. Baris lain **jangan dihapus dulu** — transaksi lama masih menunjuk ke sana lewat `id_jenis_ng`, dan Task 9 akan memigrasikannya ke tabel detail memakai id tersebut.

Urutan aman:
1. Rapikan penamaan (huruf besar/kecil, ejaan) supaya kanonik terbaca jelas.
2. Isi kolom `kategori` (tersedia setelah Task 1) — beri kategori yang sama untuk anggota satu kelompok.
3. Penggabungan id (mengarahkan transaksi lama ke satu id) dikerjakan **setelah Tahap 1 live dan stabil**, sebagai pekerjaan terpisah dengan migrasi datanya sendiri.

Alasan penundaan: mengubah `id_jenis_ng` pada transaksi lama adalah penulisan massal ke data produksi. Menggabungkannya di tengah rangkaian ini mencampur dua sumber risiko, dan bila hasilnya salah tidak akan ada error — hanya angka Pareto yang diam-diam keliru.

- [ ] **Step 3: Nonaktifkan jenis NG yang tak pernah terpakai (opsional)**

Dari hasil Q6. Soft-delete lewat menu Master > Jenis NG agar tidak muncul di dropdown operator. Aman: `restrictOnDelete` tidak terpicu karena penghapusan lewat UI bersifat soft.

- [ ] **Step 4: Catat angka Q1 untuk Tahap 2**

Simpan `pct_tanpa_jenis` per sumber di catatan tim. Angka ini yang akan ditulis di label peringatan dashboard Tahap 2 — "N% NG sebelum <bulan rilis> tidak tercatat jenisnya" — supaya pembaca tahu persis seberapa jauh boleh percaya tren historis.

---

## Task 1: Kolom `kategori` di `master_jenis_ng`

**Files:**
- Create: `database/migrations/2026_07_28_000001_add_kategori_to_master_jenis_ng.php`
- Modify: `app/Models/MasterJenisNg.php`
- Modify: `.github/workflows/deploy.yml:152`
- Test: `tests/Unit/MasterJenisNgKategoriTest.php`

**Interfaces:**
- Consumes: —
- Produces: kolom `master_jenis_ng.kategori` (string 50, nullable). `MasterJenisNg::$fillable` memuat `'kategori'`.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/MasterJenisNgKategoriTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MasterJenisNgKategoriTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_kategori_ada_dan_bisa_diisi(): void
    {
        $this->assertTrue(Schema::hasColumn('master_jenis_ng', 'kategori'));

        $jenis = MasterJenisNg::create([
            'kode' => 'DNT',
            'nama_ng' => 'Dented',
            'kategori' => 'visual',
        ]);

        $this->assertSame('visual', $jenis->fresh()->kategori);
    }

    public function test_kategori_boleh_kosong(): void
    {
        $jenis = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        $this->assertNull($jenis->fresh()->kategori);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=MasterJenisNgKategoriTest`
Expected: FAIL — `Failed asserting that false is true` (kolom belum ada).

- [ ] **Step 3: Buat migrasi**

Create `database/migrations/2026_07_28_000001_add_kategori_to_master_jenis_ng.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_jenis_ng', function (Blueprint $table) {
            $table->string('kategori', 50)->nullable()->after('nama_ng');
        });
    }

    public function down(): void
    {
        Schema::table('master_jenis_ng', function (Blueprint $table) {
            $table->dropColumn('kategori');
        });
    }
};
```

- [ ] **Step 4: Tambahkan `kategori` ke `$fillable`**

Modify `app/Models/MasterJenisNg.php`:

```php
    protected $fillable = ['kode', 'nama_ng', 'kategori', 'deskripsi'];
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=MasterJenisNgKategoriTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Daftarkan migrasi di `deploy.yml`**

Modify `.github/workflows/deploy.yml` — pada langkah "Migrate (targeted) di server", tambahkan baris setelah `--path=database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php`. Perhatikan tanda kutip penutup `"` berpindah ke baris terakhir:

```yaml
              --path=database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php \
              --path=database/migrations/2026_07_28_000001_add_kategori_to_master_jenis_ng.php"
```

- [ ] **Step 7: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap: 48 file — 8 dijalankan saat deploy, 40 baseline.`

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_28_000001_add_kategori_to_master_jenis_ng.php app/Models/MasterJenisNg.php tests/Unit/MasterJenisNgKategoriTest.php .github/workflows/deploy.yml
git commit -m "feat(ng): kolom kategori di master_jenis_ng

Disiapkan untuk roll-up Pareto per kategori di Tahap 2. Nullable dan belum
dipakai Tahap 1 — ditambahkan sekarang selagi tabel detail masih kosong,
karena menambahkannya setelah ribuan baris detail terisi berarti migrasi
data tersendiri."
```

---

## Task 2: Tabel detail + model + trait

**Files:**
- Create: `database/migrations/2026_07_28_000002_create_transaksi_produksi_ng_table.php`
- Create: `database/migrations/2026_07_28_000003_create_transaksi_qc_ng_table.php`
- Create: `app/Models/Concerns/NgDetailRow.php`
- Create: `app/Models/TransaksiProduksiNg.php`
- Create: `app/Models/TransaksiQcNg.php`
- Modify: `app/Models/TransaksiProduksi.php`
- Modify: `app/Models/TransaksiQc.php`
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Unit/NgDetailTableTest.php`

**Interfaces:**
- Consumes: `master_jenis_ng.kategori` dari Task 1 (tidak dipakai langsung, hanya harus sudah ada).
- Produces:
  - `App\Models\TransaksiProduksiNg` — kolom `id_transaksi_produksi`, `id_jenis_ng`, `qty`; relasi `jenisNg()`, `transaksi()`
  - `App\Models\TransaksiQcNg` — kolom `id_transaksi_qc`, `id_jenis_ng`, `qty`; relasi `jenisNg()`, `transaksi()`
  - `TransaksiProduksi::ngDetails(): HasMany`
  - `TransaksiQc::ngDetails(): HasMany`

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/NgDetailTableTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgDetailTableTest extends TestCase
{
    use RefreshDatabase;

    private function produksi(): TransaksiProduksi
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op NG', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'NG-01', 'nama_mesin' => 'Press NG', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'NG-P1', 'part_name' => 'NG Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        return TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
        ]);
    }

    public function test_relasi_ng_details_mengembalikan_baris_detail(): void
    {
        $trx = $this->produksi();
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 2]);

        $this->assertCount(2, $trx->fresh()->ngDetails);
        $this->assertSame(7, (int) $trx->fresh()->ngDetails->sum('qty'));
        $this->assertSame('Dented', $trx->fresh()->ngDetails->first()->jenisNg->nama_ng);
    }

    public function test_jenis_ng_yang_sama_dua_kali_ditolak_database(): void
    {
        $trx = $this->produksi();
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);

        $this->expectException(QueryException::class);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 3]);
    }

    public function test_baris_detail_tidak_memakai_soft_deletes(): void
    {
        $trx = $this->produksi();
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        $row = TransaksiProduksiNg::create([
            'id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5,
        ]);
        $row->delete();

        // Hapus lalu tambah jenis yang sama harus berhasil — inilah alasan
        // tabel detail sengaja tidak memakai SoftDeletes.
        TransaksiProduksiNg::create([
            'id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 3,
        ]);

        $this->assertSame(1, TransaksiProduksiNg::where('id_transaksi_produksi', $trx->id)->count());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgDetailTableTest`
Expected: FAIL — `Class "App\Models\TransaksiProduksiNg" not found`.

- [ ] **Step 3: Buat migrasi tabel detail produksi**

Create `database/migrations/2026_07_28_000002_create_transaksi_produksi_ng_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_produksi_ng', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_transaksi_produksi')
                ->constrained('transaksi_produksi')
                ->cascadeOnDelete();
            $table->foreignId('id_jenis_ng')
                ->constrained('master_jenis_ng')
                ->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            // Satu jenis NG tidak boleh dobel dalam satu sesi.
            $table->unique(['id_transaksi_produksi', 'id_jenis_ng'], 'uniq_prod_ng');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_produksi_ng');
    }
};
```

- [ ] **Step 4: Buat migrasi tabel detail QC**

Create `database/migrations/2026_07_28_000003_create_transaksi_qc_ng_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_qc_ng', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_transaksi_qc')
                ->constrained('transaksi_qc')
                ->cascadeOnDelete();
            $table->foreignId('id_jenis_ng')
                ->constrained('master_jenis_ng')
                ->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            $table->unique(['id_transaksi_qc', 'id_jenis_ng'], 'uniq_qc_ng');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_qc_ng');
    }
};
```

- [ ] **Step 5: Buat trait bersama**

Create `app/Models/Concerns/NgDetailRow.php`:

```php
<?php

namespace App\Models\Concerns;

use App\Models\MasterJenisNg;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perilaku bersama dua model baris detail NG.
 *
 * Sengaja TANPA SoftDeletes: baris detail ditulis ulang seluruhnya tiap simpan,
 * dan baris ter-soft-delete akan menempati slot UNIQUE sehingga hapus-lalu-tambah
 * jenis yang sama gagal dengan duplicate key.
 */
trait NgDetailRow
{
    protected $casts = [
        'qty' => 'integer',
    ];

    public function jenisNg(): BelongsTo
    {
        return $this->belongsTo(MasterJenisNg::class, 'id_jenis_ng');
    }
}
```

- [ ] **Step 6: Buat kedua model detail**

Create `app/Models/TransaksiProduksiNg.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\NgDetailRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiProduksiNg extends Model
{
    use NgDetailRow;

    protected $table = 'transaksi_produksi_ng';

    protected $fillable = ['id_transaksi_produksi', 'id_jenis_ng', 'qty'];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(TransaksiProduksi::class, 'id_transaksi_produksi');
    }
}
```

Create `app/Models/TransaksiQcNg.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\NgDetailRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiQcNg extends Model
{
    use NgDetailRow;

    protected $table = 'transaksi_qc_ng';

    protected $fillable = ['id_transaksi_qc', 'id_jenis_ng', 'qty'];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(TransaksiQc::class, 'id_transaksi_qc');
    }
}
```

- [ ] **Step 7: Tambahkan relasi di kedua model induk**

Modify `app/Models/TransaksiProduksi.php` — tambahkan import dan method:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

    public function ngDetails(): HasMany
    {
        return $this->hasMany(TransaksiProduksiNg::class, 'id_transaksi_produksi');
    }
```

Modify `app/Models/TransaksiQc.php`:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;

    public function ngDetails(): HasMany
    {
        return $this->hasMany(TransaksiQcNg::class, 'id_transaksi_qc');
    }
```

- [ ] **Step 8: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgDetailTableTest`
Expected: PASS (3 tests).

Catatan: SQLite menegakkan `UNIQUE` sama seperti MySQL, jadi test kedua valid di suite. FK `restrictOnDelete` tidak diuji di sini karena `master_jenis_ng` memakai SoftDeletes sehingga penghapusan keras tidak pernah terjadi lewat aplikasi.

- [ ] **Step 9: Daftarkan dua migrasi di `deploy.yml`**

Modify `.github/workflows/deploy.yml` — ganti baris terakhir langkah Migrate:

```yaml
              --path=database/migrations/2026_07_28_000001_add_kategori_to_master_jenis_ng.php \
              --path=database/migrations/2026_07_28_000002_create_transaksi_produksi_ng_table.php \
              --path=database/migrations/2026_07_28_000003_create_transaksi_qc_ng_table.php"
```

- [ ] **Step 10: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap: 50 file — 10 dijalankan saat deploy, 40 baseline.`

- [ ] **Step 11: Commit**

```bash
git add database/migrations/2026_07_28_00000{2,3}_*.php app/Models/ tests/Unit/NgDetailTableTest.php .github/workflows/deploy.yml
git commit -m "feat(ng): tabel detail NG per transaksi

Dua tabel terpisah, bukan polymorphic: chatbot menembak SQL mentah lewat
SqlGuard/BusinessTableCatalog, dan relasi morph hanya hidup di level Eloquent
sehingga tidak ada FK yang bisa ditegakkan database.

UNIQUE (induk, jenis_ng) menolak jenis dobel dalam satu sesi di lapisan DB.
Tabel detail sengaja tanpa SoftDeletes — baris ditulis ulang tiap simpan, dan
baris ter-soft-delete akan menempati slot UNIQUE sehingga hapus-lalu-tambah
jenis yang sama gagal duplicate key. Test ketiga mengunci perilaku ini."
```

---

## Task 3: `NgDetailService`

**Files:**
- Create: `app/Services/NgDetailService.php`
- Test: `tests/Unit/NgDetailServiceTest.php`

**Interfaces:**
- Consumes: `TransaksiProduksiNg`, `TransaksiQcNg`, relasi `ngDetails()` dari Task 2.
- Produces:
  - `NgDetailService::syncProduksi(TransaksiProduksi $trx, array $items): void`
  - `NgDetailService::syncQc(TransaksiQc $trx, array $items): void`
  - Bentuk `$items`: `[['id_jenis_ng' => int, 'qty' => int], ...]`. Baris dengan `id_jenis_ng` kosong atau `qty <= 0` dibuang diam-diam (validasi Request yang menolaknya lebih dulu dengan pesan).

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/NgDetailServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Services\NgDetailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgDetailServiceTest extends TestCase
{
    use RefreshDatabase;

    private NgDetailService $service;

    private TransaksiProduksi $trx;

    private MasterJenisNg $dented;

    private MasterJenisNg $crack;

    private MasterJenisNg $burry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(NgDetailService::class);

        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Sync', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'SY-01', 'nama_mesin' => 'Press Sync', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'SY-P1', 'part_name' => 'Sync Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->trx = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
        ]);

        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $this->crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);
        $this->burry = MasterJenisNg::create(['kode' => 'BRY', 'nama_ng' => 'Burry']);
    }

    public function test_menyimpan_tiga_jenis_dan_menurunkan_qty_ng(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ['id_jenis_ng' => $this->burry->id, 'qty' => 3],
        ]);

        $this->assertCount(3, $this->trx->fresh()->ngDetails);
        $this->assertSame(10, (int) $this->trx->fresh()->qty_ng);
    }

    public function test_edit_mengurangi_jenis_menghapus_detail_lama_dan_menurunkan_qty_ng(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ['id_jenis_ng' => $this->burry->id, 'qty' => 3],
        ]);

        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
        ]);

        $fresh = $this->trx->fresh();
        $this->assertCount(1, $fresh->ngDetails);
        $this->assertSame($this->crack->id, $fresh->ngDetails->first()->id_jenis_ng);
        $this->assertSame(4, (int) $fresh->qty_ng);
    }

    public function test_hapus_lalu_tambah_jenis_yang_sama_berhasil(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
        ]);

        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->crack->id, 'qty' => 1],
        ]);

        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 9],
        ]);

        $fresh = $this->trx->fresh();
        $this->assertCount(1, $fresh->ngDetails);
        $this->assertSame(9, (int) $fresh->qty_ng);
    }

    public function test_baris_kosong_dan_qty_nol_diabaikan(): void
    {
        $this->service->syncProduksi($this->trx, [
            ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ['id_jenis_ng' => '', 'qty' => 3],
            ['id_jenis_ng' => $this->crack->id, 'qty' => 0],
        ]);

        $this->assertCount(1, $this->trx->fresh()->ngDetails);
        $this->assertSame(5, (int) $this->trx->fresh()->qty_ng);
    }

    public function test_array_kosong_menghasilkan_qty_ng_nol(): void
    {
        $this->service->syncProduksi($this->trx, []);

        $this->assertCount(0, $this->trx->fresh()->ngDetails);
        $this->assertSame(0, (int) $this->trx->fresh()->qty_ng);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgDetailServiceTest`
Expected: FAIL — `Target class [App\Services\NgDetailService] does not exist`.

- [ ] **Step 3: Implementasikan service**

Create `app/Services/NgDetailService.php`:

```php
<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu-satunya penulis baris detail NG dan kolom turunan `qty_ng`.
 *
 * Sengaja service eksplisit, bukan Eloquent observer: observer tidak jalan di
 * operasi bulk dan membuat efek samping tak terlihat dari controller. `qty_ng`
 * dibaca 16 file, jadi kapan nilainya berubah harus mudah dilacak.
 *
 * Invarian yang dijaga: qty_ng = SUM(detail.qty) untuk setiap baris yang pernah
 * melewati service ini.
 */
class NgDetailService
{
    /**
     * Kode dan skema tidak dijamin tiba bersamaan di produksi: rsync mendahului
     * `artisan migrate` di pipeline deploy. Semua jalur BACA tabel detail wajib
     * lewat gerbang ini supaya halaman tidak 500 di jendela antara keduanya.
     */
    public static function available(): bool
    {
        return Schema::hasTable('transaksi_produksi_ng')
            && Schema::hasTable('transaksi_qc_ng');
    }

    public function syncProduksi(TransaksiProduksi $trx, array $items): void
    {
        $this->sync($trx, $items, TransaksiProduksiNg::class, 'id_transaksi_produksi');
    }

    public function syncQc(TransaksiQc $trx, array $items): void
    {
        $this->sync($trx, $items, TransaksiQcNg::class, 'id_transaksi_qc');
    }

    private function sync(Model $trx, array $items, string $detailClass, string $foreignKey): void
    {
        $rows = $this->normalize($items);

        DB::transaction(function () use ($trx, $rows, $detailClass, $foreignKey) {
            $detailClass::where($foreignKey, $trx->getKey())->delete();

            foreach ($rows as $row) {
                $detailClass::create([
                    $foreignKey => $trx->getKey(),
                    'id_jenis_ng' => $row['id_jenis_ng'],
                    'qty' => $row['qty'],
                ]);
            }

            $trx->qty_ng = array_sum(array_column($rows, 'qty'));
            $trx->save();
        });
    }

    /**
     * Buang baris tanpa jenis atau tanpa qty, dan gabungkan qty bila jenis yang
     * sama muncul lebih dari sekali (validasi Request menolaknya lebih dulu
     * dengan pesan; ini jaring agar UNIQUE tidak pernah meledak dari sini).
     *
     * @return list<array{id_jenis_ng: int, qty: int}>
     */
    private function normalize(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $id = (int) ($item['id_jenis_ng'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);

            if ($id <= 0 || $qty <= 0) {
                continue;
            }

            $merged[$id] = ($merged[$id] ?? 0) + $qty;
        }

        $rows = [];
        foreach ($merged as $id => $qty) {
            $rows[] = ['id_jenis_ng' => $id, 'qty' => $qty];
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Tambahkan test untuk gerbang `available()`**

Append ke `tests/Unit/NgDetailServiceTest.php`:

```php
    public function test_available_true_saat_kedua_tabel_detail_ada(): void
    {
        $this->assertTrue(NgDetailService::available());
    }
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgDetailServiceTest`
Expected: PASS (6 tests).

- [ ] **Step 6: Format**

Run: `vendor/bin/pint app tests`
Expected: `PASS` atau daftar file yang diformat.

- [ ] **Step 7: Commit**

```bash
git add app/Services/NgDetailService.php tests/Unit/NgDetailServiceTest.php
git commit -m "feat(ng): NgDetailService sebagai satu-satunya penulis detail NG

Service eksplisit, bukan Eloquent observer: observer tidak jalan di operasi
bulk dan menyembunyikan efek samping dari controller, sementara qty_ng dibaca
16 file sehingga kapan nilainya berubah harus mudah dilacak.

Menjaga invarian qty_ng = SUM(detail.qty). normalize() menggabungkan jenis
yang sama agar UNIQUE tidak pernah meledak dari jalur ini — validasi Request
tetap yang menolak duplikat lebih dulu dengan pesan yang enak dibaca."
```

---

## Task 4: Komponen Blade baris NG

**Files:**
- Create: `resources/views/input/partials/ng-rows.blade.php`

**Interfaces:**
- Consumes: —
- Produces: partial Blade yang dipanggil dengan
  `@include('input.partials.ng-rows', ['jenisNg' => $jenisNg, 'initial' => $initialNgRows])`
  - `$jenisNg` — Collection `MasterJenisNg`
  - `$initial` — array `[['id_jenis_ng' => int|string, 'qty' => int], ...]`, boleh kosong
  - Menghasilkan input bernama `ng[i][id_jenis_ng]` dan `ng[i][qty]`

- [ ] **Step 1: Buat partial**

Create `resources/views/input/partials/ng-rows.blade.php`:

```blade
{{--
    Baris NG berulang — dipakai 4 form (produksi/qc × create/edit).
    Mengikuti pola baris berulang form Dandori.

    Parameter:
      $jenisNg : Collection MasterJenisNg
      $initial : array [['id_jenis_ng' => int, 'qty' => int], ...]
--}}
<div x-data="ngRows(@js($initial ?? []))" class="ng-rows">

    <template x-if="rows.length === 0">
        <div class="text-muted small mb-2">Belum ada jenis NG. Klik tombol di bawah untuk menambah.</div>
    </template>

    <template x-for="(row, i) in rows" :key="row.uid">
        <div class="row g-2 mb-2 align-items-center">
            <div class="col-7">
                <select class="form-select form-select-sm"
                        :name="`ng[${i}][id_jenis_ng]`"
                        x-model="row.id_jenis_ng" required>
                    <option value="">— pilih jenis NG —</option>
                    @foreach($jenisNg as $j)
                        <option value="{{ $j->id }}">{{ $j->kode }} — {{ $j->nama_ng }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-3">
                <input type="number" min="1" step="1"
                       class="form-control form-control-sm"
                       :name="`ng[${i}][qty]`"
                       x-model.number="row.qty"
                       placeholder="Qty" required>
            </div>
            <div class="col-2">
                <button type="button" class="btn btn-sm btn-outline-danger w-100"
                        @click="remove(i)" title="Hapus baris">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    </template>

    <button type="button" class="btn btn-sm btn-outline-secondary" @click="add()">
        <i class="bi bi-plus-lg"></i> Tambah jenis NG
    </button>

    <div class="mt-2 small">
        Total NG: <strong x-text="total"></strong>
    </div>

    @error('ng')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('ng.*.id_jenis_ng')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('ng.*.qty')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
</div>

@once
@push('scripts')
<script>
function ngRows(initial) {
    let seq = 0;
    return {
        rows: (initial || []).map(r => ({
            uid: seq++,
            id_jenis_ng: String(r.id_jenis_ng ?? ''),
            qty: r.qty ?? null,
        })),
        add() {
            this.rows.push({ uid: seq++, id_jenis_ng: '', qty: null });
        },
        remove(i) {
            this.rows.splice(i, 1);
        },
        get total() {
            return this.rows.reduce((s, r) => s + (parseInt(r.qty) || 0), 0);
        },
    };
}
</script>
@endpush
@endonce
```

- [ ] **Step 2: Verifikasi partial ter-render tanpa error**

Run: `php artisan view:cache && php artisan view:clear`
Expected: `INFO  Blade templates cached successfully.` lalu `INFO  Compiled views cleared successfully.` — tanpa exception kompilasi.

- [ ] **Step 3: Commit**

```bash
git add resources/views/input/partials/ng-rows.blade.php
git commit -m "feat(ng): komponen Blade baris NG berulang

Satu partial dipakai empat form (produksi/qc x create/edit), mengikuti pola
baris berulang form Dandori. Total NG dihitung live dan read-only — operator
melihat angkanya tanpa bisa mengeditnya, karena angka itu turunan."
```

---

## Task 5: Form LHP Produksi

**Files:**
- Modify: `app/Http/Requests/StoreProduksiRequest.php`
- Modify: `app/Http/Requests/UpdateProduksiRequest.php`
- Modify: `app/Http/Controllers/Input/ProduksiController.php:141-198`
- Modify: `resources/views/input/produksi/create.blade.php:205-240`
- Modify: `resources/views/input/produksi/edit.blade.php:20-30,205-240`
- Test: `tests/Feature/Input/NgMultiJenisProduksiTest.php`

**Interfaces:**
- Consumes: `NgDetailService::syncProduksi()` dari Task 3; partial `input.partials.ng-rows` dari Task 4.
- Produces: form produksi mengirim `ng[]`; `qty_ng` dan `id_jenis_ng` tidak lagi dikirim form.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Feature/Input/NgMultiJenisProduksiTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgMultiJenisProduksiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $base;

    private MasterJenisNg $dented;

    private MasterJenisNg $crack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Multi', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'MJ-01', 'nama_mesin' => 'Press MJ', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'MJ-P1', 'part_name' => 'Multi Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->base = [
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00',
            'jam_finish' => '12:00',
            'qty_aktual' => 100,
        ];

        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $this->crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);
    }

    public function test_simpan_dengan_dua_jenis_ng(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ],
        ]);

        $response->assertRedirect(route('input.produksi.index'));

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertCount(2, $trx->ngDetails);
        $this->assertSame(7, (int) $trx->qty_ng);
    }

    public function test_jenis_ng_dobel_ditolak_validasi(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->dented->id, 'qty' => 2],
            ],
        ]);

        $response->assertSessionHasErrors('ng.1.id_jenis_ng');
        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_ada_ng_dicentang_tapi_baris_kosong_ditolak(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('ng');
    }

    public function test_tanpa_ng_menyimpan_qty_ng_nol(): void
    {
        $this->actingAs($this->admin)->post(route('input.produksi.store'), $this->base);

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertCount(0, $trx->ngDetails);
        $this->assertSame(0, (int) $trx->qty_ng);
    }

    public function test_edit_mengurangi_jenis_menurunkan_qty_ng(): void
    {
        $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ],
        ]);

        $trx = TransaksiProduksi::latest('id')->first();

        $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), [
            ...$this->base,
            'ada_ng' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
            ],
        ]);

        $trx->refresh();
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(4, (int) $trx->qty_ng);
    }

    public function test_baris_legacy_tanpa_detail_tidak_boleh_disimpan_tanpa_klasifikasi(): void
    {
        // Baris gaya lama: qty_ng terisi, nol baris detail — meniru data yang
        // di-skip migrasi karena id_jenis_ng NULL.
        $trx = TransaksiProduksi::create([...$this->base, 'jam_start' => '08:00:00', 'jam_finish' => '12:00:00']);
        $trx->forceFill(['qty_ng' => 6])->save();

        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), [
            ...$this->base,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('ng');
        $this->assertSame(6, (int) $trx->fresh()->qty_ng);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgMultiJenisProduksiTest`
Expected: FAIL — test pertama gagal karena `ngDetails` kosong dan `qty_ng` null (form belum memproses `ng[]`).

- [ ] **Step 3: Ubah `StoreProduksiRequest`**

Modify `app/Http/Requests/StoreProduksiRequest.php` — ganti dua baris aturan NG dan seluruh `prepareForValidation()`:

```php
            'ada_ng' => 'nullable|boolean',
            'ng' => 'array|required_if:ada_ng,1',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    protected function prepareForValidation(): void
    {
        /* Kalau ada_ng tidak dicentang, buang seluruh baris NG. */
        if (! $this->boolean('ada_ng')) {
            $this->merge(['ng' => []]);
        }
    }

    public function messages(): array
    {
        return [
            'ng.required_if' => 'Centang "ada NG" berarti minimal satu jenis NG harus diisi.',
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
}
```

- [ ] **Step 4: Ubah `UpdateProduksiRequest`**

Modify `app/Http/Requests/UpdateProduksiRequest.php` — aturan NG sama seperti Store, ditambah guard baris legacy:

```php
            'ada_ng' => 'nullable|boolean',
            'ng' => 'array',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $produksi = $this->route('produksi');

            if (! $produksi instanceof \App\Models\TransaksiProduksi) {
                return;
            }

            /* Tabel detail belum ada di jendela antara rsync dan migrate. */
            if (! \App\Services\NgDetailService::available()) {
                return;
            }

            /*
             * Baris gaya lama: qty_ng terisi tapi nol baris detail — data yang
             * di-skip migrasi karena id_jenis_ng NULL. Menyimpannya dengan ng
             * kosong akan menulis qty_ng = 0 dan menghapus angka NG yang pernah
             * tercatat, tanpa error. Paksa klasifikasi dulu.
             */
            $legacy = (int) $produksi->qty_ng > 0 && $produksi->ngDetails()->count() === 0;

            if ($legacy && empty($this->input('ng'))) {
                $validator->errors()->add(
                    'ng',
                    'Baris ini punya '.(int) $produksi->qty_ng.' NG dari data lama yang belum diklasifikasi. '
                    .'Pilih jenis NG-nya sebelum menyimpan.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
}
```

Catatan: nama parameter route diambil dari `Route::resource('produksi', ...)` → `$this->route('produksi')`. Bila binding memakai nama lain, sesuaikan.

- [ ] **Step 5: Ubah `ProduksiController`**

Modify `app/Http/Controllers/Input/ProduksiController.php` — inject service di constructor, lalu ubah `store()` dan `update()`:

```php
use App\Services\NgDetailService;

    // Tambahkan parameter pada constructor yang sudah ada:
    // public function __construct(private ProduksiService $produksiService, private NgDetailService $ngDetailService) {}

    public function store(StoreProduksiRequest $request)
    {
        $validated = $request->validated();
        $ngRows = $validated['ng'] ?? [];
        unset($validated['ng'], $validated['ada_ng']);

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            $validated['qty_aktual']
        );

        $produksi = TransaksiProduksi::create([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);

        $this->ngDetailService->syncProduksi($produksi, $ngRows);

        return redirect()->route('input.produksi.index')
            ->with('success', 'LHP Produksi berhasil disimpan.');
    }

    public function update(UpdateProduksiRequest $request, TransaksiProduksi $produksi)
    {
        $validated = $request->validated();
        $ngRows = $validated['ng'] ?? [];
        unset($validated['ng'], $validated['ada_ng']);

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            $validated['qty_aktual']
        );

        try {
            $produksi->update([
                ...$validated,
                'actual_cycle_time' => $cycleTime,
                'anomaly_reviewed_at' => null,
                'anomaly_reviewed_by' => null,
            ]);
        } catch (\Throwable) {
            $produksi->update([...$validated, 'actual_cycle_time' => $cycleTime]);
        }

        $this->ngDetailService->syncProduksi($produksi, $ngRows);

        $this->resolveStaffNoteIfRequested(
            $request, $produksi, 'produksi',
            $produksi->tanggal_produksi->format('Y-m-d')
        );

        return redirect()->route('input.produksi.index')->with('success', 'LHP Produksi berhasil diupdate.');
    }
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgMultiJenisProduksiTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Ganti blok NG di form create**

Modify `resources/views/input/produksi/create.blade.php` — ganti seluruh blok input `qty_ng` + typeahead jenis NG (sekitar baris 205–240) dengan:

```blade
                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', [
                                'jenisNg' => $jenisNg,
                                'initial' => old('ng', []),
                            ])
                        </div>
```

Pastikan controller `create()` sudah mengirim `$jenisNg` ke view (sudah ada — lihat `edit()` yang memuatnya).

- [ ] **Step 8: Ganti blok NG di form edit + prefill legacy**

Modify `resources/views/input/produksi/edit.blade.php` — ganti perhitungan awal di sekitar baris 25–26:

```php
$existingNg   = $produksi->ngDetails->map(fn ($d) => [
    'id_jenis_ng' => $d->id_jenis_ng,
    'qty'         => $d->qty,
])->values()->all();

/*
 * Baris gaya lama: qty_ng terisi tapi nol baris detail. Render satu baris
 * dengan qty terisi dan jenis kosong supaya operator wajib mengklasifikasi —
 * kalau dibiarkan nol baris, menyimpan akan menulis qty_ng = 0 dan menghapus
 * angka NG yang pernah tercatat tanpa error.
 */
if (empty($existingNg) && (int) $produksi->qty_ng > 0) {
    $existingNg = [['id_jenis_ng' => '', 'qty' => (int) $produksi->qty_ng]];
}

$initNgRows   = $useOld ? old('ng', $existingNg) : $existingNg;
$initAdaNg    = ! empty($initNgRows) ? '1' : '0';
```

Lalu ganti blok input NG (sekitar baris 205–240) dengan:

```blade
                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', [
                                'jenisNg' => $jenisNg,
                                'initial' => $initNgRows,
                            ])
                        </div>
```

Modify `app/Http/Controllers/Input/ProduksiController.php` — pada `edit()`, ganti eager-load `jenisNg` menjadi `ngDetails.jenisNg`:

```php
        $produksi->load(['karyawan', 'mesin', 'partProses.part.proses', 'ngDetails.jenisNg']);
```

- [ ] **Step 9: Verifikasi view terkompilasi & test masih lulus**

Run: `php artisan view:clear && php artisan test --filter=NgMultiJenisProduksiTest`
Expected: PASS (6 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/StoreProduksiRequest.php app/Http/Requests/UpdateProduksiRequest.php app/Http/Controllers/Input/ProduksiController.php resources/views/input/produksi/ tests/Feature/Input/NgMultiJenisProduksiTest.php
git commit -m "feat(ng): form LHP Produksi menerima beberapa jenis NG

Form edit lama prefill dari qty_ng dan required_if memaksa operator mengisi
jenis NG sebelum simpan. Merender baris NG semata dari tabel detail akan
menghilangkan paksaan itu: baris lama yang di-skip migrasi tampil nol baris,
disimpan, lalu qty_ng jadi 0 tanpa error.

Baris legacy (qty_ng > 0, nol detail) karena itu dirender sebagai satu baris
dengan qty terisi dan jenis kosong, dan UpdateProduksiRequest menolak simpan
selama belum diklasifikasi. Setiap edit baris lama jadi momen perbaikan data."
```

---

## Task 6: Form LHP Quality

**Files:**
- Modify: `app/Http/Requests/StoreQcRequest.php`
- Modify: `app/Http/Requests/UpdateQcRequest.php`
- Modify: `app/Http/Controllers/Input/QcController.php:125-183`
- Modify: `resources/views/input/qc/create.blade.php:155-210`
- Modify: `resources/views/input/qc/edit.blade.php`
- Test: `tests/Feature/Input/NgMultiJenisQcTest.php`

**Interfaces:**
- Consumes: `NgDetailService::syncQc()` dari Task 3; partial `input.partials.ng-rows` dari Task 4.
- Produces: form QC mengirim `ng[]`; `qty_ng` tidak lagi dikirim form.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Feature/Input/NgMultiJenisQcTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgMultiJenisQcTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $base;

    private MasterJenisNg $dented;

    private MasterJenisNg $crack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Insp Multi', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $part = MasterPart::create(['part_no' => 'QC-P1', 'part_name' => 'QC Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->base = [
            'tgl_pengecekan' => '2026-07-28',
            'tgl_produksi_part' => '2026-07-27',
            'id_shift' => $shift->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00',
            'jam_finish' => '12:00',
            'qty_ok' => 90,
        ];

        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $this->crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);
    }

    public function test_simpan_dengan_dua_jenis_ng(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 6],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
            ],
        ]);

        $response->assertRedirect(route('input.qc.index'));

        $trx = TransaksiQc::latest('id')->first();
        $this->assertCount(2, $trx->ngDetails);
        $this->assertSame(10, (int) $trx->qty_ng);
    }

    public function test_tanpa_ng_menyimpan_qty_ng_nol(): void
    {
        $this->actingAs($this->admin)->post(route('input.qc.store'), $this->base);

        $trx = TransaksiQc::latest('id')->first();
        $this->assertCount(0, $trx->ngDetails);
        $this->assertSame(0, (int) $trx->qty_ng);
    }

    public function test_qty_ok_nol_dan_tanpa_ng_ditolak(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'qty_ok' => 0,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('qty_ok');
    }

    public function test_qty_ok_nol_tapi_ada_ng_diterima(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'qty_ok' => 0,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 3],
            ],
        ]);

        $response->assertRedirect(route('input.qc.index'));
        $this->assertSame(3, (int) TransaksiQc::latest('id')->first()->qty_ng);
    }

    public function test_jenis_ng_dobel_ditolak_validasi(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 6],
                ['id_jenis_ng' => $this->dented->id, 'qty' => 4],
            ],
        ]);

        $response->assertSessionHasErrors('ng.1.id_jenis_ng');
    }

    public function test_baris_legacy_tanpa_detail_tidak_boleh_disimpan_tanpa_klasifikasi(): void
    {
        $trx = TransaksiQc::create([
            ...$this->base,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_ng' => 7,
        ]);

        $response = $this->actingAs($this->admin)->put(route('input.qc.update', $trx), [
            ...$this->base,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('ng');
        $this->assertSame(7, (int) $trx->fresh()->qty_ng);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgMultiJenisQcTest`
Expected: FAIL — test pertama gagal, `ngDetails` kosong.

- [ ] **Step 3: Ubah `StoreQcRequest`**

Modify `app/Http/Requests/StoreQcRequest.php` — ganti aturan NG dan `withValidator()`:

```php
            'qty_ok' => 'required|integer|min:0',
            'ng' => 'array',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $qtyOk = (int) $this->input('qty_ok', 0);
            $qtyNg = collect($this->input('ng', []))->sum(fn ($r) => (int) ($r['qty'] ?? 0));

            if ($qtyOk + $qtyNg === 0) {
                $validator->errors()->add('qty_ok', 'Total qty check (OK + NG) harus lebih dari 0.');
            }
        });
    }

    public function messages(): array
    {
        return [
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
```

- [ ] **Step 4: Ubah `UpdateQcRequest`**

Modify `app/Http/Requests/UpdateQcRequest.php` — aturan NG sama, plus guard legacy dan aturan total:

```php
            'qty_ok' => 'required|integer|min:0',
            'ng' => 'array',
            'ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'ng.*.qty' => 'required|integer|min:1',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $qtyOk = (int) $this->input('qty_ok', 0);
            $qtyNg = collect($this->input('ng', []))->sum(fn ($r) => (int) ($r['qty'] ?? 0));

            if ($qtyOk + $qtyNg === 0) {
                $validator->errors()->add('qty_ok', 'Total qty check (OK + NG) harus lebih dari 0.');
            }

            $qc = $this->route('qc');

            if (! $qc instanceof \App\Models\TransaksiQc) {
                return;
            }

            /* Tabel detail belum ada di jendela antara rsync dan migrate. */
            if (! \App\Services\NgDetailService::available()) {
                return;
            }

            /*
             * Baris gaya lama: qty_ng terisi tapi nol baris detail. Menyimpan
             * dengan ng kosong akan menulis qty_ng = 0 dan menghapus angka NG
             * yang pernah tercatat, tanpa error. Paksa klasifikasi dulu.
             */
            $legacy = (int) $qc->qty_ng > 0 && $qc->ngDetails()->count() === 0;

            if ($legacy && empty($this->input('ng'))) {
                $validator->errors()->add(
                    'ng',
                    'Baris ini punya '.(int) $qc->qty_ng.' NG dari data lama yang belum diklasifikasi. '
                    .'Pilih jenis NG-nya sebelum menyimpan.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'ng.*.id_jenis_ng.distinct' => 'Jenis NG yang sama tidak boleh diisi dua kali dalam satu sesi.',
            'ng.*.id_jenis_ng.required' => 'Pilih jenis NG untuk setiap baris.',
            'ng.*.qty.min' => 'Qty NG minimal 1.',
        ];
    }
```

- [ ] **Step 5: Ubah `QcController`**

Modify `app/Http/Controllers/Input/QcController.php` — inject service, ubah `store()`, `update()`, dan eager-load `edit()`:

```php
use App\Services\NgDetailService;

    // Tambahkan parameter pada constructor yang sudah ada:
    // public function __construct(private QcService $qcService, private NgDetailService $ngDetailService) {}

    public function store(StoreQcRequest $request)
    {
        $validated = $request->validated();
        $ngRows = $validated['ng'] ?? [];
        unset($validated['ng']);

        $qtyNg = array_sum(array_column($ngRows, 'qty'));

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            (int) $validated['qty_ok'],
            (int) $qtyNg
        );

        $qc = TransaksiQc::create([
            ...$validated,
            'qty_ng' => 0,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);

        $this->ngDetailService->syncQc($qc, $ngRows);

        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil disimpan.');
    }

    public function update(UpdateQcRequest $request, TransaksiQc $qc)
    {
        $validated = $request->validated();
        $ngRows = $validated['ng'] ?? [];
        unset($validated['ng']);

        $qtyNg = array_sum(array_column($ngRows, 'qty'));

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            (int) $validated['qty_ok'],
            (int) $qtyNg
        );

        $qc->update([
            ...$validated,
            'actual_cycle_time' => $cycleTime,
        ]);

        $this->ngDetailService->syncQc($qc, $ngRows);

        $this->resolveStaffNoteIfRequested(
            $request, $qc, 'qc',
            $qc->tgl_pengecekan->format('Y-m-d')
        );

        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil diupdate.');
    }
```

Pada `edit()`, ganti eager-load `jenisNg` menjadi `ngDetails.jenisNg`:

```php
        $qc->load(['karyawanQc', 'partProses.part.proses', 'ngDetails.jenisNg']);
```

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgMultiJenisQcTest`
Expected: PASS (6 tests).

- [ ] **Step 7: Ganti blok NG di form create QC**

Modify `resources/views/input/qc/create.blade.php` — ganti blok input `qty_ng` + typeahead jenis NG (sekitar baris 155–210) dengan:

```blade
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Jenis NG</label>
                        @include('input.partials.ng-rows', [
                            'jenisNg' => $jenisNg,
                            'initial' => old('ng', []),
                        ])
                    </div>
```

- [ ] **Step 8: Ganti blok NG di form edit QC + prefill legacy**

Modify `resources/views/input/qc/edit.blade.php` — tambahkan perhitungan awal di blok `@php` teratas:

```php
$existingNg = $qc->ngDetails->map(fn ($d) => [
    'id_jenis_ng' => $d->id_jenis_ng,
    'qty'         => $d->qty,
])->values()->all();

/*
 * Baris gaya lama: qty_ng terisi tapi nol baris detail. Render satu baris
 * dengan qty terisi dan jenis kosong supaya operator wajib mengklasifikasi.
 */
if (empty($existingNg) && (int) $qc->qty_ng > 0) {
    $existingNg = [['id_jenis_ng' => '', 'qty' => (int) $qc->qty_ng]];
}

$initNgRows = old('ng', $existingNg);
```

Lalu ganti blok input NG dengan:

```blade
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Jenis NG</label>
                        @include('input.partials.ng-rows', [
                            'jenisNg' => $jenisNg,
                            'initial' => $initNgRows,
                        ])
                    </div>
```

- [ ] **Step 9: Verifikasi view & test**

Run: `php artisan view:clear && php artisan test --filter=NgMultiJenisQcTest`
Expected: PASS (6 tests).

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/StoreQcRequest.php app/Http/Requests/UpdateQcRequest.php app/Http/Controllers/Input/QcController.php resources/views/input/qc/ tests/Feature/Input/NgMultiJenisQcTest.php
git commit -m "feat(ng): form LHP Quality menerima beberapa jenis NG

Aturan lama 'qty_ok + qty_ng harus > 0' dipertahankan, tapi sisi NG kini
dijumlahkan dari baris. Baris legacy (qty_ng > 0, nol detail) wajib
diklasifikasi sebelum bisa disimpan, sama seperti sisi produksi."
```

---

## Task 7: Import Excel

**Files:**
- Modify: `app/Imports/ProduksiImport.php:120-160`
- Modify: `app/Imports/QcImport.php`
- Test: `tests/Feature/Input/NgImportDetailTest.php`

**Interfaces:**
- Consumes: `NgDetailService::syncProduksi()` / `syncQc()` dari Task 3.
- Produces: import menulis baris detail; format Excel tidak berubah.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Feature/Input/NgImportDetailTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Imports\ProduksiImport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class NgImportDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_satu_jenis_ng_membuat_satu_baris_detail(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        MasterKaryawan::create([
            'nik' => 'IMP-1', 'nama_karyawan' => 'Op Import', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        MasterMesin::create([
            'id_mesin' => 'IM-01', 'nama_mesin' => 'Press Import', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'IM-P1', 'part_name' => 'Import Part', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        $import = new ProduksiImport;
        $import->collection(new Collection([
            new Collection([
                'tanggal_produksi' => '2026-07-28',
                'nama_shift' => 'Shift 1',
                'nik' => 'IMP-1',
                'id_mesin' => 'IM-01',
                'part_no' => 'IM-P1',
                'id_proses' => 1,
                'jam_start' => '08:00',
                'jam_finish' => '12:00',
                'qty_aktual' => 100,
                'qty_ng' => 5,
                'kode_jenis_ng' => 'DNT',
            ]),
        ]));

        $trx = TransaksiProduksi::latest('id')->first();

        $this->assertNotNull($trx, 'Baris produksi harus terbentuk dari import.');
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(5, (int) $trx->ngDetails->first()->qty);
        $this->assertSame(5, (int) $trx->qty_ng);
    }

    public function test_qty_ng_tanpa_kode_jenis_ditolak_bukan_disimpan_nol(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin);

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        MasterKaryawan::create([
            'nik' => 'IMP-2', 'nama_karyawan' => 'Op Import 2', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        MasterMesin::create([
            'id_mesin' => 'IM-02', 'nama_mesin' => 'Press Import 2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'IM-P2', 'part_name' => 'Import Part 2', 'customer' => 'Toyota']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $import = new ProduksiImport;
        $import->collection(new Collection([
            new Collection([
                'tanggal_produksi' => '2026-07-28',
                'nama_shift' => 'Shift 1',
                'nik' => 'IMP-2',
                'id_mesin' => 'IM-02',
                'part_no' => 'IM-P2',
                'id_proses' => 1,
                'jam_start' => '08:00',
                'jam_finish' => '12:00',
                'qty_aktual' => 100,
                'qty_ng' => 5,
                'kode_jenis_ng' => '',
            ]),
        ]));

        // Baris ditolak, bukan disimpan dengan qty_ng = 0.
        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertNotEmpty($import->errors());
        $this->assertStringContainsString('Kode Jenis NG kosong', implode(' ', $import->errors()));
    }
}
```

Catatan: bila accessor daftar error pada `ProduksiImport` bernama lain (mis. properti publik `$errors` alih-alih method `errors()`), sesuaikan dua baris terakhir mengikuti kelas yang ada — jangan mengubah kelasnya agar cocok dengan test.

Catatan untuk implementer: nama kolom pada baris impor di atas mengikuti heading yang sudah dipakai `ProduksiImport`. Buka file itu dan sesuaikan kunci array bila headingnya berbeda — jangan mengubah `ProduksiImport` agar cocok dengan test.

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgImportDetailTest`
Expected: FAIL — `assertCount(1, ...)` gagal karena `ngDetails` kosong.

- [ ] **Step 3: Ubah `ProduksiImport`**

Modify `app/Imports/ProduksiImport.php`. Blok resolusi NG (baris 119–136) **tidak berubah** — `$qtyNg` dan `$idJenisNg` tetap dihitung seperti sekarang.

Yang berubah: array `TransaksiProduksi::create([...])` di baris 145–159. Hapus dua baris `'qty_ng'` dan `'id_jenis_ng'`, tampung hasilnya ke variabel, lalu panggil service. Ganti blok itu menjadi:

```php
                $produksi = TransaksiProduksi::create([
                    'tanggal_produksi' => $tanggal,
                    'id_shift' => $shift->id,
                    'id_karyawan' => $karyawan->id,
                    'id_mesin' => $mesin->id,
                    'id_part_proses' => $partProses->id,
                    'jam_start' => $jamStart,
                    'jam_finish' => $jamFinish,
                    'qty_aktual' => $qtyAktual,
                    'actual_cycle_time' => $cycleTime,
                    'created_by' => auth()->id(),
                ]);

                app(NgDetailService::class)->syncProduksi(
                    $produksi,
                    ($qtyNg > 0 && $idJenisNg)
                        ? [['id_jenis_ng' => $idJenisNg, 'qty' => $qtyNg]]
                        : []
                );
```

Tambahkan import di bagian atas file:

```php
use App\Services\NgDetailService;
```

**Wajib, jangan dilewat.** Baris Excel dengan `qty_ng > 0` tapi `kode_jenis_ng` kosong menghasilkan `$idJenisNg === null`. Tanpa penanganan, `$ngRows` jadi kosong dan `qty_ng` diam-diam tersimpan 0 — angka NG dari file Excel hilang tanpa keluhan. Itu lubang yang sama persis dengan yang ditutup di jalur edit pada Task 5.

Tolak barisnya secara eksplisit, mengikuti pola error yang sudah dipakai import ini. Modify blok NG di baris 124–136 sehingga `kode_jenis_ng` menjadi wajib ketika ada qty NG:

```php
                if ($qtyNgRaw !== '' && (int) $qtyNgRaw > 0) {
                    $qtyNg = (int) $qtyNgRaw;

                    $kodeNg = trim((string) ($row['kode_jenis_ng'] ?? ''));

                    if ($kodeNg === '') {
                        $this->errors[] = "Baris {$rowNum}: Qty NG diisi ({$qtyNg}) tapi Kode Jenis NG kosong. "
                            ."Isi kode jenis NG-nya — NG tanpa jenis tidak bisa disimpan.";

                        continue;
                    }

                    $jenisNg = MasterJenisNg::whereRaw('LOWER(TRIM(kode)) = ?', [strtolower($kodeNg)])->first();

                    if (! $jenisNg) {
                        $this->errors[] = "Baris {$rowNum}: Jenis NG \"{$kodeNg}\" tidak ditemukan di master Jenis NG.";

                        continue;
                    }

                    $idJenisNg = $jenisNg->id;
                }
```

Baris ditolak dengan pesan yang bisa ditindaklanjuti operator, bukan diterima setengah. File Excel lama yang punya baris seperti ini akan gagal sebagian saat diimpor ulang — itu perilaku yang benar: datanya memang tidak lengkap.

- [ ] **Step 4: Ubah `QcImport` dengan pola yang sama**

Modify `app/Imports/QcImport.php` — resolusi `kode_jenis_ng` dipertahankan, lalu setelah baris tersimpan:

```php
use App\Services\NgDetailService;

$ngRows = ($qtyNg > 0 && $idJenisNg)
    ? [['id_jenis_ng' => $idJenisNg, 'qty' => $qtyNg]]
    : [];

app(NgDetailService::class)->syncQc($qc, $ngRows);
```

Hapus `'qty_ng'` dan `'id_jenis_ng'` dari array atribut.

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgImportDetailTest`
Expected: PASS (1 test).

- [ ] **Step 6: Jalankan seluruh test import lama**

Run: `php artisan test --filter=Import`
Expected: PASS — semua test import yang sudah ada tetap hijau.

- [ ] **Step 7: Commit**

```bash
git add app/Imports/ tests/Feature/Input/NgImportDetailTest.php
git commit -m "feat(ng): import Excel menulis lewat NgDetailService

Format Excel tidak berubah — satu jenis NG per baris, nol training ulang.
Jalur import dan jalur form kini bertemu di service yang sama, jadi tidak ada
dua cara menulis NG dan invarian qty_ng = SUM(detail) berlaku di keduanya."
```

---

## Task 8: Export Excel

**Files:**
- Modify: `app/Exports/LhpProduksiExport.php:26-28,68`
- Modify: `app/Exports/LhpQcExport.php:26-28,69`
- Test: `tests/Unit/NgExportFormatTest.php`

**Interfaces:**
- Consumes: relasi `ngDetails.jenisNg` dari Task 2.
- Produces: kolom jenis NG berisi string gabungan `KODE (qty); KODE (qty)`; satu baris ekspor tetap satu sesi.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/NgExportFormatTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Exports\LhpProduksiExport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgExportFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_jenis_ng_menggabungkan_semua_jenis(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Export', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'EX-01', 'nama_mesin' => 'Press Export', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'EX-P1', 'part_name' => 'Export Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $trx = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
            'qty_ng' => 7,
        ]);

        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 2]);

        $rows = (new LhpProduksiExport)->collection();
        $row = $rows->first();

        $this->assertContains('DNT (5); CRK (2)', $row);
    }

    public function test_sesi_tanpa_ng_menghasilkan_kolom_kosong(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Bersih', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'EX-02', 'nama_mesin' => 'Press Bersih', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'EX-P2', 'part_name' => 'Bersih Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
        ]);

        $row = (new LhpProduksiExport)->collection()->first();

        $this->assertContains('', $row);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgExportFormatTest`
Expected: FAIL — `assertContains('DNT (5); CRK (2)', ...)` gagal, kolom masih berisi satu kode.

- [ ] **Step 3: Ubah `LhpProduksiExport`**

Modify `app/Exports/LhpProduksiExport.php` — ganti eager-load dan sel jenis NG:

```php
        $q = TransaksiProduksi::with([
            'karyawan', 'mesin', 'partProses.part', 'shift', 'ngDetails.jenisNg',
        ]);
```

Ganti baris `$t->jenisNg?->kode ?? ''` menjadi:

```php
                $t->ngDetails
                    ->map(fn ($d) => ($d->jenisNg?->kode ?? '?').' ('.$d->qty.')')
                    ->implode('; '),
```

- [ ] **Step 4: Ubah `LhpQcExport` dengan cara yang sama**

Modify `app/Exports/LhpQcExport.php`:

```php
        $q = TransaksiQc::with([
            'karyawanQc', 'partProses.part', 'shift', 'ngDetails.jenisNg',
        ]);
```

Ganti baris `$t->jenisNg?->kode ?? ''` menjadi:

```php
                $t->ngDetails
                    ->map(fn ($d) => ($d->jenisNg?->kode ?? '?').' ('.$d->qty.')')
                    ->implode('; '),
```

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgExportFormatTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Exports/ tests/Unit/NgExportFormatTest.php
git commit -m "feat(ng): kolom jenis NG di export jadi gabungan semua jenis

Format: 'DNT (5); CRK (2)'. Satu baris ekspor tetap satu sesi sehingga bentuk
file tidak berubah — hanya isinya lebih kaya. File ekspor kemungkinan dipakai
orang di luar sistem, jadi jumlah dan urutan kolom sengaja dipertahankan."
```

---

## Task 9: Migrasi data lama

**Files:**
- Create: `database/migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php`
- Modify: `.github/workflows/deploy.yml`
- Test: `tests/Feature/Input/NgLegacyMigrationTest.php`

**Interfaces:**
- Consumes: tabel detail dari Task 2.
- Produces: baris detail untuk data lama yang punya `id_jenis_ng`; baris tanpa jenis di-skip dan jumlahnya dicetak ke log deploy.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Feature/Input/NgLegacyMigrationTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NgLegacyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_baris_berjenis_dimigrasi_dan_baris_tanpa_jenis_dilewati(): void
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Legacy', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'LG-01', 'nama_mesin' => 'Press Legacy', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'LG-P1', 'part_name' => 'Legacy Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        $base = [
            'tanggal_produksi' => '2026-07-20',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => 100,
        ];

        $berjenis = TransaksiProduksi::create([...$base, 'qty_ng' => 5, 'id_jenis_ng' => $dented->id]);
        $tanpaJenis = TransaksiProduksi::create([...$base, 'qty_ng' => 3, 'id_jenis_ng' => null]);
        $tanpaNg = TransaksiProduksi::create([...$base, 'qty_ng' => 0, 'id_jenis_ng' => null]);

        // Kosongkan detail supaya kondisi awal benar-benar meniru pra-migrasi.
        TransaksiProduksiNg::query()->delete();

        $this->runLegacyMigration();

        $this->assertSame(1, TransaksiProduksiNg::where('id_transaksi_produksi', $berjenis->id)->count());
        $this->assertSame(5, (int) TransaksiProduksiNg::where('id_transaksi_produksi', $berjenis->id)->first()->qty);

        $this->assertSame(0, TransaksiProduksiNg::where('id_transaksi_produksi', $tanpaJenis->id)->count());
        $this->assertSame(0, TransaksiProduksiNg::where('id_transaksi_produksi', $tanpaNg->id)->count());

        // Migrasi tidak menyentuh qty_ng induk.
        $this->assertSame(3, (int) $tanpaJenis->fresh()->qty_ng);
    }

    /**
     * Menjalankan ulang logika migrasi data pada database test.
     * Migrasi sudah jalan saat RefreshDatabase, jadi test ini memanggil
     * logikanya kembali setelah menyiapkan data.
     */
    private function runLegacyMigration(): void
    {
        $path = database_path('migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php');
        $migration = require $path;
        $migration->up();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgLegacyMigrationTest`
Expected: FAIL — `failed to open stream: No such file or directory` (file migrasi belum ada).

- [ ] **Step 3: Buat migrasi data**

Create `database/migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Memindahkan NG gaya lama (satu jenis per baris) ke tabel detail.
 *
 * Baris dengan qty_ng > 0 tapi id_jenis_ng NULL SENGAJA di-skip: tidak ada
 * baris master karangan yang dibuat. Konsekuensinya SUM(detail) < qty_ng untuk
 * data historis; selisihnya dihitung saat query di Tahap 2 dan ditampilkan
 * sebagai "Tanpa jenis". Jumlah yang di-skip dicetak agar angkanya diketahui
 * sekali di awal, bukan tak terlihat.
 *
 * qty_ng induk TIDAK disentuh sama sekali.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migrateTable('transaksi_produksi', 'transaksi_produksi_ng', 'id_transaksi_produksi');
        $this->migrateTable('transaksi_qc', 'transaksi_qc_ng', 'id_transaksi_qc');
    }

    public function down(): void
    {
        // Detail hasil migrasi tidak dibedakan dari detail hasil input manual,
        // jadi rollback otomatis akan menghapus data yang sah. Sengaja no-op:
        // untuk membatalkan, drop tabel detail lewat migrasi pembuatnya.
    }

    private function migrateTable(string $parent, string $detail, string $foreignKey): void
    {
        $migrated = 0;

        DB::table($parent)
            ->select('id', 'qty_ng', 'id_jenis_ng')
            ->where('qty_ng', '>', 0)
            ->whereNotNull('id_jenis_ng')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use ($detail, $foreignKey, &$migrated) {
                $now = now();
                $payload = [];

                foreach ($rows as $row) {
                    $payload[] = [
                        $foreignKey => $row->id,
                        'id_jenis_ng' => $row->id_jenis_ng,
                        'qty' => (int) $row->qty_ng,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($payload !== []) {
                    DB::table($detail)->insertOrIgnore($payload);
                    $migrated += count($payload);
                }
            });

        $skipped = DB::table($parent)
            ->where('qty_ng', '>', 0)
            ->whereNull('id_jenis_ng')
            ->count();

        echo "  → {$parent}: {$migrated} baris NG dimigrasi, {$skipped} di-skip (qty_ng > 0 tanpa jenis NG)\n";
    }
};
```

Catatan: `DB::table()` mengabaikan soft delete secara alami, sehingga induk yang ter-soft-delete ikut termigrasi tanpa perlu `withTrashed()`.

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgLegacyMigrationTest`
Expected: PASS (1 test).

- [ ] **Step 5: Daftarkan migrasi di `deploy.yml`**

Modify `.github/workflows/deploy.yml` — ganti baris terakhir langkah Migrate:

```yaml
              --path=database/migrations/2026_07_28_000003_create_transaksi_qc_ng_table.php \
              --path=database/migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php"
```

- [ ] **Step 6: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap: 51 file — 11 dijalankan saat deploy, 40 baseline.`

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_28_000004_migrate_existing_ng_to_detail_tables.php tests/Feature/Input/NgLegacyMigrationTest.php .github/workflows/deploy.yml
git commit -m "feat(ng): migrasi NG gaya lama ke tabel detail

Baris ber-jenis dipindah satu-ke-satu. Baris dengan qty_ng > 0 tapi
id_jenis_ng NULL sengaja di-skip — tidak ada baris master karangan yang
dibuat. Jumlah yang di-skip dicetak ke log deploy supaya angkanya diketahui
sekali di awal, bukan jadi selisih tak terlihat.

qty_ng induk tidak disentuh. down() sengaja no-op: detail hasil migrasi tidak
bisa dibedakan dari hasil input manual, jadi rollback otomatis akan menghapus
data yang sah."
```

---

## Task 10: Verifikasi menyeluruh

**Files:**
- Tidak ada file baru. Task ini gerbang sebelum PR.

**Interfaces:**
- Consumes: seluruh output Task 1–9.
- Produces: bukti bahwa suite hijau dan cakupan migrasi lengkap.

- [ ] **Step 1: Jalankan seluruh test suite**

Run: `php artisan test`
Expected: PASS. Jumlah test naik dari 471 menjadi sekitar 494 (23 test baru: 2+3+5+6+6+1+2+1 = 26 dikurangi yang tergabung). Angka pastinya boleh berbeda — yang wajib: **nol failure, nol error**.

- [ ] **Step 2: Verifikasi invarian tidak bocor lewat pembaca lama**

Run: `php artisan test --filter="DashboardServiceTest|OeeServiceTest|ChatbotToolsTest"`
Expected: PASS — ketiganya membaca `qty_ng` dan harus tetap hijau.

- [ ] **Step 3: Jalankan gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap: 51 file — 11 dijalankan saat deploy, 40 baseline.`

- [ ] **Step 4: Format**

Run: `vendor/bin/pint app tests`
Expected: `PASS`.

- [ ] **Step 5: Pastikan tidak ada migrasi baru yang masuk baseline**

Run: `grep -c "2026_07_28" database/migrations/.deploy-baseline`
Expected: `0` — migrasi baru tidak boleh ada di baseline.

- [ ] **Step 6: Commit hasil format bila ada perubahan**

```bash
git add -A
git commit -m "style: pint" || echo "tidak ada perubahan format"
```

- [ ] **Step 7: Push dan buka PR**

```bash
git push -u origin feat/ng-detail-multi-jenis
```

Buat PR dengan judul `feat(ng): fondasi data NG multi-jenis (Tahap 1)`. Body wajib memuat:
- ringkasan keputusan (dua tabel, tanpa SoftDeletes, `qty_ng` turunan)
- daftar 4 migrasi dan konfirmasi semuanya terdaftar di `deploy.yml`
- output `./scripts/check-migration-coverage.sh`
- catatan bahwa dashboard NG adalah Tahap 2, di luar PR ini

---

## Catatan untuk implementer

**Jangan mengubah test agar cocok dengan kode.** Bila test gagal, yang salah kodenya — kecuali ekspektasi test memang keliru, dan dalam kasus itu jelaskan alasannya di commit.

**Urutan task tidak boleh ditukar.** Task 5–6 bergantung pada Task 3 dan 4. Task 9 bergantung pada Task 2.

**Satu hal yang paling mudah salah:** menghapus `qty_ng` dari array atribut di controller/import tapi lupa memanggil `NgDetailService`. Akibatnya `qty_ng` jadi NULL diam-diam dan seluruh VCB Quality, OEE, serta SPH ikut salah — tanpa satu pun error. Setiap jalur tulis harus berakhir di service.

**Bila `php artisan test` gagal di tempat yang tidak disebut plan ini,** hentikan dan laporkan. Jangan menambal dengan mengubah test lama.
