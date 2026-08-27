# Halaman Batch Input Quality (PR 2) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Pindahkan Input LHP Quality dari laci ke halaman batch sendiri, memakai kerangka `batchDraft()` yang sudah naik ke layout di PR 1.

**Architecture:** `batchDraft(cfg)` dan `partPicker(items, partsData)` sudah hidup di `resources/views/layouts/app.blade.php` (PR #125, merged). QC menjadi konsumen kedua. Pola servernya menyalin produksi: `Store<X>BatchRequest` memvalidasi `rows_json`, `<X>WriteService::simpan()` menulis satu baris tanpa membuka transaksi sendiri, dan `<X>Controller::batch()` membungkus loop dalam satu `DB::transaction`.

**Tech Stack:** Laravel 13, Blade, Alpine.js 3.14.1 (CDN), Bootstrap 5.3.3, PHPUnit 12 (`php artisan test`, SQLite in-memory), Pint.

## Global Constraints

- PHP lokal 8.5.4, **CI & prod 8.4** — jangan pakai sintaks yang hanya ada di 8.5.
- **Nol migrasi.** `.github/workflows/deploy.yml` tidak boleh berubah.
- **QC hanya memeriksa finish good.** Daftar part di halaman batch WAJIB memakai filter yang sama dengan `QcController::create()` dan `index()`: `with(['proses' => fn ($q) => $q->orderByDesc('id_proses')])->has('proses')` lalu `$part->setRelation('proses', $part->proses->take(1))`. Menghilangkan filter itu memunculkan proses antara di dropdown QC — data yang salah, tanpa error.
- `cfg` yang shipped di PR 1: `kunci` dan `kunciBackup` adalah string kunci `localStorage` **LENGKAP** yang dirakit Blade. Tidak ada `userId`. `resetLain(diri)` adalah callback, bukan array selector. `cfg.metode` disebar PALING AWAL sehingga kerangka selalu menang. `batchDraft` melempar galat kalau salah satu dari `kunci`, `kunciBackup`, `formId`, `indexUrl`, `kumpulkan`, `wajib`, `payload` hilang.
- Kunci draft QC: `optima_draft_lhp_qc_u{id}` dan `optima_draft_lhp_qc_terkirim_u{id}`. Perhatikan `_terkirim` mendahului `_u{id}`.
- Nol entri sidebar baru; `config/sidebar.php` tidak disentuh. Entri QC memakai `match => ['input.qc.*']`, jadi halaman baru otomatis menyalakan menu yang benar.
- Laci **filter** (`erp-dock`) dan laci **note** di `input/qc/index.blade.php` TIDAK dicabut. Yang dicabut hanya laci penulis.
- Batas 50 baris per batch, sama dengan produksi.
- Bahasa komentar, pesan galat, dan commit: Indonesia.
- Scope Pint = `app/` + `tests/` saja.

---

### Task 1: `QcWriteService`

Logika tulis QC sekarang menempel di `QcController::store()` (`app/Http/Controllers/Input/QcController.php:165-192`): merakit `qty_ng`, menghitung `actual_cycle_time`, membuka `DB::transaction` sendiri, lalu `syncQc`. Batch butuh potongan itu tanpa transaksinya.

**Files:**
- Create: `app/Services/QcWriteService.php`
- Modify: `app/Http/Controllers/Input/QcController.php:165-192` (store memanggil service)
- Test: `tests/Feature/Input/QcWriteServiceTest.php`

**Interfaces:**
- Consumes: `App\Services\QcService::calculateActualCycleTime(string $jamStart, string $jamFinish, int $qtyOk, int $qtyNg): float`, `App\Services\NgDetailService::syncQc(TransaksiQc $trx, array $items): void`
- Produces: `App\Services\QcWriteService::simpan(array $baris): App\Models\TransaksiQc`. `$baris` adalah satu baris hasil validasi: `tgl_pengecekan`, `tgl_produksi_part`, `id_shift`, `id_karyawan_qc`, `id_part_proses`, `jam_start`, `jam_finish`, `qty_ok`, plus kunci bantu `ng` (array `[{id_jenis_ng, qty}]`) dan `ng_count`. Kunci bantu dibuang sebelum menulis kolom. Service TIDAK membuka transaksi.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Input/QcWriteServiceTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use App\Services\QcWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QcWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $base;

    private MasterJenisNg $dented;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Insp Tulis', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $part = MasterPart::create(['part_no' => 'QW-P1', 'part_name' => 'QC Write Part', 'customer' => 'Toyota']);
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
    }

    public function test_menulis_baris_qc_dengan_cycle_time_terhitung(): void
    {
        $trx = app(QcWriteService::class)->simpan([...$this->base, 'ng' => [], 'ng_count' => 0]);

        // 4 jam = 14400 detik, 90 barang diperiksa → 160 detik per barang.
        $this->assertSame(160.0, (float) $trx->actual_cycle_time);
        $this->assertSame(0, (int) $trx->qty_ng);
        $this->assertSame(auth()->id(), $trx->created_by);
    }

    public function test_menulis_rincian_ng_bersama_barisnya(): void
    {
        $trx = app(QcWriteService::class)->simpan([
            ...$this->base,
            'ng' => [['id_jenis_ng' => $this->dented->id, 'qty' => 6]],
            'ng_count' => 1,
        ]);

        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(6, (int) $trx->fresh()->qty_ng);
    }

    public function test_kunci_bantu_tidak_ikut_tertulis_ke_kolom(): void
    {
        // `ng` dan `ng_count` adalah kunci form, bukan kolom tabel. Kalau ikut
        // masuk ke create(), Eloquent melemparnya sebagai kolom tak dikenal —
        // atau lebih buruk, diam saja kalau suatu saat kolomnya ada.
        $trx = app(QcWriteService::class)->simpan([
            ...$this->base,
            'ng' => [['id_jenis_ng' => $this->dented->id, 'qty' => 3]],
            'ng_count' => 1,
        ]);

        $this->assertArrayNotHasKey('ng', $trx->getAttributes());
        $this->assertArrayNotHasKey('ng_count', $trx->getAttributes());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=QcWriteServiceTest`

Expected: FAIL — `Class "App\Services\QcWriteService" does not exist`.

- [ ] **Step 3: Buat service**

Buat `app/Services/QcWriteService.php`:

```php
<?php

namespace App\Services;

use App\Models\TransaksiQc;

/**
 * Menulis SATU baris LHP Quality beserta rincian NG-nya.
 *
 * Beda dari QcService, yang hanya menghitung (durasi, cycle time, target qty)
 * dan tidak pernah menyentuh database.
 *
 * Sengaja TIDAK membuka transaksi sendiri: pemanggil yang menentukan batasnya,
 * supaya satu batch berisi banyak baris bisa dibatalkan utuh. Pola yang sama
 * dipakai ProduksiWriteService.
 */
class QcWriteService
{
    public function __construct(
        private QcService $qcService,
        private NgDetailService $ngDetailService,
    ) {}

    /**
     * @param  array  $baris  payload satu baris yang sudah tervalidasi
     */
    public function simpan(array $baris): TransaksiQc
    {
        $ngRows = $baris['ng'] ?? [];

        /* Kunci bantu form, bukan kolom tabel. */
        unset($baris['ng'], $baris['ng_count']);

        $qtyNg = array_sum(array_column($ngRows, 'qty'));

        $cycleTime = $this->qcService->calculateActualCycleTime(
            $baris['jam_start'].':00',
            $baris['jam_finish'].':00',
            (int) $baris['qty_ok'],
            (int) $qtyNg
        );

        $qc = TransaksiQc::create([
            ...$baris,
            /* qty_ng ditulis 0 dulu; syncQc() yang menghitungnya dari rincian,
               supaya hanya ada SATU sumber angka itu. */
            'qty_ng' => 0,
            'actual_cycle_time' => $cycleTime,
            'created_by' => auth()->id(),
        ]);

        $this->ngDetailService->syncQc($qc, $ngRows);

        return $qc;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter=QcWriteServiceTest`

Expected: PASS, 3 test.

- [ ] **Step 5: Alihkan `QcController::store()` ke service**

Di `app/Http/Controllers/Input/QcController.php`, tambahkan `QcWriteService` ke konstruktor (ikuti gaya properti konstruktor yang sudah ada di sana), lalu ganti isi `store()` menjadi:

```php
    public function store(StoreQcRequest $request)
    {
        DB::transaction(fn () => $this->qcWriteService->simpan($request->validated()));

        return redirect()->route('input.qc.index')->with('success', 'LHP Quality berhasil disimpan.');
    }
```

Kalau `QcService` atau `NgDetailService` jadi tidak dipakai method mana pun lagi di controller, cabut dari konstruktor. Kalau masih dipakai (mis. oleh `update()`), biarkan.

- [ ] **Step 6: Jalankan test QC yang sudah ada, pastikan LULUS**

Run: `php artisan test --filter="QcWriteServiceTest|NgMultiJenisQcTest"`

Expected: PASS semua. `NgMultiJenisQcTest` TIDAK boleh disunting — ia menguji `input.qc.store` lewat HTTP dan harus tetap berperilaku sama.

- [ ] **Step 7: Format & commit**

```bash
vendor/bin/pint app tests
git add app/Services/QcWriteService.php app/Http/Controllers/Input/QcController.php tests/Feature/Input/QcWriteServiceTest.php
git commit -m "refactor(qc): logika tulis LHP Quality pindah ke QcWriteService"
```

---

### Task 2: `StoreQcBatchRequest` + endpoint `batch`

**Files:**
- Create: `app/Http/Requests/StoreQcBatchRequest.php`
- Modify: `app/Http/Controllers/Input/QcController.php` (method `batch`), `routes/web.php:142`
- Test: `tests/Feature/Input/QcBatchTest.php`

**Interfaces:**
- Consumes: `App\Services\QcWriteService::simpan(array $baris): TransaksiQc` dari Task 1.
- Produces: route bernama `input.qc.batch` (POST `input/qc/batch`), menerima `rows_json` (string JSON) ATAU `rows` (array). `QcController::batch(StoreQcBatchRequest $request)` redirect ke `input.qc.index` dengan flash `success` dan `batch_tersimpan` (jumlah baris).

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Input/QcBatchTest.php`:

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

class QcBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Insp Batch', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $part = MasterPart::create(['part_no' => 'QB-P1', 'part_name' => 'QC Batch Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->ref = [
            'shift' => $shift->id,
            'inspector' => $inspector->id,
            'proses' => $proses->id,
            'dented' => MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented'])->id,
            'crack' => MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack'])->id,
        ];
    }

    private function baris(array $ubah = []): array
    {
        return [
            'tgl_pengecekan' => '2026-07-28',
            'tgl_produksi_part' => '2026-07-27',
            'id_shift' => $this->ref['shift'],
            'id_karyawan_qc' => $this->ref['inspector'],
            'id_part_proses' => $this->ref['proses'],
            'jam_start' => '08:00',
            'jam_finish' => '12:00',
            'qty_ok' => 90,
            'ng_count' => 0,
            'ng' => [],
            ...$ubah,
        ];
    }

    private function kirim(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('input.qc.batch'), ['rows' => $rows]);
    }

    private function kirimJson(array $rows)
    {
        return $this->actingAs($this->admin)
            ->post(route('input.qc.batch'), ['rows_json' => json_encode($rows)]);
    }

    public function test_satu_submit_menulis_semua_baris(): void
    {
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '13:00', 'jam_finish' => '16:00', 'qty_ok' => 50]),
        ]);

        $response->assertRedirect(route('input.qc.index'));
        $this->assertSame(2, TransaksiQc::count());
    }

    public function test_pesan_sukses_menyebut_jumlah_baris(): void
    {
        $this->kirim([$this->baris(), $this->baris(['qty_ok' => 10])])
            ->assertSessionHas('batch_tersimpan', 2);
    }

    public function test_rincian_ng_menempel_pada_barisnya_masing_masing(): void
    {
        $this->kirim([
            $this->baris(['ng_count' => 1, 'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 4]]]),
            $this->baris(['jam_start' => '13:00', 'jam_finish' => '16:00', 'ng_count' => 0, 'ng' => []]),
        ]);

        $baris = TransaksiQc::orderBy('id')->get();
        $this->assertSame(4, (int) $baris[0]->qty_ng);
        $this->assertSame(0, (int) $baris[1]->qty_ng);
    }

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['id_shift' => 999999]),
        ]);

        $response->assertSessionHasErrors('rows.1.id_shift');
        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_qty_ok_nol_tanpa_ng_ditolak_per_baris(): void
    {
        // Aturan yang sama dengan StoreQcRequest: total qty check harus > 0.
        $this->kirim([$this->baris(['qty_ok' => 0, 'ng_count' => 0, 'ng' => []])])
            ->assertSessionHasErrors('rows.0.qty_ok');

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_qty_ok_nol_tapi_ada_ng_diterima(): void
    {
        $this->kirim([$this->baris([
            'qty_ok' => 0,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 3]],
        ])])->assertRedirect(route('input.qc.index'));

        $this->assertSame(3, (int) TransaksiQc::first()->qty_ng);
    }

    public function test_hitungan_ng_tidak_cocok_ditolak_per_baris(): void
    {
        // ng_count membuktikan Alpine hidup, bukan bahwa baris ng[] sampai.
        $this->kirim([$this->baris(['ng_count' => 2, 'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 3]]])])
            ->assertSessionHasErrors('rows.0.ng_count');

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_jenis_ng_kembar_diterima_dan_qty_dijumlah(): void
    {
        $this->kirim([$this->baris([
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->ref['dented'], 'qty' => 5],
                ['id_jenis_ng' => $this->ref['dented'], 'qty' => 3],
            ],
        ])]);

        $trx = TransaksiQc::first();
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(8, (int) $trx->qty_ng);
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('input.qc.batch'), ['rows' => [$this->baris()]])
            ->assertForbidden();

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_rows_json_menyimpan_baris(): void
    {
        $this->kirimJson([$this->baris()])->assertRedirect(route('input.qc.index'));
        $this->assertSame(1, TransaksiQc::count());
    }

    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('input.qc.batch'), ['rows_json' => '{bukan json'])
            ->assertSessionHasErrors('rows');

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_rows_json_menampung_50_baris_batas_server(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $rows[] = $this->baris(['qty_ok' => 10 + $i]);
        }

        $this->kirimJson($rows)->assertRedirect(route('input.qc.index'));
        $this->assertSame(50, TransaksiQc::count());
    }

    public function test_lebih_dari_50_baris_ditolak(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->baris(['qty_ok' => 10 + $i]);
        }

        $this->kirimJson($rows)->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_string_kosong_di_rows_json_jadi_null(): void
    {
        // Browser mengirim "" untuk field yang tidak diisi. Tanpa normalisasi,
        // "" lolos `nullable` tapi gagal `integer` dengan pesan membingungkan.
        $this->kirimJson([$this->baris(['ng' => [], 'ng_count' => 0])])
            ->assertRedirect(route('input.qc.index'));

        $this->assertSame(1, TransaksiQc::count());
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=QcBatchTest`

Expected: FAIL — route `input.qc.batch` belum ada (`Route [input.qc.batch] not defined`).

- [ ] **Step 3: Buat `StoreQcBatchRequest`**

Baca `app/Http/Requests/StoreProduksiBatchRequest.php` lebih dulu — request ini mengikuti strukturnya (`prepareForValidation` yang membongkar `rows_json` + `kosongJadiNull`, `withValidator` yang memanggil pemeriksa per baris). Buat `app/Http/Requests/StoreQcBatchRequest.php` dengan aturan:

```php
'rows' => 'required|array|min:1|max:50',
'rows.*.tgl_pengecekan' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
'rows.*.tgl_produksi_part' => 'required|date|after_or_equal:2020-01-01|before_or_equal:tomorrow',
'rows.*.id_shift' => 'required|exists:master_shift,id',
'rows.*.id_karyawan_qc' => 'required|exists:master_karyawan,id',
'rows.*.id_part_proses' => 'required|exists:master_part_proses,id',
'rows.*.jam_start' => 'required|date_format:H:i',
'rows.*.jam_finish' => 'required|date_format:H:i',
'rows.*.qty_ok' => 'required|integer|min:0',
'rows.*.ng_count' => 'required|integer|min:0',
'rows.*.ng' => 'nullable|array',
/* Tanpa `distinct`, sama seperti StoreQcRequest — inspector boleh mencatat
 * jenis NG yang sama dua kali dalam satu sesi. uniq_qc_ng + normalize()
 * menjadikannya satu baris dengan qty dijumlah (lihat PR #123). */
'rows.*.ng.*.id_jenis_ng' => 'required|integer|exists:master_jenis_ng,id',
'rows.*.ng.*.qty' => 'required|integer|min:1',
```

`authorize()`: `return $this->user()->hasRole(['admin', 'staff_qc']);`

Dua pemeriksaan per baris di `withValidator`, dipindahkan dari `StoreQcRequest::withValidator()` dan diberi indeks baris:

1. **Total qty check harus > 0.** `qty_ok + sum(ng.*.qty) === 0` → error di `rows.{i}.qty_ok`, pesan `"Baris {n}: total qty check (OK + NG) harus lebih dari 0."` (n = i + 1).
2. **`ng_count` harus sama dengan jumlah baris `ng` terkirim.** → error di `rows.{i}.ng_count`, pesan `"Baris {n}: jumlah baris NG yang tercatat (X) tidak sama dengan yang terkirim (Y). Coba simpan ulang."` Salin komentar penjelasnya dari `StoreQcRequest` — komentar itu mencatat kenapa penjaga ini ada (payload terpotong lolos validasi lalu `syncQc([])` menghapus semua detail NG tanpa error).

`prepareForValidation()` menyalin pola `StoreProduksiBatchRequest`: kalau `rows_json` ada, `json_decode` dan taruh hasilnya di `rows` (JSON rusak → `rows` diisi array kosong supaya aturan `required|array|min:1` yang menolaknya, bukan exception), lalu jalankan normalisasi string kosong → null pada setiap baris.

- [ ] **Step 4: Tambah endpoint dan route**

Di `app/Http/Controllers/Input/QcController.php`, tambahkan:

```php
    public function batch(StoreQcBatchRequest $request)
    {
        $rows = $request->validated()['rows'];

        DB::transaction(function () use ($rows) {
            foreach ($rows as $row) {
                $this->qcWriteService->simpan($row);
            }
        });

        $jumlah = count($rows);

        /* batch_tersimpan adalah bukti sukses yang dibaca index.blade.php untuk
         * membuang draft di localStorage. Draft hanya boleh dibuang setelah
         * server MEMBUKTIKAN barisnya tersimpan — bukan cuma karena halaman
         * batch tidak melihat galat validasi, yang juga terjadi saat request
         * tidak pernah sampai sini (419 sesi habis, 500, jaringan putus). */
        return redirect()->route('input.qc.index')
            ->with('success', "{$jumlah} baris LHP Quality berhasil disimpan.")
            ->with('batch_tersimpan', $jumlah);
    }
```

Di `routes/web.php`, di grup `role:admin,staff_qc` (baris 141-146), tambahkan sebelum baris `Route::resource('qc', ...)`:

```php
        Route::post('qc/batch', [QcController::class, 'batch'])->name('qc.batch');
```

Route `store` BELUM dicabut di task ini — `NgMultiJenisQcTest` masih memakainya dan baru dialihkan di Task 4.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="QcBatchTest|QcWriteServiceTest|NgMultiJenisQcTest"`

Expected: PASS semua.

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint app tests
git add app/Http/Requests/StoreQcBatchRequest.php app/Http/Controllers/Input/QcController.php routes/web.php tests/Feature/Input/QcBatchTest.php
git commit -m "feat(qc): endpoint batch LHP Quality"
```

---

### Task 3: Halaman batch Quality

`resources/views/input/qc/create.blade.php` hari ini adalah halaman YATIM: route `input.qc.create` hidup, tapi nol view menautkannya — hanya `AuthorizationTest:152` yang menyentuhnya. Gaya markupnya lama (`page-header`, bukan `.erp-panel`). Halaman itu DIGANTI seluruhnya oleh halaman batch di posisi route yang sama.

**Files:**
- Rewrite: `resources/views/input/qc/create.blade.php` (314 baris lama dibuang seluruhnya)
- Modify: `app/Http/Controllers/Input/QcController.php:143-163` (method `create` menyiapkan data halaman batch)
- Test: `tests/Feature/Input/HalamanBatchQcTest.php`

**Interfaces:**
- Consumes: route `input.qc.batch` dari Task 2; `batchDraft(cfg)` dan `partPicker(items, partsData)` dari layout.
- Produces: halaman di route `input.qc.create` dengan `<form id="formBaris">` (baris) dan `<form id="formBatch">` (submit, field `rows_json`).

**Templat markup:** `resources/views/input/produksi/create.blade.php` adalah sumber susunannya. Salin kerangkanya — blok `@php` penyiapan data, `@push('styles')` (kelas `.lhp-baris`/`.lhp-isi`/`.lhp-draft` dipakai apa adanya, JANGAN diganti nama), panel `erp-panel`, form satu baris, `<hr>`, tabel DRAFT TERSIMPAN, form submit, `@push('scripts')` — lalu ganti isian per field menurut tabel di bawah.

| Field produksi | Padanan QC |
|---|---|
| `tanggal_produksi` (date) | `tgl_pengecekan` (date, default `now()`) DAN `tgl_produksi_part` (date, default `now()->subDay()`) — dua baris terpisah |
| `id_shift` (select) | sama |
| `id_karyawan` + `taField(taKaryawan)` | `id_karyawan_qc` + `taField(taInspector)`, label "QC Inspector" |
| `id_mesin` + `taField(taMesin)` | **tidak ada** — QC tidak punya mesin |
| `partPicker` + dropdown Proses | `partPicker(taParts, partsData)`, tapi Proses **tidak dipilih** — QC hanya finish good, jadi tampilkan kotak info "Proses Finish Good" dan `<input type="hidden" name="id_part_proses" :value="prosesList[0] ? prosesList[0].id : ''">` |
| `jam_start` / `jam_finish` | sama |
| `qty_aktual` (label "Qty OK") | `qty_ok` |
| blok NG (`ada_ng` + `ng-rows`) | sama persis, `@include('input.partials.ng-rows', ['jenisNg' => $jenisNg])` |
| blok Dandori | **tidak ada** |

Kotak info Proses Finish Good menggantikan `qcPartSelector()` yang lama di laci. Isinya dari `prosesList[0]`: `id_proses`, `nama_proses`, `sph_standar_qc`. `partPicker` mengisi `prosesList` dari `partsData[].proses`, jadi `QcController::create()` HARUS mengirim `proses` sebagai ARRAY berisi satu elemen (hasil `take(1)`), bukan objek tunggal.

Kolom tabel draft: Tgl Cek, Tgl Produksi, Shift, Inspector, Part No., Part Name, Proses, Jam, Qty OK, Qty NG, Jenis NG, SPH Std QC, SPH Act, W. Kerja, CT Act, %, tombol hapus.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Input/HalamanBatchQcTest.php`. Ambil `tests/Feature/Input/HalamanBatchProduksiTest.php` sebagai templat: salin `setUp()`, helper `halaman()`, cara login, dan test-test mekanis berikut apa adanya dengan menukar nama route dan field — `test_form_batch_mengirim_ke_endpoint_batch`, `test_halaman_punya_tabel_draft_dan_tombolnya`, `test_halaman_memakai_kerangka_bersama`, `test_guest_tidak_bisa_membuka_halaman_batch`, `test_form_submit_kirim_rows_json_bukan_input_per_baris`, `test_div_tags_seimbang_di_halaman_batch`, `test_kunci_draft_dipisah_per_pengguna` (kunci QC: `optima_draft_lhp_qc_u{id}`), dan `test_kunci_draft_cocok_dengan_yang_dihapus_index` (bandingkan halaman create QC dengan `input.qc.index`). Tambah `test_halaman_memuat_blok_ng` (cari `class="ng-rows"`) dan `test_halaman_memakai_part_picker_bersama` (cari `x-data="partPicker(`).

Dua test di bawah ini tidak punya padanan di produksi — tulis lengkap:

```php
    public function test_hanya_proses_finish_good_yang_dikirim(): void
    {
        // QC hanya memeriksa produk jadi: proses dengan id_proses TERTINGGI.
        // Aturan itu hidup sebagai take(1) setelah orderByDesc('id_proses') di
        // QcController::create(). Kalau hilang, proses antara ikut muncul di
        // halaman dan inspector bisa mencatat pemeriksaan ke proses yang salah
        // — data keliru tanpa satu pun error. Karena itu dijaga di sini.
        $part = MasterPart::create([
            'part_no' => 'FG-1', 'part_name' => 'Finish Good Part', 'customer' => 'Toyota',
        ]);
        $blanking = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $forming = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'FORMING',
            'sph_standar_produksi' => 400, 'sph_standar_qc' => 200,
        ]);
        $finish = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 3, 'nama_proses' => 'PIERCING',
            'sph_standar_produksi' => 300, 'sph_standar_qc' => 150,
        ]);

        $html = $this->halaman();

        // Dijangkarkan ke blok partsData, bukan ke seluruh HTML: id proses
        // yang sama bisa muncul di tempat lain (mis. daftar filter) dan
        // membuat asersi ini lolos tanpa membuktikan apa pun.
        $this->assertMatchesRegularExpression(
            '/const partsData\s*=\s*(\[.*?\]);/s', $html, 'blok partsData tidak ditemukan'
        );
        preg_match('/const partsData\s*=\s*(\[.*?\]);/s', $html, $m);
        $partsData = json_decode($m[1], true);

        $idProses = collect($partsData)
            ->firstWhere('id', $part->id)['proses'] ?? [];
        $idProses = array_column($idProses, 'id');

        $this->assertSame([$finish->id], $idProses);
        $this->assertNotContains($blanking->id, $idProses);
        $this->assertNotContains($forming->id, $idProses);
    }

    public function test_proses_finish_good_membawa_sph_qc_bukan_sph_produksi(): void
    {
        // Kolom "%" di tabel draft membandingkan SPH aktual dengan SPH STANDAR
        // QC. Memakai sph_standar_produksi di sana menghasilkan persentase yang
        // masuk akal tapi salah — angka yang tidak akan dikoreksi siapa pun.
        $part = MasterPart::create([
            'part_no' => 'SPH-1', 'part_name' => 'Sph Part', 'customer' => 'Toyota',
        ]);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 999, 'sph_standar_qc' => 111,
        ]);

        preg_match('/const partsData\s*=\s*(\[.*?\]);/s', $this->halaman(), $m);
        $proses = collect(json_decode($m[1], true))->firstWhere('id', $part->id)['proses'][0];

        $this->assertSame(111.0, (float) $proses['sph_standar_qc']);
    }
```

Kalau nama konstanta JS-nya bukan `partsData`, sesuaikan regex-nya — tapi pertahankan penjangkarannya ke blok itu, jangan mencari di seluruh HTML.

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

Run: `php artisan test --filter=HalamanBatchQcTest`

Expected: FAIL — halaman lama tidak punya `batchDraft`, `formBatch`, maupun tabel draft.

- [ ] **Step 3: Siapkan data di controller**

Ganti `QcController::create()` supaya mengirim data yang dibutuhkan halaman batch. Pertahankan filter finish-good PERSIS seperti sekarang (`orderByDesc('id_proses')` + `has('proses')` + `take(1)`), tetap kirim `$jenisNg`, dan pastikan relasi `proses` tetap Collection — setelah di-JSON-kan ia harus jadi ARRAY berisi satu elemen, bukan objek tunggal. `partPicker()` menyetel `prosesList = part.proses` lalu halaman membaca `prosesList[0]`; kalau `proses` dikirim sebagai objek, `prosesList[0]` bernilai `undefined` dan `id_part_proses` selalu kosong.

Halaman menyiapkan tiga konstanta JS di `@php` + `@json` (pola yang sama dengan `input/produksi/create.blade.php:5-22`), dan `konfigQc()` di Step 4 memakai ketiganya — pastikan namanya persis:

| Konstanta | Isi |
|---|---|
| `taInspector` | `$karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])` |
| `taParts` | `$parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])` |
| `partsData` | `$parts->map(...)` dengan `id`, `part_no`, `part_name`, dan `proses` sebagai array `[{ id, id_proses, nama_proses, sph_standar_qc }]` |
| `ngLabel` | `$jenisNg->mapWithKeys(fn ($j) => [$j->id => $j->nama_ng])` |

`sph_standar_qc` WAJIB ikut (bukan `sph_standar_produksi`) — kolom "%" di tabel draft memakainya, dan tertukar menghasilkan persentase yang masuk akal tapi salah.

- [ ] **Step 4: Tulis halaman batch**

Tulis ulang `resources/views/input/qc/create.blade.php` menurut templat dan tabel field di atas. Bagian JS-nya:

```js
function konfigQc() {
    const picker = () => Alpine.$data(document.getElementById('qcPartNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhp_qc_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhp_qc_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.qc.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tgl_pengecekan: 'Tanggal Pengecekan',
            tgl_produksi_part: 'Tanggal Produksi Part',
            id_shift: 'Shift',
            id_karyawan_qc: 'QC Inspector',
            id_part_proses: 'Part',
            jam_start: 'Jam Start',
            jam_finish: 'Jam Finish',
        },

        kumpulkan(diri) {
            const p = picker();
            const proses = p.prosesList[0] || null;

            return {
                tgl_pengecekan: diri.nilai('tgl_pengecekan'),
                tgl_produksi_part: diri.nilai('tgl_produksi_part'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan_qc: diri.nilai('id_karyawan_qc'),
                inspector_teks: (taInspector.find(k => k.v == diri.nilai('id_karyawan_qc')) || {}).t || '',
                id_part_proses: proses ? proses.id : '',
                proses_teks: proses ? `${proses.id_proses} – ${proses.nama_proses}` : '',
                sph_std: proses ? Number(proses.sph_standar_qc) : 0,
                part_no: p.partNo,
                part_name: p.partName,
                jam_start: diri.nilai('jam_start'),
                jam_finish: diri.nilai('jam_finish'),
                qty_ok: diri.nilai('qty_ok'),
                ng: (diri.blok('.ng-rows') || { rows: [] }).rows.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
            };
        },

        periksa(calon, rows, diri) {
            /* Aturan yang sama dengan server: total qty check harus > 0.
               Qty OK boleh 0 ASAL ada NG — barang diperiksa lalu semuanya
               ditolak adalah kejadian normal, bukan salah ketik. */
            const qtyNg = calon.ng.reduce((s, n) => s + Number(n.qty || 0), 0);
            if (Number(calon.qty_ok || 0) + qtyNg === 0) {
                return 'Total qty check (OK + NG) harus lebih dari 0.';
            }
            if (diri.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                return 'Jam Finish tidak boleh sama dengan Jam Start.';
            }

            /* Nol pemeriksaan tabrakan jam: dua inspector boleh memeriksa part
               yang sama di jam yang sama. Beda dari produksi, yang satu mesin
               tidak bisa menjalankan dua baris sekaligus. */
            return null;
        },

        /* Pratinjau; server yang menghitung angka yang tersimpan. */
        pratinjau(calon, diri) {
            const jamKerja = diri.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const qtyNg = calon.ng.reduce((s, n) => s + Number(n.qty || 0), 0);
            const total = Number(calon.qty_ok || 0) + qtyNg;

            calon.jam_kerja = jamKerja;
            calon.sph_act = jamKerja > 0 ? total / jamKerja : 0;
            calon.ct_act = total > 0 ? (jamKerja * 3600) / total : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        /* Tanggal, Shift, dan Inspector sengaja TIDAK di sini: ketiganya
           konstan sepanjang satu sesi pengecekan. */
        resetField: ['jam_start', 'jam_finish', 'qty_ok'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const cb = document.getElementById('ada_ng');
            if (cb) {
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                Alpine.$data(cb.closest('[x-data]')).adaNg = false;
            }

            const ng = diri.blok('.ng-rows'); if (ng) ng.rows = [];
        },

        payload(r) {
            return {
                tgl_pengecekan: r.tgl_pengecekan,
                tgl_produksi_part: r.tgl_produksi_part,
                id_shift: r.id_shift,
                id_karyawan_qc: r.id_karyawan_qc,
                id_part_proses: r.id_part_proses,
                jam_start: r.jam_start,
                jam_finish: r.jam_finish,
                qty_ok: r.qty_ok,
                ng_count: r.ng.length,
                ng: r.ng.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
            };
        },

        metode: {
            ringkasNg(r) {
                if (!r.ng.length) return '—';
                return r.ng.map(n => `${ngLabel[n.id_jenis_ng] ?? n.id_jenis_ng} ×${n.qty}`).join(', ');
            },
        },
    };
}
```

`x-data` panelnya: `x-data="batchDraft(konfigQc())" x-init="mulai()"`.

Beri id `qcPartNoTampil` pada input Part No yang readonly, supaya `picker()` bisa menemukannya.

- [ ] **Step 5: Jalankan test, pastikan LULUS**

Run: `php artisan test --filter="HalamanBatchQcTest|QcBatchTest|QcWriteServiceTest|NgMultiJenisQcTest"`

Expected: PASS semua.

- [ ] **Step 6: Commit**

```bash
git add resources/views/input/qc/create.blade.php app/Http/Controllers/Input/QcController.php tests/Feature/Input/HalamanBatchQcTest.php
git commit -m "feat(qc): halaman batch input LHP Quality"
```

---

### Task 4: Cabut laci penulis, pensiunkan route `store`

**Files:**
- Modify: `resources/views/input/qc/index.blade.php` (cabut laci penulis + `qcPartSelector` + validasi sisi klien milik `formQc`; tambah skrip pembersih draft; tombol "Input Baru" jadi tautan)
- Modify: `app/Http/Controllers/Input/QcController.php` (hapus `store`), `routes/web.php:142`
- Modify: `tests/Feature/Input/NgMultiJenisQcTest.php` (alihkan pemanggilan `input.qc.store` ke payload batch)
- Delete: `app/Http/Requests/StoreQcRequest.php` — HANYA kalau nol pemakai tersisa

**Interfaces:**
- Consumes: route `input.qc.batch` (Task 2), halaman `input.qc.create` (Task 3).
- Produces: `input.qc.store` tidak ada lagi. `input.qc.index` memuat skrip yang menghapus dua kunci draft QC saat flash `batch_tersimpan` ada.

- [ ] **Step 1: Cabut laci penulis**

Di `resources/views/input/qc/index.blade.php`, buang: `<div class="erp-drawer">` penulis beserta isinya (mulai baris 71, form `formQc`), `qcPartSelector()`, dan blok IIFE validasi sisi klien yang menyasar `formQc`. Ubah tombol "Input Baru" (`erp-tb-btn is-new`, sekitar baris 266) dari tombol pembuka laci jadi `<a href="{{ route('input.qc.create') }}">`, mempertahankan gating perannya.

JANGAN cabut: laci **filter** (`erp-dock`), laci **note** (`erp-drawer` kedua di sekitar baris 662), form import, dan variabel `@php` milik dock filter (`$_inspectorTA`, `$_jenisNgTA`, dan sejenisnya). Variabel `@php` yang HANYA dipakai laci penulis (mis. `$oldKaryawanTxt`, `$oldKaryawanId`) ikut dibuang.

Bersihkan juga `x-data` pembungkus: kalau `drawerOpen` sudah tidak dipakai siapa pun, buang kuncinya, tapi pertahankan `filterOpen`.

- [ ] **Step 2: Tambah skrip pembersih draft**

Di `input/qc/index.blade.php`, di dalam blok `<script>` yang sudah ada, tambahkan (mencerminkan `input/produksi/index.blade.php:748-762`):

```blade
@if (session('batch_tersimpan'))
(function() {
    /* Draft di localStorage cuma hidup di browser, jadi cuma boleh dibuang
       kalau server sudah MEMBUKTIKAN barisnya tersimpan — flash
       batch_tersimpan dari QcController::batch() adalah bukti itu.
       Kunci ditulis SAMA PERSIS dengan `kunci` dan `kunciBackup` di
       konfigQc() (create.blade.php) supaya keduanya selalu menunjuk baris
       yang sama untuk pengguna yang sama. Dijaga
       HalamanBatchQcTest::test_kunci_draft_cocok_dengan_yang_dihapus_index. */
    try {
        localStorage.removeItem('optima_draft_lhp_qc_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_lhp_qc_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
```

- [ ] **Step 3: Alihkan test yang memakai route `store`**

Di `tests/Feature/Input/NgMultiJenisQcTest.php`, ubah setiap `post(route('input.qc.store'), $payload)` menjadi `post(route('input.qc.batch'), ['rows' => [$payload]])`. Kunci error ikut bersarang: `assertSessionHasErrors('ng_count')` menjadi `assertSessionHasErrors('rows.0.ng_count')`, dan seterusnya.

Test yang memakai route `update` (`input.qc.update`) TIDAK berubah — form edit tidak disentuh PR ini.

Ini mengubah asersi test yang sudah ada, dan itu memang diminta di sini — bedakan dari Task 1-3 di mana asersi lama tidak boleh disentuh. Yang dilarang tetap sama: jangan MELEMAHKAN asersi. Kalau sebuah test jadi tidak masuk akal di jalur batch, pindahkan cakupannya, jangan hapus diam-diam.

- [ ] **Step 4: Pensiunkan route dan method `store`**

Di `routes/web.php`, ubah `Route::resource('qc', QcController::class)->only(['create', 'store', 'destroy'])` menjadi `->only(['create', 'destroy'])`.

Hapus `QcController::store()`. Lalu:

```bash
grep -rn "StoreQcRequest\|input.qc.store\|qc.store" app/ routes/ resources/ tests/
```

Kalau `StoreQcRequest` nol pemakai, hapus `app/Http/Requests/StoreQcRequest.php` dan rapikan komentar mana pun yang menyebutnya. Kalau masih ada pemakai, JANGAN hapus — laporkan siapa pemakainya.

⚠️ Periksa `tests/Feature/DateRangeGuardTest.php`: kalau ia mengawasi `StoreQcRequest`, alihkan ke `StoreQcBatchRequest` dengan field bersarang (`rows.*.tgl_pengecekan`). Melewatkan langkah ini membuat penjaga tanggal mengawasi kelas yang sudah mati sementara request yang LIVE tidak dijaga sama sekali — persis yang hampir terjadi di PR #114.

- [ ] **Step 5: Jalankan seluruh suite, pastikan LULUS**

Run: `php artisan test`

Expected: PASS. Laporkan jumlahnya dan jelaskan tiap selisih dari jumlah sebelumnya.

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint app tests
git add -A
git commit -m "feat(qc): laci penulis dicabut, route store pensiun"
```

---

### Task 5: Gerbang akhir & PR

**Files:** tidak ada berkas baru.

**Interfaces:**
- Consumes: hasil Task 1-4.
- Produces: PR siap review.

- [ ] **Step 1: Seluruh suite**

Run: `php artisan test`
Expected: PASS.

- [ ] **Step 2: Gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: `54 file — 14 dijalankan saat deploy, 40 baseline` — tidak boleh berubah, PR ini nol migrasi.

- [ ] **Step 3: Format**

Run: `vendor/bin/pint app tests`
Expected: `{"tool":"pint","result":"passed"}`

- [ ] **Step 4: Periksa sisa**

```bash
grep -rn "qcPartSelector\|input.qc.store" app/ routes/ resources/
```
Expected: nol hasil.

```bash
grep -rn "erp-drawer" resources/views/input/qc/index.blade.php
```
Expected: tepat satu hasil — laci NOTE. Kalau dua, laci penulis belum tercabut.

- [ ] **Step 5: Buka PR**

```bash
git push -u origin feat/batch-quality
```

Judul: `feat(qc): halaman batch input LHP Quality`

Isi PR harus menyebut: (a) ini PR 2 dari 4 di `docs/superpowers/specs/2026-08-27-batch-input-empat-modul-design.md`, (b) konsumen pertama `batchDraft()` selain produksi, dan apakah `cfg` cukup atau ada yang harus dipaksakan, (c) halaman yatim `input.qc.create` diganti isinya, (d) nol migrasi, (e) aturan finish-good dipertahankan dan sekarang ada testnya.

---

## Catatan untuk PR berikutnya

QC adalah konsumen pertama `batchDraft()` yang ditulis dari nol. Kalau ada bagian `cfg` yang terasa dipaksakan — mis. `resetLain` harus tahu terlalu banyak soal isi halaman, atau `kumpulkan` perlu data yang tidak bisa dibaca dari form — catat di PR-nya. Memperbaiki bentuk `cfg` saat baru dua modul menumpanginya masih murah; menemukannya di PR 4 berarti membongkar empat halaman.

Satu kekurangan `cfg` sudah diketahui dan sengaja ditunda: nol callback setelah `mulai()` memulihkan draft. Breakdown (PR 4) membutuhkannya untuk melanjutkan `nomor_urut`; lihat spec §2.3.
