# Input Dandori Satu Alur — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Operator mencatat dandori langsung dari form LHP Produksi lewat centang "Ada Dandori", tanpa pindah halaman.

**Architecture:** Yang digabungkan **input**-nya, bukan tabelnya. Baris dandori tetap ditulis ke `transaksi_dandori_rincian` dengan bentuk yang sama persis seperti sekarang — hanya field sesinya diambil dari form LHP. Logika `std_menit`/`over_menit` diangkat ke satu layanan yang dipakai kedua jalur, supaya tidak ada dua definisi yang bisa menyimpang.

**Tech Stack:** Laravel 13 · PHP 8.4 (CI/prod) / 8.5.4 (lokal) · Blade + Alpine.js 3.14.1 · PHPUnit lewat `php artisan test`

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-08-22-dandori-satu-alur-design.md`
- **NOL perubahan pada perhitungan.** `MaintenanceDashboardService`, `MaintenanceDiesDashboardService`, `OeeService`, `OverviewService`, `KalenderKerjaService`, `DashboardService` **tidak boleh disentuh**. Angka Availability, OEE, Achievement, Performance, Loss Time, dan seluruh isi Overview wajib identik sebelum dan sesudah
- **NOL migrasi.** Tidak ada kolom, tabel, atau indeks baru. Kalau sebuah task terasa butuh tabel, berhenti dan tanya
- **Bentuk baris `transaksi_dandori_rincian` tidak berubah** — kolom yang ditulis sama persis dengan yang ditulis `DandoriController::store()` sekarang
- **Baris dandori TIDAK menyimpan tautan ke baris LHP.** 1.182 kombinasi tanggal+shift+mesin+proses punya lebih dari satu baris LHP (sampai 5); tautan apa pun butuh aturan sewenang-wenang
- **Blok dandori hanya di form PEMBUATAN** (`create.blade.php` dan laci `index.blade.php`), **tidak di `edit.blade.php`**
- Tiap modul Input punya **TIGA** form yang menulis; laci di `index.blade.php` yang paling sering dipakai operator dan paling sering kelewat
- **Seluruh test Dandori dan Produksi yang sudah ada harus tetap hijau tanpa payload-nya diubah.** Kalau ada yang merah, perilaku lama ikut berubah — dan itu di luar yang diminta
- **Direktif Blade tidak boleh menempel karakter kata** (`Filter@if` → 500). Sapuan: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
- Test: `php artisan test` (SQLite in-memory). Format: `vendor/bin/pint app tests`
- Komentar, label, dan pesan: **Indonesia**

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `app/Services/DandoriRincianService.php` | **Satu-satunya** definisi `std_menit`/`over_menit` + penulisan baris rincian |
| `app/Http/Controllers/Input/DandoriController.php` | Beralih memakai layanan itu; perilaku tidak berubah |
| `app/Http/Requests/StoreProduksiRequest.php` | Aturan `ada_dandori` / `dandori[]` / `dandori_count` |
| `app/Http/Controllers/Input/ProduksiController.php` | Menulis dandori saat LHP disimpan; mengirim `$losstimes` ke view |
| `resources/views/input/partials/dandori-rows.blade.php` | Baris berulang dandori — satu definisi, dipakai dua form |
| `resources/views/input/produksi/create.blade.php` | Centang + blok dandori |
| `resources/views/input/produksi/index.blade.php` | Sama, di laci input harian |
| `resources/views/input/dandori/index.blade.php` | Tombol input turun jadi "Dandori tanpa LHP" |
| `tests/Feature/Input/DandoriDariLhpTest.php` | Seluruh perilaku baru |

`edit.blade.php` dan `UpdateProduksiRequest` **tidak disentuh**.

---

### Task 1: Layanan penulis rincian dandori

**Files:**
- Create: `app/Services/DandoriRincianService.php`
- Modify: `app/Http/Controllers/Input/DandoriController.php` (method `store`)
- Test: `tests/Feature/Input/DandoriDariLhpTest.php`

**Interfaces:**
- Consumes: —
- Produces: `DandoriRincianService::simpan(array $sesi, array $rincian): int` — `$sesi` memuat kunci `tanggal`, `id_shift`, `id_karyawan`, `id_mesin`, `id_part_proses`; `$rincian` berisi `[['id_losstime' => int, 'actual_menit' => float], …]`; mengembalikan jumlah baris yang ditulis

- [ ] **Step 1: Write the failing tests**

Buat `tests/Feature/Input/DandoriDariLhpTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use App\Services\DandoriRincianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dandori dicatat dari form LHP Produksi. Yang digabungkan INPUT-nya, bukan
 * tabelnya: baris rincian tetap ditulis dengan bentuk yang sama persis, jadi
 * tidak satu pun perhitungan (Availability, OEE, Achievement, Loss Time)
 * membaca sesuatu yang berbeda.
 */
class DandoriDariLhpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = MasterShift::create([
            'nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00',
        ]);
        $this->karyawan = MasterKaryawan::create([
            'nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->karyawanLain = MasterKaryawan::create([
            'nik' => 'K2', 'nama_karyawan' => 'OPERATOR DUA', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A',
            'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500,
        ]);
        $this->prosesProgressive = MasterPartProses::create([
            'id_part' => $part->id, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500,
        ]);
        $this->losstime = MasterLosstime::create([
            'kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES',
            'std_menit_default' => 30, 'std_menit_progressive' => 45,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function sesi(?int $idProses = null): array
    {
        return [
            'tanggal' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $idProses ?? $this->proses->id,
        ];
    }

    public function test_menulis_satu_baris_per_rincian(): void
    {
        $jumlah = app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $this->assertSame(1, $jumlah);
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    public function test_std_menit_diambil_dari_master_dan_over_dihitung(): void
    {
        app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertEquals(30, $baris->std_menit);
        $this->assertEquals(20, $baris->over_menit);
    }

    public function test_proses_progressive_memakai_std_progressive(): void
    {
        // Cabang PROGRESSIVE sudah ada di DandoriController; ia harus ikut
        // pindah utuh, bukan disederhanakan.
        app(DandoriRincianService::class)->simpan($this->sesi($this->prosesProgressive->id), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertEquals(45, $baris->std_menit);
        $this->assertEquals(5, $baris->over_menit);
    }

    public function test_over_menit_tidak_pernah_negatif(): void
    {
        app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 10],
        ]);

        $this->assertEquals(0, TransaksiDandoriRincian::first()->over_menit);
    }

    public function test_rincian_kosong_tidak_menulis_apa_pun(): void
    {
        $jumlah = app(DandoriRincianService::class)->simpan($this->sesi(), []);

        $this->assertSame(0, $jumlah);
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }
}
```

Tambahkan properti yang dipakai `setUp()` di atas kelas: `private $shift, $karyawan, $karyawanLain, $mesin, $proses, $prosesProgressive, $losstime;`

Kalau `MasterShift::create` atau `MasterPartProses::create` menolak karena kolom wajib lain, lengkapi payload-nya sesuai skema — **jangan** melonggarkan asersinya.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php`
Expected: FAIL — `Target class [App\Services\DandoriRincianService] does not exist.`

- [ ] **Step 3: Tulis layanannya**

`app/Services/DandoriRincianService.php`:

```php
<?php

namespace App\Services;

use App\Models\MasterLosstime;
use App\Models\MasterPartProses;
use App\Models\TransaksiDandoriRincian;

/**
 * Satu-satunya tempat yang menentukan std_menit dan over_menit sebuah baris
 * dandori. Dipakai dua jalur input — form Dandori dan form LHP Produksi.
 *
 * Sengaja satu definisi: dua jalur yang menghitungnya sendiri-sendiri pasti
 * menyimpang, dan repo ini sudah membuktikannya berkali-kali pada komponen
 * yang disalin.
 */
class DandoriRincianService
{
    /**
     * @param  array  $sesi  tanggal, id_shift, id_karyawan, id_mesin, id_part_proses
     * @param  array  $rincian  [['id_losstime' => int, 'actual_menit' => float], ...]
     * @return int jumlah baris yang ditulis
     */
    public function simpan(array $sesi, array $rincian): int
    {
        if ($rincian === []) {
            return 0;
        }

        $partProses = MasterPartProses::findOrFail($sesi['id_part_proses']);
        $isProgressive = strtoupper(trim($partProses->nama_proses)) === 'PROGRESSIVE';

        $losstimeMap = MasterLosstime::whereIn(
            'id',
            collect($rincian)->pluck('id_losstime')->unique()
        )->get()->keyBy('id');

        foreach ($rincian as $item) {
            $losstime = $losstimeMap->get($item['id_losstime']);

            $stdMenit = $isProgressive
                ? ($losstime->std_menit_progressive ?? $losstime->std_menit_default ?? 0)
                : ($losstime->std_menit_default ?? 0);

            $actualMenit = (float) $item['actual_menit'];

            TransaksiDandoriRincian::create([
                'tanggal' => $sesi['tanggal'],
                'id_shift' => $sesi['id_shift'],
                'id_karyawan' => $sesi['id_karyawan'],
                'id_mesin' => $sesi['id_mesin'],
                'id_part_proses' => $sesi['id_part_proses'],
                'id_losstime' => $item['id_losstime'],
                'actual_menit' => $actualMenit,
                'std_menit' => $stdMenit,
                'over_menit' => max(0, $actualMenit - $stdMenit),
                'created_by' => auth()->id(),
            ]);
        }

        return count($rincian);
    }
}
```

