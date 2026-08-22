# Halaman Batch Input LHP Produksi — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti laci input LHP Produksi dengan halaman penuh bermode batch — isi satu baris, SAVE TO DRAFT, ulang, lalu SUBMIT DRAFT sekali jalan.

**Architecture:** Draft hidup di `localStorage` (nol tabel, nol migrasi). SUBMIT mengirim satu form POST berisi `rows[]` ke endpoint baru `input.produksi.batch`, yang membungkus seluruh baris dalam satu `DB::transaction` dan memanggil layanan penulis yang sudah ada per baris. Laci di `index.blade.php` dicabut sehingga form penulis turun dari tiga jadi dua.

**Tech Stack:** Laravel 13, PHP 8.4 (CI/prod) / 8.5.4 (lokal), Blade, Alpine.js 3.14.1, Bootstrap 5.3.3, PHPUnit dengan SQLite in-memory.

**Spec:** `docs/superpowers/specs/2026-08-22-lhp-produksi-batch-input-design.md`

## Global Constraints

- **Nol migrasi.** Tidak ada kolom, tabel, atau indeks baru. `./scripts/check-migration-coverage.sh` harus melaporkan jumlah yang sama seperti sebelum pekerjaan ini: `54 file — 14 dijalankan saat deploy, 40 baseline`.
- **Nol perubahan pada perhitungan.** `git diff main --stat -- app/Services/MaintenanceDashboardService.php app/Services/MaintenanceDiesDashboardService.php app/Services/OeeService.php app/Services/OverviewService.php app/Services/KalenderKerjaService.php app/Services/DashboardService.php` harus kosong di akhir pekerjaan.
- **Form Edit tidak disentuh.** `resources/views/input/produksi/edit.blade.php` nol baris berubah.
- **`taField()` satu definisi** di `resources/views/layouts/app.blade.php`, dijaga `KomponenBersamaTest`. Jangan membuat varian typeahead baru.
- **`<option>` proses wajib membawa `data-progressive`.** Tanpa itu pratinjau Std/Over di blok dandori diam-diam memakai `std_menit_default`.
- **Blok NG dan Dandori memakai `.input-panel`**, dijaga `test_blok_ng_dan_dandori_memakai_kemasan_yang_sama`.
- **Direktif Blade tidak boleh menempel huruf.** Sapuan wajib bersih: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
- **Pint hanya memformat `app/` dan `tests/`.** Blade tidak diformat otomatis.
- Perintah gerbang: `php artisan test`, `vendor/bin/pint app tests`, `./scripts/check-migration-coverage.sh`

## File Structure

**Dibuat:**

| Berkas | Tanggung jawab |
|---|---|
| `app/Services/ProduksiWriteService.php` | Menulis SATU baris LHP + rincian NG + rincian dandori. Tidak membuka transaksi — pemanggil yang membukanya. |
| `app/Http/Requests/StoreProduksiBatchRequest.php` | Validasi payload `rows[]`: aturan per baris, penjaga hitungan NG/dandori per baris, deteksi jam tumpang tindih antar baris. |
| `tests/Feature/Input/ProduksiBatchTest.php` | Test endpoint batch: banyak baris, rollback, indeks baris gagal, tumpang tindih. |
| `tests/Feature/Input/HalamanBatchProduksiTest.php` | Test markup halaman batch dan hilangnya form penulis dari `index`. |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `app/Http/Controllers/Input/ProduksiController.php` | `store()` didelegasikan ke layanan baru lalu dicabut; `batch()` ditambahkan. |
| `routes/web.php` | `produksi/batch` ditambahkan; `store` dikeluarkan dari `Route::resource`. |
| `resources/views/input/produksi/create.blade.php` | Dirombak jadi halaman batch. |
| `resources/views/input/produksi/index.blade.php` | Markup laci input dicabut; tombol INPUT BARU jadi tautan. |
| `tests/Feature/Input/ProduksiStoreTest.php` | 6 pemanggilan `store` → payload batch. |
| `tests/Feature/Input/NgMultiJenisProduksiTest.php` | 9 pemanggilan `store` → payload batch. |
| `tests/Feature/Input/DandoriDariLhpTest.php` | 9 pemanggilan `store` → payload batch; 2 test yang menjangkar laci dipindah. |

---

### Task 1: Layanan penulis satu baris

Logika tulis sekarang hidup di dalam `ProduksiController::store()`. Mode batch butuh logika yang sama per baris. Diangkat dulu ke layanan supaya batch tidak menyalinnya — jalur tulis yang disalin sudah berkali-kali menyimpang di repo ini.

**Files:**
- Create: `app/Services/ProduksiWriteService.php`
- Create: `tests/Feature/Input/ProduksiWriteServiceTest.php`
- Modify: `app/Http/Controllers/Input/ProduksiController.php`

**Interfaces:**
- Consumes: `ProduksiService::calculateActualCycleTime(string, string, int): float`, `NgDetailService::syncProduksi(TransaksiProduksi, array): void`, `DandoriRincianService::simpan(array, array): int`
- Produces: `ProduksiWriteService::simpan(array $baris): TransaksiProduksi`

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/Input/ProduksiWriteServiceTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\ProduksiWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Satu jalur tulis untuk satu baris LHP. Dipakai endpoint batch; jalur yang
 * disalin sudah berkali-kali menyimpang di repo ini.
 */
class ProduksiWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $karyawanLain = MasterKaryawan::create(['nik' => 'K2', 'nama_karyawan' => 'OPERATOR DUA', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);
        $jenisNg = MasterJenisNg::create(['kode' => 'NG1', 'jenis_ng' => 'GORES']);
        $losstime = MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 45]);

        $this->ref = compact('shift', 'karyawan', 'karyawanLain', 'mesin', 'proses', 'jenisNg', 'losstime');

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_mesin' => $this->ref['mesin']->id,
            'id_part_proses' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '15:00',
            'qty_aktual' => 4000,
        ], $override);
    }

    public function test_menulis_baris_lhp_dengan_cycle_time_terhitung(): void
    {
        $produksi = app(ProduksiWriteService::class)->simpan($this->baris());

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(4000, $produksi->qty_aktual);
        $this->assertSame(auth()->id(), $produksi->created_by);
        // 8 jam / 4000 pcs = 7,2 detik per pcs.
        $this->assertEqualsWithDelta(7.2, (float) $produksi->actual_cycle_time, 0.01);
    }

    public function test_menulis_rincian_ng_bersama_barisnya(): void
    {
        $produksi = app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_ng' => 1,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
        ]));

        $this->assertSame(1, $produksi->ngDetails()->count());
    }

    public function test_menulis_rincian_dandori_dengan_field_sesi_yang_sama(): void
    {
        app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50]],
        ]));

        $baris = TransaksiDandoriRincian::first();
        $this->assertNotNull($baris);
        $this->assertSame($this->ref['mesin']->id, $baris->id_mesin);
        $this->assertSame($this->ref['proses']->id, $baris->id_part_proses);
        $this->assertEquals(30, $baris->std_menit);
        $this->assertEquals(20, $baris->over_menit);
    }

    public function test_operator_dandori_bisa_beda_dari_operator_lhp(): void
    {
        app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori_karyawan_id' => $this->ref['karyawanLain']->id,
            'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50]],
        ]));

        $this->assertSame($this->ref['karyawanLain']->id, TransaksiDandoriRincian::first()->id_karyawan);
    }

    public function test_kunci_bantu_tidak_ikut_tertulis_ke_kolom(): void
    {
        // ada_ng, ng_count, ada_dandori, dandori_count, dandori_karyawan_id
        // adalah kunci bantu form — bukan kolom. Kalau ikut masuk ke create(),
        // penyimpanan meledak dengan "column not found".
        $produksi = app(ProduksiWriteService::class)->simpan($this->baris([
            'ada_ng' => 1,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
            'ada_dandori' => 1,
            'dandori_count' => 0,
            'dandori' => [],
        ]));

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertNull($produksi->getAttribute('ng_count'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=ProduksiWriteServiceTest
```

Expected: FAIL — `Target class [App\Services\ProduksiWriteService] does not exist.`

- [ ] **Step 3: Tulis layanannya**

Buat `app/Services/ProduksiWriteService.php`:

```php
<?php

namespace App\Services;

use App\Models\TransaksiProduksi;

/**
 * Menulis SATU baris LHP Produksi beserta rincian NG dan dandori-nya.
 *
 * Beda dari ProduksiService, yang hanya menghitung (durasi, cycle time, target
 * qty) dan tidak pernah menyentuh database.
 *
 * Sengaja TIDAK membuka transaksi sendiri: pemanggil yang menentukan batasnya,
 * supaya satu batch berisi banyak baris bisa dibatalkan utuh.
 */
class ProduksiWriteService
{
    public function __construct(
        private ProduksiService $produksiService,
        private NgDetailService $ngDetailService,
        private DandoriRincianService $dandoriRincianService,
    ) {}

    /**
     * @param  array  $baris  payload satu baris yang sudah tervalidasi
     */
    public function simpan(array $baris): TransaksiProduksi
    {
        $ngRows = $baris['ng'] ?? [];
        $dandoriRows = $baris['dandori'] ?? [];
        $dandoriKaryawan = $baris['dandori_karyawan_id'] ?? $baris['id_karyawan'];

        /* Kunci bantu form, bukan kolom tabel. */
        unset(
            $baris['ng'], $baris['ada_ng'], $baris['ng_count'],
            $baris['dandori'], $baris['ada_dandori'], $baris['dandori_count'],
            $baris['dandori_karyawan_id'],
        );

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $baris['jam_start'].':00',
            $baris['jam_finish'].':00',
            $baris['qty_aktual']
        );

        $produksi = TransaksiProduksi::create([
            ...$baris,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);

        $this->ngDetailService->syncProduksi($produksi, $ngRows);

        /*
         * Baris dandori sengaja TIDAK menyimpan tautan ke baris LHP: satu
         * kombinasi tanggal+shift+mesin+proses bisa punya sampai lima baris
         * LHP, jadi tautan apa pun butuh aturan sewenang-wenang. Ia cuma
         * menyalin field sesi — bentuk barisnya identik dengan yang ditulis
         * form Dandori, sehingga tidak satu pun perhitungan berubah.
         */
        $this->dandoriRincianService->simpan([
            'tanggal' => $baris['tanggal_produksi'],
            'id_shift' => $baris['id_shift'],
            'id_karyawan' => $dandoriKaryawan,
            'id_mesin' => $baris['id_mesin'],
            'id_part_proses' => $baris['id_part_proses'],
        ], $dandoriRows);

        return $produksi;
    }
}
```

- [ ] **Step 4: Alihkan controller ke layanan itu**

Di `app/Http/Controllers/Input/ProduksiController.php`, tambahkan import:

```php
use App\Services\ProduksiWriteService;
```

Tambahkan ke konstruktor (setelah `private DandoriRincianService $dandoriRincianService,`):

```php
        private ProduksiWriteService $produksiWriteService,
```

Ganti seluruh isi `store()` menjadi:

```php
    public function store(StoreProduksiRequest $request)
    {
        DB::transaction(fn () => $this->produksiWriteService->simpan($request->validated()));

        return redirect()->route('input.produksi.index')
            ->with('success', 'LHP Produksi berhasil disimpan.');
    }
```

Import `MasterLosstime` dan `TransaksiProduksi` di controller tetap dipakai method lain — jangan dicabut tanpa memeriksa.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=ProduksiWriteServiceTest
```
Expected: PASS — 5 test.

```bash
php artisan test
```
Expected: PASS — seluruh suite hijau. Perilaku `store()` tidak berubah, jadi `ProduksiStoreTest`, `NgMultiJenisProduksiTest`, dan `DandoriDariLhpTest` harus tetap hijau **tanpa disentuh**. Kalau ada yang merah, layanannya tidak setara dengan kode lama.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app tests
git add app/Services/ProduksiWriteService.php app/Http/Controllers/Input/ProduksiController.php tests/Feature/Input/ProduksiWriteServiceTest.php
git commit -m "refactor(produksi): jalur tulis satu baris jadi layanan tersendiri"
```

---

### Task 2: Validasi payload batch

**Files:**
- Create: `app/Http/Requests/StoreProduksiBatchRequest.php`
- Create: `tests/Feature/Input/ProduksiBatchTest.php`

**Interfaces:**
- Consumes: —
- Produces: `StoreProduksiBatchRequest` dengan kunci payload `rows` (array of baris). Task 3 memakainya lewat `$request->validated()['rows']`.

Aturan per baris sama persis dengan `StoreProduksiRequest`. Yang baru cuma dua: penjaga hitungan dipindah ke dalam tiap baris, dan deteksi jam tumpang tindih antar baris.

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/Input/ProduksiBatchTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Satu submit membawa banyak baris. Semua-atau-tidak-sama-sekali: draft di
 * browser tetap utuh saat gagal, jadi server tidak boleh menyimpan separuh.
 */
class ProduksiBatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $mesinLain = MasterMesin::create(['id_mesin' => 'A-002', 'nama_mesin' => 'MESIN B', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);
        $jenisNg = MasterJenisNg::create(['kode' => 'NG1', 'jenis_ng' => 'GORES']);
        $losstime = MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 45]);

        $this->ref = compact('shift', 'karyawan', 'mesin', 'mesinLain', 'proses', 'jenisNg', 'losstime');

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_mesin' => $this->ref['mesin']->id,
            'id_part_proses' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 1000,
            'ng_count' => 0,
        ], $override);
    }

    private function kirim(array $rows)
    {
        return $this->post(route('input.produksi.batch'), ['rows' => $rows]);
    }

    public function test_satu_submit_menulis_semua_baris(): void
    {
        $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00']),
            $this->baris(['jam_start' => '11:00', 'jam_finish' => '13:00']),
        ])->assertRedirect(route('input.produksi.index'))->assertSessionHas('success');

        $this->assertSame(3, TransaksiProduksi::count());
    }

    public function test_pesan_sukses_menyebut_jumlah_baris(): void
    {
        $this->kirim([$this->baris(), $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00'])]);

        $this->assertStringContainsString('2 baris', session('success'));
    }

    public function test_rincian_menempel_pada_barisnya_masing_masing(): void
    {
        $this->kirim([
            $this->baris([
                'ada_ng' => 1, 'ng_count' => 1,
                'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
            ]),
            $this->baris([
                'jam_start' => '09:00', 'jam_finish' => '11:00',
                'ada_dandori' => 1, 'dandori_count' => 1,
                'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50]],
            ]),
        ])->assertRedirect();

        $baris = TransaksiProduksi::orderBy('id')->get();
        $this->assertSame(1, $baris[0]->ngDetails()->count());
        $this->assertSame(0, $baris[1]->ngDetails()->count());
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        // Baris ke-2 memakai id_part_proses yang tidak ada.
        $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00', 'id_part_proses' => 999999]),
            $this->baris(['jam_start' => '11:00', 'jam_finish' => '13:00']),
        ])->assertSessionHasErrors('rows.1.id_part_proses');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    {
        // Lolos validasi tapi meledak saat menulis: qty_aktual di luar jangkauan
        // kolom. Membuktikan pembatalan transaksi, bukan sekadar urutan validasi.
        $rows = [
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00', 'qty_aktual' => 999999999999999]),
        ];

        try {
            $this->kirim($rows);
        } catch (\Throwable $e) {
            // Ledakan penyimpanan memang diharapkan di sini.
        }

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_hitungan_ng_tidak_cocok_ditolak_per_baris(): void
    {
        $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00', 'ada_ng' => 1, 'ng_count' => 2, 'ng' => [
                ['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5],
            ]]),
        ])->assertSessionHasErrors('rows.1.ng_count');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_hitungan_dandori_tidak_cocok_ditolak_per_baris(): void
    {
        $this->kirim([
            $this->baris(['ada_dandori' => 1, 'dandori_count' => 2, 'dandori' => [
                ['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50],
            ]]),
        ])->assertSessionHasErrors('rows.0.dandori_count');
    }

    public function test_jam_tumpang_tindih_di_mesin_sama_ditolak(): void
    {
        $this->kirim([
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '15:00']),
            $this->baris(['jam_start' => '08:00', 'jam_finish' => '12:00']),
        ])->assertSessionHasErrors('rows.1.jam_start');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_jam_bersentuhan_tapi_tidak_tumpang_tindih_diterima(): void
    {
        // 07:00–09:00 lalu 09:00–11:00 bukan tumpang tindih.
        $this->kirim([
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '09:00']),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00']),
        ])->assertRedirect();

        $this->assertSame(2, TransaksiProduksi::count());
    }

    public function test_jam_sama_di_mesin_berbeda_diterima(): void
    {
        $this->kirim([
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '15:00']),
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '15:00', 'id_mesin' => $this->ref['mesinLain']->id]),
        ])->assertRedirect();

        $this->assertSame(2, TransaksiProduksi::count());
    }

    public function test_baris_lewat_tengah_malam_dihitung_utuh(): void
    {
        // 22:00–06:00 menempati 22:00 hari itu sampai 06:00 esoknya. Baris
        // kedua 23:00–01:00 berada di dalamnya, jadi bentrok.
        $this->kirim([
            $this->baris(['jam_start' => '22:00', 'jam_finish' => '06:00']),
            $this->baris(['jam_start' => '23:00', 'jam_finish' => '01:00']),
        ])->assertSessionHasErrors('rows.1.jam_start');
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->kirim([$this->baris()])->assertForbidden();

        $this->assertSame(0, TransaksiProduksi::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=ProduksiBatchTest
```

Expected: FAIL — `Route [input.produksi.batch] not defined.`

- [ ] **Step 3: Tulis FormRequest-nya**

Buat `app/Http/Requests/StoreProduksiBatchRequest.php`:

```php
<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload batch: rows[] berisi baris yang bentuknya sama persis dengan payload
 * satu baris pada StoreProduksiRequest.
 */
class StoreProduksiBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rows' => 'required|array|min:1|max:50',
            'rows.*.tanggal_produksi' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
            'rows.*.id_shift' => 'required|exists:master_shift,id',
            'rows.*.id_karyawan' => 'required|exists:master_karyawan,id',
            'rows.*.id_mesin' => 'required|exists:master_mesin,id',
            'rows.*.id_part_proses' => 'required|exists:master_part_proses,id',
            'rows.*.jam_start' => 'required|date_format:H:i',
            'rows.*.jam_finish' => 'required|date_format:H:i',
            'rows.*.qty_aktual' => 'required|integer|min:1',
            'rows.*.ada_ng' => 'nullable|boolean',
            'rows.*.ng_count' => 'required|integer|min:0',
            'rows.*.ng' => 'nullable|array',
            'rows.*.ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id|distinct',
            'rows.*.ng.*.qty' => 'required|integer|min:1',
            'rows.*.ada_dandori' => 'nullable|boolean',
            'rows.*.dandori_count' => 'nullable|integer|min:0',
            'rows.*.dandori' => 'nullable|array',
            'rows.*.dandori.*.id_losstime' => 'required|integer|exists:master_losstime,id|distinct',
            'rows.*.dandori.*.actual_menit' => 'required|numeric|min:0.01',
            'rows.*.dandori_karyawan_id' => 'nullable|integer|exists:master_karyawan,id',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rows = $this->input('rows');
            if (! is_array($rows)) {
                return;
            }

            foreach ($rows as $i => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $this->periksaHitungan($validator, (int) $i, $row, 'ng');
                $this->periksaHitungan($validator, (int) $i, $row, 'dandori');
            }

            $bentrok = $this->cariBentrok($rows);
            if ($bentrok !== null) {
                [$a, $b] = $bentrok;
                $validator->errors()->add(
                    "rows.{$b}.jam_start",
                    'Jam baris '.($b + 1).' tumpang tindih dengan baris '.($a + 1)
                    .' pada mesin dan tanggal yang sama.'
                );
            }
        });
    }

    /**
     * Hitungan yang dikirim membuktikan Alpine hidup, bukan bahwa baris
     * rinciannya benar-benar sampai. Payload dengan ng_count=2 tapi ng[]
     * hilang akan lolos aturan di atas lalu menyimpan NOL baris rincian tanpa
     * satu pun error — operator mengira sudah tercatat.
     */
    private function periksaHitungan($validator, int $i, array $row, string $kunci): void
    {
        $kunciHitungan = $kunci.'_count';

        if (! array_key_exists($kunciHitungan, $row)) {
            return;
        }

        $diminta = (int) ($row[$kunciHitungan] ?? 0);
        $terkirim = is_array($row[$kunci] ?? null) ? count($row[$kunci]) : 0;

        if ($diminta !== $terkirim) {
            $validator->errors()->add(
                "rows.{$i}.{$kunciHitungan}",
                'Baris '.($i + 1).": jumlah rincian yang tercatat ({$diminta}) tidak sama dengan yang terkirim ({$terkirim}). "
                .'Coba simpan ulang.'
            );
        }
    }

    /**
     * Mencari sepasang baris pada mesin dan tanggal yang sama yang jamnya
     * beririsan. Jam finish lebih kecil dari start berarti melewati tengah
     * malam — rentangnya dilanjutkan ke hari berikutnya, sama seperti
     * ProduksiService::calculateDurationSeconds() menghitung durasi.
     *
     * Hanya antar baris DALAM satu batch. Data yang sudah tersimpan tidak
     * diperiksa: baris lama yang tumpang tindih sudah ada di prod, dan
     * menolaknya akan mengunci operator dari mengoreksi datanya sendiri.
     *
     * @return array{0:int,1:int}|null indeks pasangan pertama yang bentrok
     */
    private function cariBentrok(array $rows): ?array
    {
        $rentang = [];

        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['tanggal_produksi', 'jam_start', 'jam_finish', 'id_mesin'] as $wajib) {
                if (empty($row[$wajib])) {
                    continue 2;
                }
            }

            $mulai = strtotime($row['tanggal_produksi'].' '.$row['jam_start']);
            $selesai = strtotime($row['tanggal_produksi'].' '.$row['jam_finish']);
            if ($mulai === false || $selesai === false) {
                continue;
            }
            if ($selesai <= $mulai) {
                $selesai += 86400;
            }

            $rentang[(int) $i] = ['mesin' => $row['id_mesin'], 'mulai' => $mulai, 'selesai' => $selesai];
        }

        foreach ($rentang as $i => $a) {
            foreach ($rentang as $j => $b) {
                if ($j <= $i || $a['mesin'] != $b['mesin']) {
                    continue;
                }
                if ($a['mulai'] < $b['selesai'] && $b['mulai'] < $a['selesai']) {
                    return [$i, $j];
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 4: Run tests to verify they still fail on the route**

```bash
php artisan test --filter=ProduksiBatchTest
```
Expected: masih FAIL — `Route [input.produksi.batch] not defined.` Route dan controller menyusul di Task 3.

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/StoreProduksiBatchRequest.php tests/Feature/Input/ProduksiBatchTest.php
git commit -m "test(produksi): aturan validasi payload batch beserta testnya"
```

---

### Task 3: Endpoint batch

**Files:**
- Modify: `app/Http/Controllers/Input/ProduksiController.php`
- Modify: `routes/web.php:100-105`

**Interfaces:**
- Consumes: `StoreProduksiBatchRequest`, `ProduksiWriteService::simpan(array): TransaksiProduksi`
- Produces: route bernama `input.produksi.batch` (POST `input/produksi/batch`)

- [ ] **Step 1: Tambahkan route**

Di `routes/web.php`, di dalam grup `Route::middleware('role:admin,staff_produksi')->prefix('input')->name('input.')`, tambahkan tepat sebelum baris `Route::resource('produksi', ...)`:

```php
        Route::post('produksi/batch', [ProduksiController::class, 'batch'])->name('produksi.batch');
```

- [ ] **Step 2: Tambahkan method controller**

Di `app/Http/Controllers/Input/ProduksiController.php`, tambahkan import:

```php
use App\Http\Requests\StoreProduksiBatchRequest;
```

Tambahkan method tepat setelah `store()`:

```php
    /**
     * Menyimpan banyak baris LHP sekali jalan dari halaman batch.
     *
     * Satu transaksi untuk seluruh baris: draft di browser tetap utuh saat
     * gagal, jadi menyimpan separuh akan membuat operator mengirim ulang baris
     * yang sudah masuk.
     */
    public function batch(StoreProduksiBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->produksiWriteService->simpan($row);
            }
        });

        $jumlah = count($rows);

        return redirect()->route('input.produksi.index')
            ->with('success', "{$jumlah} baris LHP Produksi berhasil disimpan.");
    }
```

- [ ] **Step 3: Run tests to verify they pass**

```bash
php artisan test --filter=ProduksiBatchTest
```
Expected: PASS — 13 test.

```bash
php artisan test
```
Expected: PASS — seluruh suite hijau.

- [ ] **Step 4: Commit**

```bash
vendor/bin/pint app tests
git add app/Http/Controllers/Input/ProduksiController.php routes/web.php
git commit -m "feat(produksi): endpoint batch menyimpan banyak baris satu transaksi"
```

---

### Task 4: Halaman batch

**Files:**
- Modify: `resources/views/input/produksi/create.blade.php` (rombak total)
- Create: `tests/Feature/Input/HalamanBatchProduksiTest.php`

**Interfaces:**
- Consumes: route `input.produksi.batch`; partial `input.partials.ng-rows` dan `input.partials.dandori-rows`; komponen JS `taField()` di layout
- Produces: halaman di route `input.produksi.create` dengan komponen Alpine `lhpBatch()`

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/Input/HalamanBatchProduksiTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchProduksiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);
        MasterJenisNg::create(['kode' => 'NG1', 'jenis_ng' => 'GORES']);
        MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 45]);

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function halaman(): string
    {
        return $this->get(route('input.produksi.create'))->assertOk()->getContent();
    }

    public function test_form_batch_mengirim_ke_endpoint_batch(): void
    {
        $this->assertStringContainsString(route('input.produksi.batch'), $this->halaman());
    }

    public function test_halaman_punya_tabel_draft_dan_tombolnya(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('DRAFT TERSIMPAN', $html);
        $this->assertStringContainsString('SAVE TO DRAFT', $html);
        $this->assertStringContainsString('SUBMIT DRAFT', $html);
    }

    public function test_halaman_memuat_blok_ng_dan_dandori(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('name="ada_ng"', $html);
        $this->assertStringContainsString('name="ada_dandori"', $html);
        $this->assertStringContainsString('class="ng-rows"', $html);
        $this->assertStringContainsString('class="dandori-rows"', $html);
    }

    public function test_pilihan_proses_membawa_penanda_progressive(): void
    {
        // Tanpa penanda ini pratinjau Std/Over di blok dandori diam-diam selalu
        // memakai std_menit_default.
        $this->assertStringContainsString('data-progressive', $this->halaman());
    }

    public function test_kolom_dandori_ada_di_tabel_draft(): void
    {
        // Satu baris bisa punya NG dan dandori sekaligus; rancangan awal cuma
        // punya kolom NG.
        $html = $this->halaman();

        $this->assertStringContainsString('>Dandori<', $html);
        $this->assertStringContainsString('>Jenis NG<', $html);
    }

    public function test_kunci_draft_dipisah_per_pengguna(): void
    {
        // Komputer di lantai produksi dipakai bergantian; kunci bersama membuat
        // draft operator A muncul di layar operator B.
        $this->assertStringContainsString('optima_draft_lhp_produksi_u'.auth()->id(), $this->halaman());
    }

    public function test_guest_tidak_bisa_membuka_halaman_batch(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->get(route('input.produksi.create'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter=HalamanBatchProduksiTest
```
Expected: FAIL — halaman `create` masih form satu baris, `DRAFT TERSIMPAN` tidak ada.

- [ ] **Step 3: Rombak halaman create**

Ganti seluruh isi `resources/views/input/produksi/create.blade.php` dengan:

```blade
@extends('layouts.app')

@section('title', 'Input LHP Produksi')

@php
    $_taKaryawan = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
    $_taMesin = $mesin->map(fn ($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $parts->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->proses->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
            'sph_standar_produksi' => (float) $pr->sph_standar_produksi,
        ])->values(),
    ])->values();
    $_shiftData = $shifts->map(fn ($s) => ['id' => $s->id, 'nama' => $s->nama_shift])->values();
@endphp

@section('content')
<div class="erp-panel" x-data="lhpBatch()" x-init="mulai()">
    <div class="erp-panel-header">Input LHP Produksi</div>
    <div class="erp-panel-body">

        <template x-if="pesan">
            <div class="alert alert-info py-2" x-text="pesan"></div>
        </template>

        @if ($errors->any())
            <div class="alert alert-danger py-2">
                <div class="fw-semibold mb-1">Draft ditolak server:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $pesanGalat)
                        <li>{{ $pesanGalat }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ══ FORM SATU BARIS ══ --}}
        <form id="formBaris" novalidate @submit.prevent="simpanKeDraft()">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi" class="form-control"
                           value="{{ now()->format('Y-m-d') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select">
                        <option value="">-- Pilih Shift --</option>
                        @foreach ($shifts as $s)
                            <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3" x-data="taField(taKaryawan)">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input" x-model="query"
                               @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik nama operator...">
                        <input type="hidden" name="id_karyawan" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="col-md-3" x-data="taField(taMesin)">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input" x-model="query"
                               @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik ID atau nama mesin...">
                        <input type="hidden" name="id_mesin" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="col-md-4" x-data="partPicker()">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input" x-model="query"
                               @focus="open = true; idx = -1" @blur="tutup()" @keydown="tombol($event)"
                               @input="val = null; open = true; idx = -1"
                               placeholder="Ketik part no atau nama...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="tersaring.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in tersaring" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pilih(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>

                    <label class="form-label mt-2">Part No.</label>
                    <input type="text" class="form-control" id="partNoTampil" :value="partNo" readonly
                           placeholder="Terisi otomatis dari Part">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select" :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Part dulu --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            {{-- data-progressive dibaca blok dandori untuk memilih
                                 std_menit_progressive. Penanda, bukan teks pilihan. --}}
                            <option :value="p.id"
                                    :data-progressive="p.nama_proses.trim().toUpperCase() === 'PROGRESSIVE' ? '1' : '0'"
                                    x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_produksi})`">
                            </option>
                        </template>
                    </select>
                </div>

                <div class="col-md-4">
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                            <input type="time" name="jam_start" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                            <input type="time" name="jam_finish" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Qty OK <span class="text-danger">*</span></label>
                            <input type="number" name="qty_aktual" class="form-control" min="1">
                        </div>
                    </div>
                </div>

                {{-- Ada NG? --}}
                <div class="col-12" x-data="{ adaNg: false }">
                    <div class="input-panel">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_ng" id="ada_ng"
                                   value="1" x-model="adaNg"
                                   @change="if (!adaNg) { const w = $el.closest('[x-data]').querySelector('.ng-rows'); if (w) Alpine.$data(w).rows = []; }">
                            <label class="form-check-label fw-semibold" for="ada_ng"
                                   style="font-size:0.875rem;color:#374151">
                                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Ada NG Proses?
                            </label>
                        </div>

                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', ['jenisNg' => $jenisNg, 'initial' => []])
                        </div>
                    </div>
                </div>

                {{-- Ada Dandori? --}}
                <div class="col-12" x-data="{ adaDandori: false }">
                    <div class="input-panel">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_dandori"
                                   id="ada_dandori" value="1" x-model="adaDandori"
                                   @change="dandoriSync($el, adaDandori)">
                            <label class="form-check-label fw-semibold" for="ada_dandori"
                                   style="font-size:0.875rem;color:#374151">
                                <i class="bi bi-tools me-1 text-secondary"></i>Ada Dandori?
                            </label>
                        </div>

                        <div x-show="adaDandori" x-cloak class="mt-2">
                            @include('input.partials.dandori-rows', [
                                'losstimes' => $losstimes,
                                'karyawan' => $karyawan,
                                'initial' => [],
                            ])
                        </div>
                    </div>
                </div>
            </div>

            <template x-if="galat">
                <div class="alert alert-danger py-2 mt-3 mb-0" x-text="galat"></div>
            </template>

            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
        </form>

        <hr class="my-4">

        {{-- ══ DRAFT TERSIMPAN ══ --}}
        <div class="fw-semibold mb-2">DRAFT TERSIMPAN <span x-text="`(${rows.length})`"></span></div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th><th>Shift</th><th>Operator</th><th>Mesin</th>
                        <th>Part No.</th><th>Part Name</th><th>Proses</th><th>Jam</th>
                        <th>Qty OK</th><th>Qty NG</th><th>Jenis NG</th><th>Dandori</th>
                        <th>SPH Std</th><th>SPH Act</th><th>W. Kerja</th><th>CT Act</th><th>%</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="18" class="text-center text-muted py-3">
                            Belum ada baris draft. Isi form di atas lalu klik SAVE TO DRAFT.
                        </td></tr>
                    </template>
                    <template x-for="(r, i) in rows" :key="r.uid">
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
                            <td x-text="r.tanggal_produksi"></td>
                            <td x-text="r.shift_teks"></td>
                            <td x-text="r.karyawan_teks"></td>
                            <td x-text="r.mesin_teks"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="`${r.jam_start}–${r.jam_finish}`"></td>
                            <td x-text="r.qty_aktual"></td>
                            <td x-text="r.ng.reduce((s, n) => s + Number(n.qty || 0), 0) || '—'"></td>
                            <td x-text="ringkasNg(r)"></td>
                            <td x-text="ringkasDandori(r)"></td>
                            <td x-text="r.sph_std.toFixed(0)"></td>
                            <td x-text="r.sph_act.toFixed(0)"></td>
                            <td x-text="r.jam_kerja.toFixed(2)"></td>
                            <td x-text="r.ct_act.toFixed(2)"></td>
                            <td x-text="`${r.persen.toFixed(0)}%`"></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="small text-muted mt-2">
            SPH Act, CT Act, dan % di atas adalah pratinjau. Angka yang tersimpan dihitung ulang di server.
        </div>

        {{-- ══ SUBMIT ══ --}}
        <form method="POST" action="{{ route('input.produksi.batch') }}" id="formBatch" class="mt-3">
            @csrf
            <template x-for="(r, i) in rows" :key="r.uid">
                <div>
                    <input type="hidden" :name="`rows[${i}][tanggal_produksi]`" :value="r.tanggal_produksi">
                    <input type="hidden" :name="`rows[${i}][id_shift]`" :value="r.id_shift">
                    <input type="hidden" :name="`rows[${i}][id_karyawan]`" :value="r.id_karyawan">
                    <input type="hidden" :name="`rows[${i}][id_mesin]`" :value="r.id_mesin">
                    <input type="hidden" :name="`rows[${i}][id_part_proses]`" :value="r.id_part_proses">
                    <input type="hidden" :name="`rows[${i}][jam_start]`" :value="r.jam_start">
                    <input type="hidden" :name="`rows[${i}][jam_finish]`" :value="r.jam_finish">
                    <input type="hidden" :name="`rows[${i}][qty_aktual]`" :value="r.qty_aktual">
                    <input type="hidden" :name="`rows[${i}][ng_count]`" :value="r.ng.length">
                    <input type="hidden" :name="`rows[${i}][ada_ng]`" :value="r.ng.length > 0 ? 1 : 0">
                    <template x-for="(n, j) in r.ng" :key="j">
                        <div>
                            <input type="hidden" :name="`rows[${i}][ng][${j}][id_jenis_ng]`" :value="n.id_jenis_ng">
                            <input type="hidden" :name="`rows[${i}][ng][${j}][qty]`" :value="n.qty">
                        </div>
                    </template>
                    <input type="hidden" :name="`rows[${i}][dandori_count]`" :value="r.dandori.length">
                    <input type="hidden" :name="`rows[${i}][ada_dandori]`" :value="r.dandori.length > 0 ? 1 : 0">
                    <input type="hidden" :name="`rows[${i}][dandori_karyawan_id]`" :value="r.dandori_karyawan_id">
                    <template x-for="(d, j) in r.dandori" :key="j">
                        <div>
                            <input type="hidden" :name="`rows[${i}][dandori][${j}][id_losstime]`" :value="d.id_losstime">
                            <input type="hidden" :name="`rows[${i}][dandori][${j}][actual_menit]`" :value="d.actual_menit">
                        </div>
                    </template>
                </div>
            </template>

            <div class="d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-success" :disabled="rows.length === 0">
                    <i class="bi bi-upload me-1"></i>SUBMIT DRAFT
                </button>
                <button type="button" class="btn btn-danger" @click="batal()">CANCEL</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taKaryawan  = @json($_taKaryawan);
const taMesin     = @json($_taMesin);
const taParts     = @json($_taParts);
const partsData   = @json($_partsData);
const shiftData   = @json($_shiftData);

/* Part memilih part sekaligus mengisi Part No dan daftar prosesnya. */
function partPicker() {
    return {
        query: '', val: null, open: false, idx: -1,
        partNo: '', partName: '', prosesList: [],

        get tersaring() {
            if (!this.query.trim()) return taParts.slice(0, 60);
            const q = this.query.toLowerCase();
            return taParts.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pilih(item) {
            this.query = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part = partsData.find(p => p.id == item.v);
            this.partNo     = part ? part.part_no : '';
            this.partName   = part ? part.part_name : '';
            this.prosesList = part ? part.proses : [];
        },
        tutup() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !taParts.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = ''; this.partNo = ''; this.partName = '';
                    this.prosesList = [];
                }
            }, 180);
        },
        tombol(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx + 1, this.tersaring.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.idx = Math.max(this.idx - 1, 0); }
            else if (e.key === 'Enter') { e.preventDefault(); if (this.idx >= 0 && this.tersaring[this.idx]) this.pilih(this.tersaring[this.idx]); }
            else if (e.key === 'Escape') { this.open = false; }
        },
    };
}

function lhpBatch() {
    /* Kunci dipisah per pengguna: komputer di lantai produksi dipakai
       bergantian, dan kunci bersama membuat draft operator A muncul di layar
       operator B. */
    const KUNCI = 'optima_draft_lhp_produksi_u{{ auth()->id() }}';
    let seq = 0;

    return {
        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],

        mulai() {
            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah) {
                    this.rows = JSON.parse(mentah).map(r => ({ ...r, uid: seq++ }));
                    if (this.rows.length) this.pesan = `${this.rows.length} baris draft dipulihkan.`;
                }
            } catch (e) {
                localStorage.removeItem(KUNCI);
            }
            this.$watch('rows', () => localStorage.setItem(KUNCI, JSON.stringify(this.rows)));

            /* Server menolak batch → tandai barisnya, draft JANGAN dibuang. */
            const gagal = @json($errors->keys());
            this.barisGagal = gagal
                .map(k => (k.match(/^rows\.(\d+)\./) || [])[1])
                .filter(v => v !== undefined)
                .map(Number);
        },

        form() { return document.getElementById('formBaris'); },

        nilai(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            return el ? el.value : '';
        },

        teksTerpilih(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            if (!el || el.tagName !== 'SELECT') return '';
            return el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : '';
        },

        blok(kelas) {
            const el = this.form().querySelector(kelas);
            return el ? Alpine.$data(el) : null;
        },

        picker() {
            return Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));
        },

        detik(jam) {
            const [h, m] = (jam || '0:0').split(':').map(Number);
            return (h * 3600) + (m * 60);
        },

        durasiDetik(mulai, selesai) {
            const a = this.detik(mulai), b = this.detik(selesai);
            return b >= a ? b - a : (86400 - a) + b;
        },

        /* Rentang mutlak, supaya baris yang melewati tengah malam ikut benar. */
        rentang(r) {
            const mulai = Date.parse(`${r.tanggal_produksi}T${r.jam_start}:00`);
            return { mulai, selesai: mulai + (this.durasiDetik(r.jam_start, r.jam_finish) * 1000) };
        },

        bentrokDengan(calon) {
            for (let i = 0; i < this.rows.length; i++) {
                const r = this.rows[i];
                if (r.id_mesin != calon.id_mesin) continue;
                const a = this.rentang(r), b = this.rentang(calon);
                if (a.mulai < b.selesai && b.mulai < a.selesai) return i;
            }
            return -1;
        },

        simpanKeDraft() {
            this.galat = '';
            const picker = this.picker();

            const calon = {
                uid: seq++,
                tanggal_produksi: this.nilai('tanggal_produksi'),
                id_shift: this.nilai('id_shift'),
                shift_teks: this.teksTerpilih('id_shift'),
                id_karyawan: this.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == this.nilai('id_karyawan')) || {}).t || '',
                id_mesin: this.nilai('id_mesin'),
                mesin_teks: (taMesin.find(m => m.v == this.nilai('id_mesin')) || {}).t || '',
                id_part_proses: this.nilai('id_part_proses'),
                proses_teks: this.teksTerpilih('id_part_proses'),
                part_no: picker.partNo,
                part_name: picker.partName,
                jam_start: this.nilai('jam_start'),
                jam_finish: this.nilai('jam_finish'),
                qty_aktual: this.nilai('qty_aktual'),
                ng: (this.blok('.ng-rows') || { rows: [] }).rows.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
                dandori: (this.blok('.dandori-rows') || { rows: [] }).rows.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
                dandori_karyawan_id: this.nilai('dandori_karyawan_id'),
            };

            const wajib = {
                tanggal_produksi: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
                id_mesin: 'Mesin', id_part_proses: 'Proses', jam_start: 'Jam Start',
                jam_finish: 'Jam Finish', qty_aktual: 'Qty OK',
            };
            for (const [kunci, label] of Object.entries(wajib)) {
                if (!calon[kunci]) { this.galat = `${label} wajib diisi.`; return; }
            }
            if (Number(calon.qty_aktual) < 1) { this.galat = 'Qty OK minimal 1.'; return; }
            if (this.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                this.galat = 'Jam Finish tidak boleh sama dengan Jam Start.'; return;
            }

            const bentrok = this.bentrokDengan(calon);
            if (bentrok >= 0) {
                this.galat = `Jam tumpang tindih dengan baris ${bentrok + 1} pada mesin dan tanggal yang sama.`;
                return;
            }

            /* Pratinjau; server yang menghitung angka yang tersimpan. */
            const jamKerja = this.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_produksi : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;

            this.rows.push(calon);
            this.pesan = '';
            this.barisGagal = [];
            this.resetSebagian();
        },

        /* Tanggal, Shift, Operator, dan Mesin sengaja dipertahankan: keempatnya
           konstan sepanjang satu sesi kerja. */
        resetSebagian() {
            const f = this.form();
            ['jam_start', 'jam_finish', 'qty_aktual'].forEach(n => {
                const el = f.querySelector(`[name="${n}"]`);
                if (el) el.value = '';
            });

            const picker = this.picker();
            picker.query = ''; picker.val = null; picker.partNo = '';
            picker.partName = ''; picker.prosesList = [];

            const proses = f.querySelector('[name="id_part_proses"]');
            if (proses) proses.value = '';

            ['ada_ng', 'ada_dandori'].forEach(id => {
                const cb = document.getElementById(id);
                if (!cb) return;
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                Alpine.$data(cb.closest('[x-data]'))[id === 'ada_ng' ? 'adaNg' : 'adaDandori'] = false;
            });

            const ng = this.blok('.ng-rows'); if (ng) ng.rows = [];
            const dandori = this.blok('.dandori-rows'); if (dandori) dandori.rows = [];
        },

        hapus(i) { this.rows.splice(i, 1); this.barisGagal = []; },

        ringkasNg(r) {
            if (!r.ng.length) return '—';
            return r.ng.map(n => `#${n.id_jenis_ng} ×${n.qty}`).join(', ');
        },

        ringkasDandori(r) {
            if (!r.dandori.length) return '—';
            return r.dandori.map(d => `#${d.id_losstime} ${d.actual_menit}mnt`).join(', ');
        },

        batal() {
            if (this.rows.length && !confirm(`Buang ${this.rows.length} baris draft yang belum dikirim?`)) return;
            localStorage.removeItem(KUNCI);
            window.location = '{{ route('input.produksi.index') }}';
        },
    };
}
</script>
@endpush
```

- [ ] **Step 4: Kirim daftar shift & jenis NG ke view**

Periksa `ProduksiController::create()` sudah mengirim `$shifts`, `$karyawan`, `$mesin`, `$parts`, `$jenisNg`, `$losstimes`. Kalau salah satu tidak ada, tambahkan ke `compact()`-nya. Halaman batch memakai keenamnya.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test --filter=HalamanBatchProduksiTest
```
Expected: PASS — 7 test.