- [ ] **Step 4: Alihkan DandoriController ke layanan itu**

Di `app/Http/Controllers/Input/DandoriController.php`, suntikkan layanan lewat konstruktor (kalau belum ada konstruktor, buat satu), lalu ganti seluruh isi `store()` setelah `$v = $request->validated();` menjadi:

```php
        $count = $this->dandoriRincianService->simpan([
            'tanggal' => $v['tanggal'],
            'id_shift' => $v['id_shift'],
            'id_karyawan' => $v['id_karyawan'],
            'id_mesin' => $v['id_mesin'],
            'id_part_proses' => $v['id_part_proses'],
        ], $v['rincian']);

        return redirect()->route('input.dandori.index')
            ->with('success', "{$count} baris data Dandori berhasil disimpan.");
```

Buang `use` yang jadi tidak terpakai di controller itu, dan **jangan ubah** `StoreDandoriRequest`, method `edit`, `update`, maupun `destroy`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php`
Expected: PASS (5 test)

Run: `php artisan test tests/Feature/Input/`
Expected: PASS — seluruh test Dandori dan Produksi yang sudah ada tetap hijau **tanpa payload-nya diubah**. Kalau ada yang merah, perilaku lama ikut berubah dan itu di luar lingkup: laporkan, jangan sesuaikan testnya.

- [ ] **Step 6: Commit**

```bash
git add app/Services/DandoriRincianService.php app/Http/Controllers/Input/DandoriController.php tests/Feature/Input/DandoriDariLhpTest.php
git commit -m "refactor(dandori): std_menit & over_menit jadi satu definisi di layanan"
```

---

### Task 2: LHP Produksi menulis dandori

**Files:**
- Modify: `app/Http/Requests/StoreProduksiRequest.php`
- Modify: `app/Http/Controllers/Input/ProduksiController.php` (method `store`)
- Test: `tests/Feature/Input/DandoriDariLhpTest.php` (tambah)

**Interfaces:**
- Consumes: `DandoriRincianService::simpan(array $sesi, array $rincian): int`
- Produces: route `input.produksi.store` menerima `ada_dandori`, `dandori[]` (`id_losstime`, `actual_menit`), `dandori_count`, dan `dandori_karyawan_id`

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Input/DandoriDariLhpTest.php` (tambahkan `use App\Models\TransaksiProduksi;` di bagian import):

```php
    /** Payload LHP minimal yang sah; blok dandori ditambahkan per test. */
    private function payloadLhp(array $tambahan = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '15:00',
            'qty_aktual' => 1000,
            'ng_count' => 0,
        ], $tambahan);
    }

    public function test_menyimpan_rincian_dandori_bersama_lhp(): void
    {
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 2,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
                ['id_losstime' => $this->losstimeKedua->id, 'actual_menit' => 10],
            ],
        ]))->assertRedirect();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(2, TransaksiDandoriRincian::count());

        // Field sesi harus persis mengikuti baris LHP-nya.
        $baris = TransaksiDandoriRincian::first();
        $this->assertSame('2026-08-20', $baris->tanggal->toDateString());
        $this->assertSame($this->shift->id, $baris->id_shift);
        $this->assertSame($this->mesin->id, $baris->id_mesin);
        $this->assertSame($this->proses->id, $baris->id_part_proses);
        $this->assertSame($this->karyawan->id, $baris->id_karyawan);
    }

    public function test_tanpa_centang_tidak_menulis_dandori(): void
    {
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'dandori_count' => 0,
        ]))->assertRedirect();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_hitungan_tidak_cocok_ditolak(): void
    {
        // dandori_count membuktikan Alpine hidup, bukan bahwa baris dandori[]
        // benar-benar sampai. Tanpa penjaga ini, payload terpotong menyimpan
        // NOL baris dandori tanpa satu pun error — operator mengira tercatat.
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 2,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
            ],
        ]))->assertSessionHasErrors('dandori_count');

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_jenis_loss_time_kembar_ditolak(): void
    {
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 2,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 20],
            ],
        ]))->assertSessionHasErrors('dandori.0.id_losstime');

        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_operator_dandori_bisa_beda_dari_lhp(): void
    {
        // Probe prod menemukan 250 sesi dengan operator dandori berbeda dari
        // operator LHP-nya; mewarisi tanpa bisa diubah memaksa data yang salah.
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori_karyawan_id' => $this->karyawanLain->id,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
            ],
        ]))->assertRedirect();

        $this->assertSame($this->karyawanLain->id, TransaksiDandoriRincian::first()->id_karyawan);
    }

    public function test_lhp_gagal_tidak_meninggalkan_baris_dandori(): void
    {
        // Keduanya ditulis dalam satu transaksi; separuh tersimpan lebih buruk
        // daripada gagal, karena dandori tanpa LHP tidak terlihat salah.
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'qty_aktual' => -5,
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
            ],
        ]))->assertSessionHasErrors();

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }
```

Tambahkan `$losstimeKedua` di `setUp()` beserta propertinya:

```php
        $this->losstimeKedua = MasterLosstime::create([
            'kode' => 'LT2', 'jenis_losstime' => 'SETTING',
            'std_menit_default' => 15, 'std_menit_progressive' => 20,
        ]);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php`
Expected: FAIL — baris dandori tidak tertulis; `dandori_count` belum divalidasi

- [ ] **Step 3: Tambahkan aturan validasi**

Di `app/Http/Requests/StoreProduksiRequest.php`, tambahkan ke `rules()`:

```php
            'ada_dandori' => 'nullable|boolean',
            'dandori_count' => 'nullable|integer|min:0',
            'dandori' => 'array|required_if:ada_dandori,1',
            'dandori.*.id_losstime' => 'required|integer|exists:master_losstime,id|distinct',
            'dandori.*.actual_menit' => 'required|numeric|min:0.01',
            'dandori_karyawan_id' => 'nullable|integer|exists:master_karyawan,id',
```

⚠️ `dandori_count` sengaja `nullable`, **berbeda dari `ng_count` yang `required`**. Alasannya: `ng_count` sudah ada sejak awal sehingga seluruh payload test lama memuatnya, sedangkan menambahkan `required` untuk dandori akan membuat test Produksi lama merah — padahal perilaku lamanya tidak berubah. Penjaganya tetap bekerja penuh karena form selalu mengirim input tersembunyi itu; yang dilonggarkan hanya request yang tidak menyebut dandori sama sekali.

Di `prepareForValidation()`, tambahkan setelah blok `ada_ng` yang sudah ada:

```php
        /* Kalau ada_dandori tidak dicentang, buang seluruh baris dandori. */
        if (! $this->boolean('ada_dandori')) {
            $this->merge(['dandori' => []]);
        }
```

Di `withValidator()`, tambahkan di dalam `$validator->after(...)` yang sudah ada:

```php
            /*
             * Sama seperti ng_count: hitungan ini membuktikan Alpine hidup,
             * bukan bahwa baris dandori[] benar-benar sampai. Payload dengan
             * dandori_count=2 tapi dandori[] hilang akan lolos aturan di atas
             * dan menyimpan NOL baris dandori tanpa satu pun error — operator
             * mengira sudah tercatat.
             *
             * Dilewati kalau dandori_count tidak dikirim sama sekali: request
             * seperti itu memang tidak menyebut dandori.
             */
            if ($this->has('dandori_count')) {
                $dandoriCount = (int) $this->input('dandori_count', 0);
                $dandoriTerkirim = is_array($this->input('dandori')) ? count($this->input('dandori')) : 0;

                if ($dandoriCount !== $dandoriTerkirim) {
                    $validator->errors()->add(
                        'dandori_count',
                        "Jumlah baris Dandori yang tercatat ({$dandoriCount}) tidak sama dengan yang terkirim ({$dandoriTerkirim}). "
                        .'Coba simpan ulang.'
                    );
                }
            }
```

- [ ] **Step 4: Tulis dandori saat LHP disimpan**

Di `app/Http/Controllers/Input/ProduksiController.php`, suntikkan `DandoriRincianService` lewat konstruktor yang sudah ada (di sebelah `NgDetailService`), lalu ubah `store()`:

```php
    public function store(StoreProduksiRequest $request)
    {
        $validated = $request->validated();
        $ngRows = $validated['ng'] ?? [];
        $dandoriRows = $validated['dandori'] ?? [];
        $dandoriKaryawan = $validated['dandori_karyawan_id'] ?? $validated['id_karyawan'];
        unset(
            $validated['ng'], $validated['ada_ng'], $validated['ng_count'],
            $validated['dandori'], $validated['ada_dandori'], $validated['dandori_count'],
            $validated['dandori_karyawan_id'],
        );

        $cycleTime = $this->produksiService->calculateActualCycleTime(
            $validated['jam_start'].':00',
            $validated['jam_finish'].':00',
            $validated['qty_aktual']
        );

        DB::transaction(function () use ($validated, $cycleTime, $ngRows, $dandoriRows, $dandoriKaryawan) {
            $produksi = TransaksiProduksi::create([
                ...$validated,
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
                'tanggal' => $validated['tanggal_produksi'],
                'id_shift' => $validated['id_shift'],
                'id_karyawan' => $dandoriKaryawan,
                'id_mesin' => $validated['id_mesin'],
                'id_part_proses' => $validated['id_part_proses'],
            ], $dandoriRows);
        });

        return redirect()->route('input.produksi.index')
            ->with('success', 'LHP Produksi berhasil disimpan.');
    }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php`