```bash
php artisan test
```
Expected: PASS — seluruh suite hijau.

- [ ] **Step 6: Periksa mata di peramban**

Jalankan aplikasi dengan DB SQLite sementara **di dalam folder proyek**, login sebagai `staff_produksi`, buka `/input/produksi/create`. Yang diperiksa dan dilaporkan apa adanya:

- Isi satu baris → SAVE TO DRAFT → baris muncul di tabel; Tanggal, Shift, Operator, Mesin **tetap terisi**; Part, Proses, Jam, Qty kosong lagi
- Simpan baris kedua dengan jam yang tumpang tindih → ditolak dengan pesan menyebut nomor baris lawannya
- Centang Ada NG dan Ada Dandori, isi, SAVE TO DRAFT → kolom Jenis NG dan Dandori terisi ringkasannya
- Refresh halaman → draft kembali dengan pemberitahuan
- CANCEL saat draft berisi → muncul konfirmasi
- SUBMIT DRAFT → kembali ke daftar dengan pesan "N baris berhasil disimpan", dan barisnya benar-benar ada di tabel

Hapus berkas DB sementara dan matikan server setelah selesai.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/produksi/create.blade.php tests/Feature/Input/HalamanBatchProduksiTest.php app/Http/Controllers/Input/ProduksiController.php
git commit -m "feat(produksi): halaman batch input menggantikan form satu baris"
```

---

### Task 5: Cabut laci dari halaman daftar

**Files:**
- Modify: `resources/views/input/produksi/index.blade.php`
- Modify: `tests/Feature/Input/DandoriDariLhpTest.php`
- Modify: `tests/Feature/Input/HalamanBatchProduksiTest.php`

**Interfaces:**
- Consumes: route `input.produksi.create`
- Produces: `index.blade.php` tanpa form penulis apa pun

- [ ] **Step 1: Write the failing test**

Tambahkan ke `tests/Feature/Input/HalamanBatchProduksiTest.php`:

```php
    public function test_halaman_daftar_tidak_lagi_punya_form_penulis(): void
    {
        // Laci input dicabut: form penulis turun dari tiga jadi dua (halaman
        // batch + form Edit). Selama masih ada di sini, field baru bisa
        // diam-diam terpasang di satu form saja.
        $html = $this->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="qty_aktual"', $html);
        $this->assertStringNotContainsString('name="ada_dandori"', $html);
        $this->assertStringNotContainsString('id="formProduksi"', $html);
    }

    public function test_tombol_input_baru_menautkan_ke_halaman_batch(): void
    {
        $html = $this->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('input.produksi.create'), $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

```bash
php artisan test --filter='tidak_lagi_punya_form_penulis|menautkan_ke_halaman_batch'
```
Expected: FAIL — laci masih ada di `index.blade.php`.

- [ ] **Step 3: Cabut markup laci**

Di `resources/views/input/produksi/index.blade.php`:

1. Hapus blok latar gelap laci input (`<div x-show="drawerOpen" ...>` yang berisi `@click="drawerOpen = false"`, sekitar baris 30–42) **dan** seluruh panel laci input dari `<div x-show="drawerOpen" ...>` sampai penutupnya setelah `</form>` (sekitar baris 43–275).

   ⚠️ Laci **note** di sekitar baris 837 memakai kelas `erp-drawer` juga — **jangan** ikut dicabut. Yang dicabut hanya laci input, yaitu yang memuat `id="formProduksi"`.

2. Ganti tombolnya (sekitar baris 339):

```blade
    <a href="{{ route('input.produksi.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg me-1"></i>Input Baru
    </a>
```

3. Pada `x-data` pembungkus halaman (sekitar baris 26), buang `drawerOpen` yang kini tidak dipakai lagi oleh laci input:

```blade
<div x-data="{ filterOpen: false }">
```

   ⚠️ Periksa dulu apakah `drawerOpen` masih dipakai laci note. Kalau iya, biarkan kuncinya dan cukup ubah nilai awalnya menjadi `false` (bukan `$errors->any()`), karena validasi batch sekarang mendarat di halaman create.

- [ ] **Step 4: Periksa keseimbangan div**

Membuang pembungkus panel sudah 6× meninggalkan `</div>` nyasar di repo ini, 1× lolos ke prod. Jalankan:

```bash
python3 - <<'PY'
s = open('resources/views/input/produksi/index.blade.php').read()
print('buka', s.count('<div'), 'tutup', s.count('</div>'), 'selisih', s.count('<div') - s.count('</div>'))
PY
```

Catat selisihnya, lalu bandingkan dengan selisih pada `git show HEAD:resources/views/input/produksi/index.blade.php`. Selisihnya harus **sama**; kalau berubah, ada tag yatim.

- [ ] **Step 5: Pindahkan test yang menjangkar ke laci**

Di `tests/Feature/Input/DandoriDariLhpTest.php`:

1. Pada `formPembuatan()`, hapus entri `'laci index' => ['input.produksi.index']` sehingga tinggal:

```php
    public static function formPembuatan(): array
    {
        return [
            'create' => ['input.produksi.create'],
        ];
    }
```

2. Hapus `test_laci_index_punya_blok_dandori()` seluruhnya — perannya sekarang dipikul `HalamanBatchProduksiTest::test_halaman_memuat_blok_ng_dan_dandori()`, yang menguji halaman yang benar-benar dipakai operator.

3. Pada `test_kemasan_panel_tidak_ditulis_ulang_sebaris()`, hapus `route('input.produksi.index')` dari array `$halaman` sehingga tinggal `create` dan `edit`.

⚠️ Penjaga dipindah, bukan dihapus. Sebelum menghapus, pastikan `HalamanBatchProduksiTest` benar-benar sudah menguji hal yang sama pada halaman batch.

- [ ] **Step 6: Run tests to verify they pass**

```bash
php artisan test
```
Expected: PASS — seluruh suite hijau.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint app tests
git add resources/views/input/produksi/index.blade.php tests/Feature/Input/DandoriDariLhpTest.php tests/Feature/Input/HalamanBatchProduksiTest.php
git commit -m "refactor(produksi): cabut laci input, tombol Input Baru jadi tautan"
```

---

### Task 6: Pensiunkan route store

**Files:**
- Modify: `routes/web.php:101`
- Modify: `app/Http/Controllers/Input/ProduksiController.php`
- Modify: `tests/Feature/Input/ProduksiStoreTest.php`
- Modify: `tests/Feature/Input/NgMultiJenisProduksiTest.php`
- Modify: `tests/Feature/Input/DandoriDariLhpTest.php`

Setelah Task 4 dan 5, tidak ada satu pun view yang memanggil `input.produksi.store`. Route yang tak terpakai membusuk lalu menyimpang dari saudaranya.

- [ ] **Step 1: Pastikan benar-benar tak terpakai**

```bash
grep -rn "produksi.store" resources/ app/
```
Expected: tidak ada keluaran. Kalau masih ada, Task 4 atau 5 belum tuntas — perbaiki dulu.

- [ ] **Step 2: Pindahkan pemanggilan test ke payload batch**

Di **tiap** berkas `tests/Feature/Input/ProduksiStoreTest.php`, `tests/Feature/Input/NgMultiJenisProduksiTest.php`, dan `tests/Feature/Input/DandoriDariLhpTest.php`, tambahkan satu helper di dalam kelasnya:

```php
    /** Satu baris dikirim lewat endpoint batch. */
    private function simpanLhp(array $payload)
    {
        return $this->post(route('input.produksi.batch'), ['rows' => [$payload]]);
    }
```

Lalu ganti tiap pemanggilan:

```php
$this->post(route('input.produksi.store'), $payload)
```

menjadi:

```php
$this->simpanLhp($payload)
```

⚠️ **Kunci error ikut berubah** — kini bersarang di bawah `rows.0.`. Ganti setiap asersi:

| Sebelum | Sesudah |
|---|---|
| `assertSessionHasErrors('dandori_count')` | `assertSessionHasErrors('rows.0.dandori_count')` |
| `assertSessionHasErrors('ng_count')` | `assertSessionHasErrors('rows.0.ng_count')` |
| `assertSessionHasErrors('dandori.0.id_losstime')` | `assertSessionHasErrors('rows.0.dandori.0.id_losstime')` |
| `assertSessionHasErrors('ng.0.id_jenis_ng')` | `assertSessionHasErrors('rows.0.ng.0.id_jenis_ng')` |
| `assertSessionHasErrors('qty_aktual')` | `assertSessionHasErrors('rows.0.qty_aktual')` |

Cari sisanya dengan:

```bash
grep -rn "assertSessionHasErrors" tests/Feature/Input/ProduksiStoreTest.php tests/Feature/Input/NgMultiJenisProduksiTest.php tests/Feature/Input/DandoriDariLhpTest.php
```

⚠️ Asersi pesan sukses juga berubah: `'LHP Produksi berhasil disimpan.'` menjadi `'1 baris LHP Produksi berhasil disimpan.'`. Cari dengan:

```bash
grep -rn "berhasil disimpan" tests/
```

- [ ] **Step 3: Run tests to verify they pass sebelum route dicabut**

```bash
php artisan test --filter='ProduksiStoreTest|NgMultiJenisProduksiTest|DandoriDariLhpTest'
```
Expected: PASS. Test sudah memakai batch, route `store` masih ada tapi tak dipanggil siapa pun.

- [ ] **Step 4: Cabut route dan method**

Di `routes/web.php`, ubah:

```php
        Route::resource('produksi', ProduksiController::class)->only(['create', 'store', 'destroy']);
```

menjadi:

```php
        Route::resource('produksi', ProduksiController::class)->only(['create', 'destroy']);
```

Di `app/Http/Controllers/Input/ProduksiController.php`, hapus seluruh method `store()` dan import `StoreProduksiRequest` **kalau** tidak dipakai method lain:

```bash
grep -n "StoreProduksiRequest" app/Http/Controllers/Input/ProduksiController.php
```

⚠️ `app/Http/Requests/StoreProduksiRequest.php` **tetap ada** — ia masih dipakai `UpdateProduksiRequest` sebagai acuan aturan pada beberapa repo. Periksa dulu:

```bash
grep -rn "StoreProduksiRequest" app/
```
Kalau nol pemakai tersisa, berkasnya boleh dihapus di commit yang sama; kalau masih ada, biarkan.

- [ ] **Step 5: Run tests to verify they pass**

```bash
php artisan test
```
Expected: PASS — seluruh suite hijau.

```bash
php artisan route:list --path=input/produksi
```
Expected: tidak ada lagi baris `POST input/produksi` bernama `input.produksi.store`; ada `POST input/produksi/batch`.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint app tests
git add -A
git commit -m "refactor(produksi): pensiunkan route store, batch jadi satu-satunya jalur simpan"
```

---

### Task 7: Gerbang akhir & PR

**Files:** seluruh suite

- [ ] **Step 1: Buktikan angka tidak bergeser**

```bash
git diff main --stat -- app/Services/MaintenanceDashboardService.php app/Services/MaintenanceDiesDashboardService.php app/Services/OeeService.php app/Services/OverviewService.php app/Services/KalenderKerjaService.php app/Services/DashboardService.php
```
Expected: **tidak ada keluaran**

```bash
git diff main --stat -- database/migrations/
```
Expected: **tidak ada keluaran**

```bash
git diff main --stat -- resources/views/input/produksi/edit.blade.php
```
Expected: **tidak ada keluaran** — form Edit tidak disentuh

- [ ] **Step 2: Jalankan seluruh gerbang**

```bash
php artisan test
```
Expected: PASS, nol gagal

```bash
vendor/bin/pint --test app tests
```
Expected: `"result":"passed"`

```bash
grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/
```
Expected: tidak ada keluaran

```bash
./scripts/check-migration-coverage.sh
```
Expected: `✓ Cakupan migrasi lengkap: 54 file — 14 dijalankan saat deploy, 40 baseline.`

- [ ] **Step 3: Buktikan penjaganya menangkap**

Kembalikan tiap bug sebentar, pastikan penjaganya jatuh, lalu kembalikan lagi:

- Hapus panggilan `cariBentrok()` di `StoreProduksiBatchRequest::withValidator()` → `test_jam_tumpang_tindih_di_mesin_sama_ditolak` harus MERAH
- Ganti `DB::transaction(...)` di `ProduksiController::batch()` jadi perulangan biasa tanpa transaksi → `test_gagal_di_tengah_transaksi_tidak_menyisakan_baris` harus MERAH
- Hapus blok `periksaHitungan()` → `test_hitungan_ng_tidak_cocok_ditolak_per_baris` harus MERAH
- Hapus `:data-progressive` dari `<option>` proses di `create.blade.php` → `test_pilihan_proses_membawa_penanda_progressive` harus MERAH
- Kembalikan satu `<form>` penulis ke `index.blade.php` → `test_halaman_daftar_tidak_lagi_punya_form_penulis` harus MERAH

Penjaga yang tidak pernah terlihat merah belum terbukti menjaga apa pun.

- [ ] **Step 4: Push dan buka PR**

```bash
git push -u origin feat/lhp-produksi-batch-input
```

Badan PR wajib memuat: tautan ke spec, alasan laci dicabut (form penulis tiga jadi dua), bukti `git diff --stat` kosong untuk enam layanan perhitungan dan `database/migrations/`, penjelasan bahwa draft hidup di `localStorage` sehingga nol migrasi, catatan bahwa pemeriksaan tumpang tindih **hanya** antar baris dalam satu batch dan bukan terhadap data tersimpan, serta hasil mutation testing kelima penjaga.

- [ ] **Step 5: Tunggu CI hijau sebelum merge**

```bash
gh pr checks <nomor>
```
Expected: `test pass`

---

## Verifikasi Manual Setelah Deploy

1. LHP Produksi → **Input Baru** → halaman batch terbuka, sidebar dan topbar tetap ada
2. Isi satu baris → SAVE TO DRAFT → baris muncul; Tanggal, Shift, Operator, Mesin tetap terisi
3. Isi baris kedua dengan jam tumpang tindih di mesin sama → ditolak, pesannya menyebut nomor baris lawannya
4. Centang Ada NG dan Ada Dandori, isi keduanya, SAVE TO DRAFT → kolom Jenis NG dan Dandori terisi
5. Refresh halaman → draft kembali dengan pemberitahuan
6. CANCEL saat draft berisi → muncul konfirmasi; kalau diiyakan, draft hilang dan kembali ke daftar
7. SUBMIT DRAFT → kembali ke daftar dengan pesan "N baris berhasil disimpan", barisnya benar-benar ada
8. Buka Edit salah satu baris → form Edit tetap seperti dulu, tanpa blok dandori
9. Buka `/overview` dan satu halaman VCB → angkanya sama seperti sebelum deploy