Expected: PASS (11 test)

Run: `php artisan test tests/Feature/Input/`
Expected: PASS — test Produksi lama tetap hijau tanpa payload-nya diubah

- [ ] **Step 6: Commit**

```bash
git add app/Http/Requests/StoreProduksiRequest.php app/Http/Controllers/Input/ProduksiController.php tests/Feature/Input/DandoriDariLhpTest.php
git commit -m "feat(produksi): simpan rincian dandori dari form LHP"
```

---

### Task 3: Blok dandori di dua form pembuatan

**Files:**
- Create: `resources/views/input/partials/dandori-rows.blade.php`
- Modify: `app/Http/Controllers/Input/ProduksiController.php` (method `index` dan `create`)
- Modify: `resources/views/input/produksi/create.blade.php`
- Modify: `resources/views/input/produksi/index.blade.php`
- Test: `tests/Feature/Input/DandoriDariLhpTest.php` (tambah)

**Interfaces:**
- Consumes: nama field dari Task 2 — `ada_dandori`, `dandori[i][id_losstime]`, `dandori[i][actual_menit]`, `dandori_count`, `dandori_karyawan_id`
- Produces: partial `input.partials.dandori-rows` dengan parameter `$losstimes` (Collection `MasterLosstime`), `$karyawan` (Collection), `$initial` (array)

⚠️ **Laci di `index.blade.php` adalah form yang paling sering dipakai operator dan paling sering kelewat.** Melewatkannya berarti jalur utama diam-diam tidak bisa menyimpan dandori sementara `create` yang jarang dibuka terlihat baik-baik saja. Itu sudah terjadi persis begitu pada NG multi-jenis.

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Input/DandoriDariLhpTest.php`:

```php
    /** Ambil isi satu blok saja — nama field muncul di lebih dari satu tempat di halaman. */
    private function blokDandori(string $html): string
    {
        $awal = strpos($html, 'class="dandori-rows"');
        $this->assertNotFalse($awal, 'Blok dandori tidak ditemukan.');

        return substr($html, $awal, 3000);
    }

    public function test_form_create_punya_blok_dandori(): void
    {
        $html = $this->get(route('input.produksi.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="ada_dandori"', $html);
        $this->assertStringContainsString('dandori_count', $this->blokDandori($html));
    }

    public function test_laci_index_punya_blok_dandori(): void
    {
        // Laci di index.blade.php adalah form yang paling sering dipakai
        // operator, dan yang paling sering kelewat saat menambah field.
        $html = $this->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="ada_dandori"', $html);
        $this->assertStringContainsString('dandori_count', $this->blokDandori($html));
    }

    public function test_form_edit_tidak_punya_blok_dandori(): void
    {
        // Baris dandori tidak menyimpan tautan ke baris LHP, jadi di Edit tidak
        // ada cara jujur untuk tahu baris mana yang "milik" baris LHP ini.
        $produksi = \App\Models\TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00:00',
            'jam_finish' => '15:00:00',
            'qty_aktual' => 1000,
            'created_by' => auth()->id(),
        ]);

        $html = $this->get(route('input.produksi.edit', $produksi))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="ada_dandori"', $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php --filter blok_dandori`
Expected: FAIL — `Blok dandori tidak ditemukan.`

- [ ] **Step 3: Buat partialnya**

`resources/views/input/partials/dandori-rows.blade.php`:

```blade
{{--
    Baris Dandori berulang — dipakai form create dan laci index LHP Produksi.
    Mengikuti pola input/partials/ng-rows.

    Parameter:
      $losstimes : Collection MasterLosstime
      $karyawan  : Collection MasterKaryawan (untuk operator dandori)
      $initial   : array [['id_losstime' => int, 'actual_menit' => float], ...]
--}}
<div x-data="dandoriRows(@js($initial ?? []))" class="dandori-rows">

    {{--
        Wajib mengevaluasi binding Alpine. Kalau Alpine gagal boot, baris
        dandori[] yang hidup di dalam x-for tidak pernah dirender, dan tanpa
        hitungan ini payload lolos validasi lalu menyimpan NOL baris dandori
        tanpa satu pun error — operator mengira sudah tercatat.
    --}}
    <input type="hidden" name="dandori_count" :value="rows.length">

    <div class="mb-2">
        <label class="form-label small fw-semibold">Operator Dandori</label>
        <select class="form-select form-select-sm" name="dandori_karyawan_id">
            <option value="">— sama dengan operator LHP —</option>
            @foreach($karyawan as $k)
                <option value="{{ $k->id }}" @selected(old('dandori_karyawan_id') == $k->id)>{{ $k->nama_karyawan }}</option>
            @endforeach
        </select>
    </div>

    <template x-if="rows.length === 0">
        <div class="text-muted small mb-2">Belum ada aktivitas dandori. Klik tombol di bawah untuk menambah.</div>
    </template>

    <template x-for="(row, i) in rows" :key="row.uid">
        <div class="row g-2 mb-2 align-items-center">
            <div class="col-7">
                <select class="form-select form-select-sm"
                        :name="`dandori[${i}][id_losstime]`"
                        x-model="row.id_losstime" required>
                    <option value="">— pilih jenis loss time —</option>
                    @foreach($losstimes as $l)
                        <option value="{{ $l->id }}">{{ $l->kode }} — {{ $l->jenis_losstime }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-3">
                <input type="number" min="0.01" step="0.01"
                       class="form-control form-control-sm"
                       :name="`dandori[${i}][actual_menit]`"
                       x-model.number="row.actual_menit"
                       placeholder="Menit" required>
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
        <i class="bi bi-plus-lg"></i> Tambah aktivitas dandori
    </button>

    <div class="mt-2 small">
        Total menit: <strong x-text="total"></strong>
    </div>

    @error('dandori')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('dandori_count')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
    @error('dandori.0.id_losstime')<div class="text-danger mt-1" style="font-size:0.8rem">{{ $message }}</div>@enderror
</div>

@once
@push('scripts')
<script>
function dandoriRows(initial) {
    let seq = 0;
    return {
        rows: (initial || []).map(r => ({
            uid: seq++,
            id_losstime: String(r.id_losstime ?? ''),
            actual_menit: r.actual_menit ?? null,
        })),
        add() {
            this.rows.push({ uid: seq++, id_losstime: '', actual_menit: null });
        },
        remove(i) {
            this.rows.splice(i, 1);
        },
        get total() {
            return this.rows.reduce((s, r) => s + (Number(r.actual_menit) || 0), 0);
        },
    };
}
</script>
@endpush
@endonce
```

⚠️ Jaga spasi sebelum tiap direktif Blade. `@selected(...)` yang menempel karakter kata tidak dikompilasi sementara pasangannya iya, dan halamannya 500.

- [ ] **Step 4: Kirim daftar loss time ke kedua view**

Di `app/Http/Controllers/Input/ProduksiController.php`, pada method `index` dan `create`, tambahkan di sebelah `$jenisNg` yang sudah ada:

```php
        $losstimes = \App\Models\MasterLosstime::orderBy('kode')->get();
```

dan tambahkan `'losstimes'` ke `compact(...)` masing-masing.

- [ ] **Step 5: Pasang blok di create dan di laci index**

Di `resources/views/input/produksi/create.blade.php` dan `resources/views/input/produksi/index.blade.php`, tepat setelah blok NG yang sudah ada, sisipkan blok berikut. Sesuaikan `id` centangnya: pakai `ada_dandori` di `create.blade.php` dan `ada_dandori_drawer` di `index.blade.php`, mengikuti cara blok NG membedakan keduanya.

```blade
                <div class="col-12" x-data="{ adaDandori: {{ old('ada_dandori') ? 'true' : 'false' }} }">
                    <div class="form-check">
                        {{-- Melepas centang MENGOSONGKAN baris dandori-rows, bukan sekadar
                             menyembunyikannya, supaya dandori_count selalu konsisten
                             dengan dandori[] yang terkirim. --}}
                        <input class="form-check-input" type="checkbox" name="ada_dandori"
                               id="ada_dandori" value="1" x-model="adaDandori"
                               @change="if (!adaDandori) { const w = $el.closest('[x-data]').querySelector('.dandori-rows'); if (w) Alpine.$data(w).rows = []; }">
                        <label class="form-check-label fw-semibold" for="ada_dandori">Ada Dandori</label>
                    </div>
                    <div x-show="adaDandori" x-cloak class="mt-2">
                        @include('input.partials.dandori-rows', [
                            'losstimes' => $losstimes,
                            'karyawan' => $karyawan,
                            'initial' => old('dandori', []),
                        ])
                    </div>
                </div>
```

**Jangan** menyentuh `edit.blade.php`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php`
Expected: PASS (14 test)

Run: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
Expected: tidak ada keluaran

Run: `php artisan test tests/Feature/Layout/ErpWorkspaceMarkupTest.php`
Expected: PASS — susunan `.erp-ws` halaman LHP Produksi tidak rusak

- [ ] **Step 7: Commit**

```bash
git add resources/views/input/partials/dandori-rows.blade.php resources/views/input/produksi/create.blade.php resources/views/input/produksi/index.blade.php app/Http/Controllers/Input/ProduksiController.php tests/Feature/Input/DandoriDariLhpTest.php
git commit -m "feat(produksi): blok Ada Dandori di form create dan laci index"
```

---

### Task 4: Halaman Dandori jadi daftar

**Files:**
- Modify: `resources/views/input/dandori/index.blade.php` (tombol input di toolbar)
- Test: `tests/Feature/Input/DandoriDariLhpTest.php` (tambah)

**Interfaces:**
- Consumes: —
- Produces: —

- [ ] **Step 1: Write the failing tests**

Tambahkan ke `tests/Feature/Input/DandoriDariLhpTest.php`:

```php
    public function test_halaman_dandori_menandai_input_sebagai_jalur_sekunder(): void
    {
        // 17% sesi dandori pada era aplikasi tidak punya baris LHP — ganti dies
        // yang produksinya batal atau jalan di shift berikutnya. Mencabut
        // tombolnya membuat kejadian itu tidak bisa dicatat sama sekali.
        $html = $this->get(route('input.dandori.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Dandori tanpa LHP', $html);
    }

    public function test_mengubah_lhp_tidak_menyentuh_dandori(): void
    {
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->losstime->id, 'actual_menit' => 50]],
        ]))->assertRedirect();

        $produksi = \App\Models\TransaksiProduksi::first();
        $sebelum = TransaksiDandoriRincian::first()->only(['id_losstime', 'actual_menit', 'std_menit', 'over_menit']);

        $this->put(route('input.produksi.update', $produksi), $this->payloadLhp([
            'qty_aktual' => 2000,
        ]))->assertRedirect();

        $this->assertSame(1, TransaksiDandoriRincian::count());
        $this->assertSame($sebelum, TransaksiDandoriRincian::first()->only(['id_losstime', 'actual_menit', 'std_menit', 'over_menit']));
    }

    public function test_menghapus_lhp_tidak_menghapus_dandori(): void
    {
        // Keduanya catatan yang berdiri sendiri; dandori tetap sah walau baris
        // LHP-nya dihapus.
        $this->post(route('input.produksi.store'), $this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->losstime->id, 'actual_menit' => 50]],
        ]))->assertRedirect();

        $this->delete(route('input.produksi.destroy', \App\Models\TransaksiProduksi::first()))->assertRedirect();

        $this->assertSame(1, TransaksiDandoriRincian::count());
    }
```

Kalau `input.produksi.update` menolak payload di atas karena `UpdateProduksiRequest` mensyaratkan field lain (mis. `ng_count`), lengkapi payload-nya — **jangan** mengubah request class-nya.

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php --filter jalur_sekunder`
Expected: FAIL — teks "Dandori tanpa LHP" belum ada

- [ ] **Step 3: Turunkan tombolnya jadi jalur sekunder**

Di `resources/views/input/dandori/index.blade.php`, di dalam `<div class="erp-tb">` (sekitar baris 376), ganti blok ini:

```blade
    @if(! auth()->user()->isGuest())
    <button type="button" class="erp-tb-btn is-new" @click="drawerOpen = true">
        <i class="bi bi-plus-lg"></i>Input Baru
    </button>
    @endif
```

menjadi:

```blade
    {{-- Jalur sekunder. Alur utama pencatatan dandori sekarang lewat centang
         "Ada Dandori" di form LHP Produksi; tombol ini untuk 17% kejadian yang
         memang tidak punya baris LHP — ganti dies yang produksinya batal, atau
         yang produksinya jalan di shift berikutnya. Gaya `is-new` (hijau,
         dipakai aksi Tambah utama) sengaja dilepas supaya ia tidak lagi
         terbaca sebagai jalan masuk utama. --}}
    @if(! auth()->user()->isGuest())
    <button type="button" class="erp-tb-btn" @click="drawerOpen = true">
        <i class="bi bi-plus-lg"></i>Dandori tanpa LHP
    </button>
    @endif
```

Ubah juga judul lacinya (sekitar baris 84) dari `Input Dandori` menjadi `Dandori tanpa LHP`, supaya laci yang terbuka menyebut hal yang sama dengan tombol yang membukanya.

Jangan ubah kolom, filter, Edit, Hapus, atau Import di halaman itu.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Input/DandoriDariLhpTest.php`
Expected: PASS (17 test)

Run: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
Expected: tidak ada keluaran

- [ ] **Step 5: Commit**

```bash
git add resources/views/input/dandori/index.blade.php tests/Feature/Input/DandoriDariLhpTest.php
git commit -m "feat(dandori): halaman jadi daftar, input turun jadi jalur sekunder"
```

---

### Task 5: Gerbang akhir & PR

**Files:** seluruh suite

- [ ] **Step 1: Buktikan angka Overview tidak bergeser**

Ini syarat utama yang diminta pemilik sistem. Layanan perhitungan tidak boleh tersentuh sama sekali:

```bash
git diff main --stat -- app/Services/MaintenanceDashboardService.php app/Services/MaintenanceDiesDashboardService.php app/Services/OeeService.php app/Services/OverviewService.php app/Services/KalenderKerjaService.php app/Services/DashboardService.php
```
Expected: **tidak ada keluaran** — nol baris berubah di keenam berkas itu

```bash
git diff main --stat -- database/migrations/
```
Expected: **tidak ada keluaran** — nol migrasi

- [ ] **Step 2: Jalankan seluruh gerbang**

```bash
php artisan test
```
Expected: PASS — 939 test lama + 17 test baru, nol gagal. **Tidak satu pun test lama boleh diubah payload-nya.**

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
Expected: `✓ Cakupan migrasi lengkap` dengan jumlah **tidak berubah**

- [ ] **Step 3: Buktikan penjaganya menangkap**

Kembalikan tiap bug sebentar, pastikan penjaganya jatuh, lalu kembalikan lagi:

- Hapus blok `if ($this->has('dandori_count'))` di `StoreProduksiRequest` → `test_hitungan_tidak_cocok_ditolak` harus MERAH
- Hapus `|distinct` dari `dandori.*.id_losstime` → `test_jenis_loss_time_kembar_ditolak` harus MERAH
- Ganti `$dandoriKaryawan` jadi `$validated['id_karyawan']` di `ProduksiController::store()` → `test_operator_dandori_bisa_beda_dari_lhp` harus MERAH
- Hapus blok dandori dari laci `index.blade.php` → `test_laci_index_punya_blok_dandori` harus MERAH

Penjaga yang tidak pernah terlihat merah belum terbukti menjaga apa pun.

- [ ] **Step 4: Periksa mata**

Render halaman LHP Produksi sebagai `staff_produksi` ke berkas HTML **di dalam folder proyek** (berkas di luar folder proyek hanya dirender sebagai cuplikan statis), buka di peramban, lalu hapus lagi berkas dan test sementaranya.

Yang diperiksa: centang "Ada Dandori" memunculkan blok · tombol tambah baris bekerja · melepas centang mengosongkan baris · laci di halaman index punya blok yang sama.

Laporkan apa yang terlihat, bukan cuma bahwa langkahnya dijalankan.

- [ ] **Step 5: Push dan buka PR**

```bash
git push -u origin feat/dandori-satu-alur
```

Badan PR wajib memuat: tautan ke spec, ringkasan lima probe yang mendasari keputusannya (60% → 17% sesudah era migrasi dipisahkan), pernyataan bahwa **nol rumus dan nol migrasi** disentuh berikut bukti `git diff --stat` yang kosong, alasan blok dandori tidak ada di form Edit, dan temuan atribusi part Jan–Mei yang **dilaporkan tapi tidak dikerjakan**.

- [ ] **Step 6: Tunggu CI hijau sebelum merge**

```bash
gh pr checks <nomor>
```
Expected: `test pass`

---

## Verifikasi Manual Setelah Deploy

1. Buka LHP Produksi → laci input → centang **Ada Dandori** → tambah dua baris → simpan. Cek halaman Dandori: dua baris baru muncul dengan tanggal, shift, mesin, dan part yang sama dengan LHP-nya.
2. Ulangi lewat form **Tambah** (`create`) — hasilnya harus sama.
3. Simpan LHP **tanpa** mencentang → tidak ada baris dandori baru.
4. Isi Operator Dandori berbeda dari operator LHP → baris dandori memakai operator yang dipilih.
5. Buka **Edit** sebuah LHP → tidak ada blok dandori di sana.
6. Halaman Dandori: tombol **"Dandori tanpa LHP"** masih bisa dipakai dan tetap menyimpan seperti biasa.
7. Buka `/overview` dan satu halaman VCB — angkanya harus sama seperti sebelum deploy.
