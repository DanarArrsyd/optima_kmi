# Dashboard NG (VCB NG Proses & VCB NG Total) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dua halaman dashboard read-only yang menampilkan NG Rate per bulan, dan saat satu bulan diklik menampilkan rincian jenis NG beserta qty-nya pada bulan itu.

**Architecture:** Service baru `NgDashboardService` memegang seluruh agregasi, dengan satu parameter `$sumber` (`'produksi'` / `'qc'`) memilih tabel, kolom tanggal, dan kolom qty baik. Dua controller tipis memanggil service yang sama dan merender satu Blade bersama. Nol migrasi, nol route group baru.

**Tech Stack:** Laravel 13, PHP 8.4 (CI & prod) / 8.5.4 (lokal), SQLite in-memory untuk test, Blade + Bootstrap 5.3.3 + Alpine.js 3.14.1 + Chart.js 4.4.4.

**Spec:** `docs/superpowers/specs/2026-07-28-vcb-ng-dashboard-design.md`

## Global Constraints

- **Nol migrasi baru.** Tidak ada perubahan pada `.github/workflows/deploy.yml`. Kalau sebuah task terasa butuh migrasi, itu tanda salah paham — semua kolom sudah live sejak Tahap 1.
- **Rumus NG Rate, dikunci:** `qty_ng ÷ (qty_baik + qty_ng) × 100`. Produksi: `qty_baik = qty_aktual`. Quality: `qty_baik = qty_ok`. Nilai `qty_aktual` berisi barang baik saja, NG dicatat terpisah.
- **Bulan dengan transaksi tapi nol NG tampil sebagai NG Rate 0%. Bulan tanpa transaksi sama sekali tidak muncul.**
- Semua pembacaan tabel detail dibungkus `App\Services\NgDetailService::available()`. Bila tabel belum ada, kembalikan struktur kosong — jangan biarkan 500.
- Sesi ter-soft-delete tidak boleh ikut terhitung.
- `created_by` adalah FK **non-nullable** pada `transaksi_produksi` dan `transaksi_qc`. Setiap test yang membuat model itu langsung wajib mengisinya, jika tidak akan gagal pada NOT NULL, bukan pada hal yang sedang diuji.
- Halaman read-only. Nol route write, nol perubahan `DenyGuestWrite`.
- Test: `php artisan test` (SQLite in-memory, tidak butuh DB lokal).
- Format: `vendor/bin/pint app tests` — scope Pint hanya `app/` dan `tests/`, **jangan** dijalankan pada file Blade.
- Blade harus terkompilasi: `php artisan view:cache && php artisan view:clear`.

---

## File Structure

**Dibuat:**

| File | Tanggung jawab |
|---|---|
| `app/Services/NgDashboardService.php` | Seluruh agregasi NG — dua method, satu parameter sumber |
| `app/Http/Controllers/Vcb/VcbNgProsesController.php` | Halaman NG Proses: index + data + detail |
| `app/Http/Controllers/Vcb/VcbNgTotalController.php` | Halaman NG Total: idem, sumber `'qc'` |
| `resources/views/vcb/ng.blade.php` | Satu view dipakai kedua halaman |
| `tests/Unit/NgDashboardServiceTest.php` | Rumus, pengelompokan bulan, keadaan kosong |
| `tests/Feature/Vcb/VcbNgDashboardTest.php` | Route, otorisasi, bentuk JSON |

**Diubah:**

| File | Perubahan |
|---|---|
| `routes/web.php` | 6 route baru, masuk dua grup role yang sudah ada |
| `resources/views/layouts/app.blade.php` | Dua entri sidebar |

---

## Task 1: `NgDashboardService::getVcbNg()`

**Files:**
- Create: `app/Services/NgDashboardService.php`
- Test: `tests/Unit/NgDashboardServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\TransaksiProduksi`, `App\Models\TransaksiQc` (keduanya punya relasi `ngDetails()`), `App\Services\NgDetailService::available()`.
- Produces:
  ```php
  NgDashboardService::getVcbNg(string $sumber, string $dateFrom, string $dateTo): array
  // $sumber: 'produksi' | 'qc'
  // $dateFrom, $dateTo: 'YYYY-MM-DD'
  // return:
  // [
  //   'summary'  => ['total_ng' => int, 'total_check' => int, 'ng_rate' => float],
  //   'by_month' => [
  //       ['bulan' => 'Mar 2026', 'bulan_raw' => '2026-03',
  //        'total_ng' => int, 'total_check' => int, 'ng_rate' => float],
  //       ...   // urut menaik menurut bulan_raw
  //   ],
  // ]
  ```

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Unit/NgDashboardServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\NgDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgDashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private NgDashboardService $svc;

    private User $user;

    private MasterShift $shift;

    private MasterKaryawan $karyawan;

    private MasterMesin $mesin;

    private MasterPartProses $proses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->svc = app(NgDashboardService::class);
        $this->user = User::factory()->create();
        $this->shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op NG Dash', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'ND-01', 'nama_mesin' => 'Press ND', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'ND-P1', 'part_name' => 'ND Part', 'customer' => 'Toyota']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
    }

    private function produksi(string $tanggal, int $qtyAktual, int $qtyNg): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => $tanggal,
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_aktual' => $qtyAktual,
            'qty_ng' => $qtyNg,
            'created_by' => $this->user->id,
        ]);
    }

    private function qc(string $tanggal, int $qtyOk, int $qtyNg): TransaksiQc
    {
        return TransaksiQc::create([
            'tgl_pengecekan' => $tanggal,
            'tgl_produksi_part' => $tanggal,
            'id_shift' => $this->shift->id,
            'id_karyawan_qc' => $this->karyawan->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_ok' => $qtyOk,
            'qty_ng' => $qtyNg,
            'created_by' => $this->user->id,
        ]);
    }

    public function test_ng_rate_produksi_memakai_qty_aktual_plus_ng_sebagai_penyebut(): void
    {
        // 5 NG dari (95 baik + 5 NG) = 5%
        $this->produksi('2026-03-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame('2026-03', $hasil['by_month'][0]['bulan_raw']);
        $this->assertSame(5, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
        $this->assertSame(5.0, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_ng_rate_qc_memakai_qty_ok_plus_ng_sebagai_penyebut(): void
    {
        // 10 NG dari (90 ok + 10 NG) = 10%
        $this->qc('2026-03-10', 90, 10);

        $hasil = $this->svc->getVcbNg('qc', '2026-01-01', '2026-12-31');

        $this->assertSame(10, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
        $this->assertSame(10.0, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_beberapa_sesi_dalam_satu_bulan_dijumlahkan(): void
    {
        $this->produksi('2026-03-01', 90, 10);
        $this->produksi('2026-03-20', 190, 10);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame(20, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(300, $hasil['by_month'][0]['total_check']);
        $this->assertSame(6.7, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_bulan_dengan_transaksi_tapi_nol_ng_tampil_sebagai_nol_persen(): void
    {
        $this->produksi('2026-03-10', 100, 0);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame(0, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
        $this->assertSame(0.0, $hasil['by_month'][0]['ng_rate']);
    }

    public function test_bulan_tanpa_transaksi_sama_sekali_tidak_muncul(): void
    {
        $this->produksi('2026-03-10', 95, 5);
        $this->produksi('2026-05-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        // April tidak punya transaksi — tidak boleh muncul sebagai bulan 0%.
        $this->assertCount(2, $hasil['by_month']);
        $this->assertSame(['2026-03', '2026-05'], array_column($hasil['by_month'], 'bulan_raw'));
    }

    public function test_urut_menaik_menurut_bulan(): void
    {
        $this->produksi('2026-05-10', 95, 5);
        $this->produksi('2026-01-10', 95, 5);
        $this->produksi('2026-03-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame(['2026-01', '2026-03', '2026-05'], array_column($hasil['by_month'], 'bulan_raw'));
    }

    public function test_di_luar_rentang_tidak_ikut(): void
    {
        $this->produksi('2026-03-10', 95, 5);
        $this->produksi('2026-08-10', 95, 5);

        $hasil = $this->svc->getVcbNg('produksi', '2026-03-01', '2026-03-31');

        $this->assertCount(1, $hasil['by_month']);
        $this->assertSame('2026-03', $hasil['by_month'][0]['bulan_raw']);
    }

    public function test_sesi_ter_soft_delete_tidak_ikut_terhitung(): void
    {
        $this->produksi('2026-03-10', 95, 5);
        $this->produksi('2026-03-11', 90, 10)->delete();

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame(5, $hasil['by_month'][0]['total_ng']);
        $this->assertSame(100, $hasil['by_month'][0]['total_check']);
    }

    public function test_rentang_tanpa_data_mengembalikan_struktur_kosong(): void
    {
        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame([], $hasil['by_month']);
        $this->assertSame(0, $hasil['summary']['total_ng']);
        $this->assertSame(0, $hasil['summary']['total_check']);
        $this->assertSame(0.0, $hasil['summary']['ng_rate']);
    }

    public function test_summary_menjumlahkan_seluruh_rentang(): void
    {
        $this->produksi('2026-03-10', 90, 10);
        $this->produksi('2026-05-10', 190, 10);

        $hasil = $this->svc->getVcbNg('produksi', '2026-01-01', '2026-12-31');

        $this->assertSame(20, $hasil['summary']['total_ng']);
        $this->assertSame(300, $hasil['summary']['total_check']);
        $this->assertSame(6.7, $hasil['summary']['ng_rate']);
    }

    public function test_sumber_tidak_dikenal_melempar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->svc->getVcbNg('mesin', '2026-01-01', '2026-12-31');
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgDashboardServiceTest`
Expected: FAIL — `Target class [App\Services\NgDashboardService] does not exist`.

- [ ] **Step 3: Implementasikan service**

Create `app/Services/NgDashboardService.php`:

```php
<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use Illuminate\Support\Carbon;

/**
 * Agregasi NG untuk dua halaman dashboard: VCB NG Proses (sisi produksi) dan
 * VCB NG Total (sisi quality).
 *
 * Satu method dengan parameter $sumber, bukan dua method kembar — yang berbeda
 * antara kedua sisi hanya nama tabel, kolom tanggal, dan kolom qty baik.
 *
 * Rumus NG Rate sama di kedua sisi supaya angkanya bisa dibandingkan langsung:
 *   NG Rate = qty_ng / (qty_baik + qty_ng) * 100
 * Produksi memakai qty_aktual sebagai qty baik — kolom itu berisi barang baik
 * saja, NG dicatat terpisah lewat tabel detail.
 */
class NgDashboardService
{
    /**
     * @return array{model: class-string, tanggal: string, qty_baik: string}
     */
    private function config(string $sumber): array
    {
        return match ($sumber) {
            'produksi' => [
                'model' => TransaksiProduksi::class,
                'tanggal' => 'tanggal_produksi',
                'qty_baik' => 'qty_aktual',
            ],
            'qc' => [
                'model' => TransaksiQc::class,
                'tanggal' => 'tgl_pengecekan',
                'qty_baik' => 'qty_ok',
            ],
            default => throw new \InvalidArgumentException(
                "Sumber NG tidak dikenal: {$sumber}. Yang sah: 'produksi' atau 'qc'."
            ),
        };
    }

    public function getVcbNg(string $sumber, string $dateFrom, string $dateTo): array
    {
        $cfg = $this->config($sumber);

        /*
         * Query lewat Eloquent, bukan DB::table, supaya global scope SoftDeletes
         * ikut berlaku — sesi yang dihapus tidak boleh terhitung.
         */
        $records = $cfg['model']::query()
            ->whereDate($cfg['tanggal'], '>=', $dateFrom)
            ->whereDate($cfg['tanggal'], '<=', $dateTo)
            ->get([$cfg['tanggal'], $cfg['qty_baik'], 'qty_ng']);

        $byMonth = [];
        $totalNg = 0;
        $totalCheck = 0;

        foreach ($records as $r) {
            $tanggal = $r->{$cfg['tanggal']};
            $key = $tanggal instanceof Carbon
                ? $tanggal->format('Y-m')
                : Carbon::parse($tanggal)->format('Y-m');

            $ng = (int) ($r->qty_ng ?? 0);
            $baik = (int) ($r->{$cfg['qty_baik']} ?? 0);

            if (! isset($byMonth[$key])) {
                $byMonth[$key] = ['total_ng' => 0, 'total_check' => 0];
            }

            $byMonth[$key]['total_ng'] += $ng;
            $byMonth[$key]['total_check'] += $baik + $ng;

            $totalNg += $ng;
            $totalCheck += $baik + $ng;
        }

        /*
         * Kunci bulan datang dari transaksi yang benar-benar ada, jadi bulan
         * tanpa transaksi memang tidak pernah muncul. Bulan dengan transaksi
         * tapi nol NG tetap muncul dengan rate 0% — itu informasi, bukan lubang.
         */
        ksort($byMonth);

        $rows = [];
        foreach ($byMonth as $key => $agg) {
            $rows[] = [
                'bulan' => Carbon::createFromFormat('Y-m', $key)->format('M Y'),
                'bulan_raw' => $key,
                'total_ng' => $agg['total_ng'],
                'total_check' => $agg['total_check'],
                'ng_rate' => $this->rate($agg['total_ng'], $agg['total_check']),
            ];
        }

        return [
            'summary' => [
                'total_ng' => $totalNg,
                'total_check' => $totalCheck,
                'ng_rate' => $this->rate($totalNg, $totalCheck),
            ],
            'by_month' => $rows,
        ];
    }

    private function rate(int $ng, int $check): float
    {
        return $check > 0 ? round($ng / $check * 100, 1) : 0.0;
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgDashboardServiceTest`
Expected: PASS (11 tests).

- [ ] **Step 5: Format**

Run: `vendor/bin/pint app tests`
Expected: `PASS` atau daftar file yang diformat.

- [ ] **Step 6: Commit**

```bash
git add app/Services/NgDashboardService.php tests/Unit/NgDashboardServiceTest.php
git commit -m "feat(ng): NgDashboardService — NG Rate per bulan

Satu method dengan parameter sumber, bukan dua method kembar: yang berbeda
antara sisi produksi dan quality hanya nama tabel, kolom tanggal, dan kolom
qty baik. Rumus NG Rate sama di kedua sisi sehingga angkanya sebanding.

Query lewat Eloquent, bukan DB::table, supaya global scope SoftDeletes ikut
berlaku — sesi yang dihapus tidak boleh terhitung. Kunci bulan datang dari
transaksi yang benar-benar ada, jadi bulan tanpa transaksi tidak muncul
sebagai batang 0% yang akan terbaca sebagai bulan sempurna."
```

---

## Task 2: `NgDashboardService::getVcbNgDetail()`

**Files:**
- Modify: `app/Services/NgDashboardService.php`
- Modify: `tests/Unit/NgDashboardServiceTest.php`

**Interfaces:**
- Consumes: `getVcbNg()` dan helper `config()` dari Task 1; `App\Models\TransaksiProduksiNg`, `App\Models\TransaksiQcNg` (masing-masing punya relasi `jenisNg()` dan `transaksi()`); `App\Services\NgDetailService::available()`.
- Produces:
  ```php
  NgDashboardService::getVcbNgDetail(string $sumber, string $bulan): array
  // $bulan: 'YYYY-MM'
  // return: [
  //   ['kode' => string, 'nama_ng' => string, 'qty' => int, 'porsi' => float],
  //   ...   // urut menurun menurut qty
  // ]
  ```

- [ ] **Step 1: Tulis test yang gagal**

Append ke `tests/Unit/NgDashboardServiceTest.php` (tambahkan juga `use App\Models\MasterJenisNg;`, `use App\Models\TransaksiProduksiNg;`, dan `use App\Models\TransaksiQcNg;` di bagian import):

```php
    public function test_detail_mengembalikan_jenis_ng_urut_qty_terbanyak(): void
    {
        $trx = $this->produksi('2026-03-10', 90, 10);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $crack->id, 'qty' => 3]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 7]);

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(2, $hasil);
        $this->assertSame('DNT', $hasil[0]['kode']);
        $this->assertSame('Dented', $hasil[0]['nama_ng']);
        $this->assertSame(7, $hasil[0]['qty']);
        $this->assertSame(70.0, $hasil[0]['porsi']);
        $this->assertSame('CRK', $hasil[1]['kode']);
        $this->assertSame(3, $hasil[1]['qty']);
        $this->assertSame(30.0, $hasil[1]['porsi']);
    }

    public function test_detail_menjumlahkan_jenis_yang_sama_dari_beberapa_sesi(): void
    {
        $a = $this->produksi('2026-03-01', 95, 5);
        $b = $this->produksi('2026-03-20', 95, 5);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $a->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $b->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(1, $hasil);
        $this->assertSame(10, $hasil[0]['qty']);
        $this->assertSame(100.0, $hasil[0]['porsi']);
    }

    public function test_detail_tidak_bocor_lintas_bulan(): void
    {
        $maret = $this->produksi('2026-03-10', 95, 5);
        $april = $this->produksi('2026-04-10', 97, 3);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $maret->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $april->id, 'id_jenis_ng' => $crack->id, 'qty' => 3]);

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertCount(1, $hasil);
        $this->assertSame('DNT', $hasil[0]['kode']);
    }

    public function test_detail_mengabaikan_sesi_ter_soft_delete(): void
    {
        $hidup = $this->produksi('2026-03-10', 95, 5);
        $mati = $this->produksi('2026-03-11', 95, 5);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);

        TransaksiProduksiNg::create(['id_transaksi_produksi' => $hidup->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $mati->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);
        $mati->delete();

        $hasil = $this->svc->getVcbNgDetail('produksi', '2026-03');

        $this->assertSame(5, $hasil[0]['qty']);
    }

    public function test_detail_sisi_qc_terpisah_dari_produksi(): void
    {
        $trx = $this->qc('2026-03-10', 90, 10);
        $burry = MasterJenisNg::create(['kode' => 'BRY', 'nama_ng' => 'Burry']);

        TransaksiQcNg::create(['id_transaksi_qc' => $trx->id, 'id_jenis_ng' => $burry->id, 'qty' => 10]);

        $this->assertSame([], $this->svc->getVcbNgDetail('produksi', '2026-03'));

        $hasil = $this->svc->getVcbNgDetail('qc', '2026-03');
        $this->assertCount(1, $hasil);
        $this->assertSame('BRY', $hasil[0]['kode']);
        $this->assertSame(10, $hasil[0]['qty']);
    }

    public function test_detail_bulan_tanpa_ng_mengembalikan_array_kosong(): void
    {
        $this->produksi('2026-03-10', 100, 0);

        $this->assertSame([], $this->svc->getVcbNgDetail('produksi', '2026-03'));
    }

    public function test_detail_sumber_tidak_dikenal_melempar(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->svc->getVcbNgDetail('mesin', '2026-03');
    }

    public function test_detail_mengembalikan_kosong_saat_tabel_detail_belum_ada(): void
    {
        $trx = $this->produksi('2026-03-10', 95, 5);
        $dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dented->id, 'qty' => 5]);

        // Meniru jendela antara rsync dan artisan migrate: kode sudah live,
        // tabelnya belum ada. Halaman harus tampil kosong, bukan meledak.
        Schema::drop('transaksi_produksi_ng');
        Schema::drop('transaksi_qc_ng');

        $this->assertSame([], $this->svc->getVcbNgDetail('produksi', '2026-03'));
    }
```

Tambahkan juga import `use Illuminate\Support\Facades\Schema;` di bagian atas berkas test.

Catatan: `NgDetailService::available()` memeriksa **kedua** tabel detail, jadi keduanya harus di-drop agar guard-nya benar-benar menyala. Men-drop satu saja membuat test ini lulus karena alasan yang salah — query akan meledak, bukan guard yang menahan.

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=NgDashboardServiceTest`
Expected: FAIL — `Call to undefined method App\Services\NgDashboardService::getVcbNgDetail()`.

- [ ] **Step 3: Tambahkan konfigurasi tabel detail**

Modify `app/Services/NgDashboardService.php` — pada method `config()`, tambahkan dua kunci di setiap cabang sehingga menjadi:

```php
            'produksi' => [
                'model' => TransaksiProduksi::class,
                'tanggal' => 'tanggal_produksi',
                'qty_baik' => 'qty_aktual',
                'detail' => TransaksiProduksiNg::class,
                'detail_fk' => 'id_transaksi_produksi',
            ],
            'qc' => [
                'model' => TransaksiQc::class,
                'tanggal' => 'tgl_pengecekan',
                'qty_baik' => 'qty_ok',
                'detail' => TransaksiQcNg::class,
                'detail_fk' => 'id_transaksi_qc',
            ],
```

Tambahkan import:

```php
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQcNg;
```

- [ ] **Step 4: Implementasikan `getVcbNgDetail()`**

Modify `app/Services/NgDashboardService.php` — tambahkan method berikut setelah `getVcbNg()`:

```php
    /**
     * Rincian jenis NG untuk satu bulan, urut qty terbanyak.
     *
     * @param  string  $bulan  format 'YYYY-MM'
     */
    public function getVcbNgDetail(string $sumber, string $bulan): array
    {
        $cfg = $this->config($sumber);

        /*
         * Tabel detail bisa belum ada di jendela antara rsync dan migrate.
         * Halaman harus tampil dengan keadaan kosong, bukan 500.
         */
        if (! NgDetailService::available()) {
            return [];
        }

        [$tahun, $bln] = array_map('intval', explode('-', $bulan));

        /*
         * whereHas menerapkan global scope model induk, jadi sesi ter-soft-delete
         * otomatis tidak ikut.
         */
        $rows = $cfg['detail']::query()
            ->with('jenisNg')
            ->whereHas('transaksi', fn ($q) => $q
                ->whereYear($cfg['tanggal'], $tahun)
                ->whereMonth($cfg['tanggal'], $bln))
            ->get();

        $byJenis = [];
        $total = 0;

        foreach ($rows as $row) {
            $id = (int) $row->id_jenis_ng;
            $qty = (int) $row->qty;

            if (! isset($byJenis[$id])) {
                $byJenis[$id] = [
                    'kode' => $row->jenisNg?->kode ?? '?',
                    'nama_ng' => $row->jenisNg?->nama_ng ?? 'Jenis NG terhapus',
                    'qty' => 0,
                ];
            }

            $byJenis[$id]['qty'] += $qty;
            $total += $qty;
        }

        $hasil = array_values($byJenis);
        usort($hasil, fn ($a, $b) => $b['qty'] <=> $a['qty']);

        foreach ($hasil as $i => $row) {
            $hasil[$i]['porsi'] = $total > 0 ? round($row['qty'] / $total * 100, 1) : 0.0;
        }

        return $hasil;
    }
```

Tambahkan import `use App\Services\NgDetailService;` — atau, karena berada di namespace yang sama (`App\Services`), rujuk langsung sebagai `NgDetailService::available()` tanpa import. Pilih yang kedua; jangan menambahkan import untuk kelas dalam namespace yang sama.

- [ ] **Step 5: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=NgDashboardServiceTest`
Expected: PASS (19 tests).

- [ ] **Step 6: Format & commit**

```bash
vendor/bin/pint app tests
git add app/Services/NgDashboardService.php tests/Unit/NgDashboardServiceTest.php
git commit -m "feat(ng): rincian jenis NG per bulan

whereHas menerapkan global scope model induk, jadi sesi ter-soft-delete
otomatis tidak ikut terhitung tanpa filter tambahan.

Pembacaan tabel detail dijaga NgDetailService::available(): di jendela antara
rsync dan artisan migrate tabelnya bisa belum ada, dan halaman harus tampil
kosong, bukan 500."
```

---

## Task 3: Route + dua controller

**Files:**
- Create: `app/Http/Controllers/Vcb/VcbNgProsesController.php`
- Create: `app/Http/Controllers/Vcb/VcbNgTotalController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Vcb/VcbNgDashboardTest.php`

**Interfaces:**
- Consumes: `NgDashboardService::getVcbNg()` dan `getVcbNgDetail()` dari Task 1–2.
- Produces: enam route bernama —
  `vcb.ng-proses`, `vcb.ng-proses.data`, `vcb.ng-proses.detail`,
  `vcb.ng-total`, `vcb.ng-total.data`, `vcb.ng-total.detail`.
  View `vcb.ng` menerima variabel `$judul`, `$sumber`, `$routeData`, `$routeDetail`, `$catatanKosong`.

- [ ] **Step 1: Tulis test yang gagal**

Create `tests/Feature/Vcb/VcbNgDashboardTest.php`:

```php
<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VcbNgDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_bisa_membuka_kedua_halaman(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.ng-proses'))->assertOk();
        $this->actingAs($admin)->get(route('vcb.ng-total'))->assertOk();
    }

    public function test_guest_bisa_membuka_kedua_halaman(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->get(route('vcb.ng-proses'))->assertOk();
        $this->actingAs($guest)->get(route('vcb.ng-total'))->assertOk();
    }

    public function test_staff_qc_tidak_bisa_membuka_ng_proses(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($qc)->get(route('vcb.ng-proses'))->assertForbidden();
        $this->actingAs($qc)->get(route('vcb.ng-total'))->assertOk();
    }

    public function test_staff_produksi_tidak_bisa_membuka_ng_total(): void
    {
        $produksi = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($produksi)->get(route('vcb.ng-total'))->assertForbidden();
        $this->actingAs($produksi)->get(route('vcb.ng-proses'))->assertOk();
    }

    public function test_belum_login_dialihkan(): void
    {
        $this->get(route('vcb.ng-proses'))->assertRedirect(route('login'));
        $this->get(route('vcb.ng-total'))->assertRedirect(route('login'));
    }

    public function test_endpoint_data_mengembalikan_bentuk_yang_diharapkan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('vcb.ng-proses.data', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))
            ->assertOk()
            ->assertJsonStructure([
                'summary' => ['total_ng', 'total_check', 'ng_rate'],
                'by_month',
            ]);
    }

    public function test_endpoint_detail_menolak_format_bulan_yang_salah(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('vcb.ng-proses.detail', ['bulan' => 'Maret']))
            ->assertStatus(422);
    }

    public function test_endpoint_detail_mengembalikan_array(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('vcb.ng-proses.detail', ['bulan' => '2026-03']))
            ->assertOk()
            ->assertJson([]);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=VcbNgDashboardTest`
Expected: FAIL — `Route [vcb.ng-proses] not defined`.

- [ ] **Step 3: Buat controller NG Proses**

Create `app/Http/Controllers/Vcb/VcbNgProsesController.php`:

```php
<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Services\NgDashboardService;
use Illuminate\Http\Request;

class VcbNgProsesController extends Controller
{
    public function __construct(private NgDashboardService $svc) {}

    public function index()
    {
        return view('vcb.ng', [
            'judul' => 'VCB NG Proses',
            'sumber' => 'produksi',
            'routeData' => route('vcb.ng-proses.data'),
            'routeDetail' => route('vcb.ng-proses.detail'),
            'catatanKosong' => 'Belum ada data NG pada rentang ini.',
        ]);
    }

    public function data(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        return response()->json($this->svc->getVcbNg(
            'produksi',
            $request->input('date_from'),
            $request->input('date_to'),
        ));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return response()->json($this->svc->getVcbNgDetail('produksi', $request->input('bulan')));
    }
}
```

- [ ] **Step 4: Buat controller NG Total**

Create `app/Http/Controllers/Vcb/VcbNgTotalController.php`:

```php
<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Services\NgDashboardService;
use Illuminate\Http\Request;

class VcbNgTotalController extends Controller
{
    public function __construct(private NgDashboardService $svc) {}

    public function index()
    {
        return view('vcb.ng', [
            'judul' => 'VCB NG Total',
            'sumber' => 'qc',
            'routeData' => route('vcb.ng-total.data'),
            'routeDetail' => route('vcb.ng-total.detail'),
            'catatanKosong' => 'Belum ada data NG pada rentang ini. '
                .'NG dari sisi Quality memang belum pernah tercatat sejak sistem berjalan — '
                .'halaman ini akan terisi begitu inspector mulai mencatat NG di LHP Quality.',
        ]);
    }

    public function data(Request $request)
    {
        $request->validate([
            'date_from' => 'required|date',
            'date_to' => 'required|date|after_or_equal:date_from',
        ]);

        return response()->json($this->svc->getVcbNg(
            'qc',
            $request->input('date_from'),
            $request->input('date_to'),
        ));
    }

    public function detail(Request $request)
    {
        $request->validate([
            'bulan' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return response()->json($this->svc->getVcbNgDetail('qc', $request->input('bulan')));
    }
}
```

- [ ] **Step 5: Daftarkan route**

Modify `routes/web.php`.

Tambahkan import di bagian atas berkas, sejajar dengan import controller VCB lain:

```php
use App\Http\Controllers\Vcb\VcbNgProsesController;
use App\Http\Controllers\Vcb\VcbNgTotalController;
```

Pada grup yang sudah ada `Route::middleware('role:admin,staff_produksi,manager,guest')->prefix('vcb')->name('vcb.')`, tambahkan setelah baris `dandori/data`:

```php
        Route::get('ng-proses', [VcbNgProsesController::class, 'index'])->name('ng-proses');
        Route::get('ng-proses/data', [VcbNgProsesController::class, 'data'])->name('ng-proses.data');
        Route::get('ng-proses/detail', [VcbNgProsesController::class, 'detail'])->name('ng-proses.detail');
```

Pada grup yang sudah ada `Route::middleware('role:admin,staff_qc,manager,guest')->prefix('vcb')->name('vcb.')`, tambahkan setelah baris `quality/operator-data`:

```php
        Route::get('ng-total', [VcbNgTotalController::class, 'index'])->name('ng-total');
        Route::get('ng-total/data', [VcbNgTotalController::class, 'data'])->name('ng-total.data');
        Route::get('ng-total/detail', [VcbNgTotalController::class, 'detail'])->name('ng-total.detail');
```

Jangan membuat grup baru — keenam route harus masuk ke dua grup role yang sudah ada.

- [ ] **Step 6: Buat view sementara agar route bisa dites**

Create `resources/views/vcb/ng.blade.php` dengan isi sementara berikut. Task 4 menggantinya seluruhnya; ini hanya supaya test route pada task ini bisa berjalan.

```blade
@extends('layouts.app')
@section('title', $judul)
@section('content')
    <h1>{{ $judul }}</h1>
@endsection
```

- [ ] **Step 7: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=VcbNgDashboardTest`
Expected: PASS (8 tests).

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Vcb/VcbNgProsesController.php app/Http/Controllers/Vcb/VcbNgTotalController.php routes/web.php resources/views/vcb/ng.blade.php tests/Feature/Vcb/VcbNgDashboardTest.php
git commit -m "feat(ng): route dan controller dua dashboard NG

Keenam route masuk ke dua grup role yang sudah ada, bukan grup baru — nol
permukaan akses baru untuk di-review. Semuanya read-only sehingga
DenyGuestWrite tidak terpengaruh.

Controller tipis: validasi masukan lalu teruskan ke NgDashboardService.
Perbedaan antara kedua halaman hanya nilai sumber dan teks keadaan kosong."
```

---

## Task 4: View bersama + grafik + drill-down

**Files:**
- Modify: `resources/views/vcb/ng.blade.php` (ganti seluruh isi sementara dari Task 3)

**Interfaces:**
- Consumes: variabel view `$judul`, `$sumber`, `$routeData`, `$routeDetail`, `$catatanKosong` dari Task 3; bentuk JSON dari Task 1–2.
- Produces: tidak ada yang dikonsumsi task lain.

- [ ] **Step 1: Ganti seluruh isi view**

Replace `resources/views/vcb/ng.blade.php`:

```blade
@extends('layouts.app')
@section('title', $judul)

@section('content')
@php
    $defaultTo = date('Y-m-d');
    $defaultFrom = date('Y-m-01', strtotime('-11 months'));
@endphp

<div x-data="vcbNg()" x-init="load()">

    {{-- ── Filter ───────────────────────────────── --}}
    <div class="erp-panel mb-3">
        <div class="erp-panel-body">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-1" style="font-size:0.82rem">Dari</label>
                    <input type="date" class="form-control form-control-sm" x-model="dateFrom">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1" style="font-size:0.82rem">Sampai</label>
                    <input type="date" class="form-control form-control-sm" x-model="dateTo">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-dark" @click="load()" :disabled="loading">
                        <span x-show="!loading">Tampilkan</span>
                        <span x-show="loading" x-cloak>Memuat…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Ringkasan ────────────────────────────── --}}
    <div class="row g-2 mb-3" x-show="hasData" x-cloak>
        <div class="col-6 col-md-4">
            <div class="erp-panel h-100"><div class="erp-panel-body">
                <div class="text-muted" style="font-size:0.78rem">Total NG</div>
                <div style="font-size:1.4rem;font-weight:700" x-text="fmtInt(summary.total_ng)"></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="erp-panel h-100"><div class="erp-panel-body">
                <div class="text-muted" style="font-size:0.78rem">Total Diperiksa</div>
                <div style="font-size:1.4rem;font-weight:700" x-text="fmtInt(summary.total_check)"></div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="erp-panel h-100"><div class="erp-panel-body">
                <div class="text-muted" style="font-size:0.78rem">NG Rate</div>
                <div style="font-size:1.4rem;font-weight:700" x-text="summary.ng_rate + '%'"></div>
            </div></div>
        </div>
    </div>

    {{-- ── Grafik NG Rate per bulan ─────────────── --}}
    <div class="erp-panel mb-3">
        <div class="erp-panel-head">NG Rate per Bulan</div>
        <div class="erp-panel-body">
            <div x-show="!hasData && !loading" x-cloak class="text-muted text-center py-4">
                {{ $catatanKosong }}
            </div>
            <div x-show="hasData" x-cloak style="height:320px">
                <canvas id="ngRateChart"></canvas>
            </div>
            <div x-show="hasData" x-cloak class="text-muted mt-2" style="font-size:0.78rem">
                Klik satu batang bulan untuk melihat rincian jenis NG bulan itu.
            </div>
        </div>
    </div>

    {{-- ── Rincian bulan terpilih ───────────────── --}}
    <div class="erp-panel" x-show="bulanTerpilih" x-cloak>
        <div class="erp-panel-head" x-text="'Rincian NG — ' + labelBulanTerpilih"></div>
        <div class="erp-panel-body">
            <div x-show="detail.length === 0" x-cloak class="text-muted text-center py-3">
                Bulan ini tidak punya NG tercatat.
            </div>
            <div class="table-responsive" x-show="detail.length > 0" x-cloak>
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:100px">Kode</th>
                            <th>Jenis NG</th>
                            <th class="text-end" style="width:100px">Qty</th>
                            <th class="text-end" style="width:100px">Porsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in detail" :key="row.kode">
                            <tr>
                                <td x-text="row.kode"></td>
                                <td x-text="row.nama_ng"></td>
                                <td class="text-end" x-text="fmtInt(row.qty)"></td>
                                <td class="text-end" x-text="row.porsi + '%'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function vcbNg() {
    return {
        dateFrom: '{{ $defaultFrom }}',
        dateTo: '{{ $defaultTo }}',
        loading: false,
        hasData: false,
        summary: { total_ng: 0, total_check: 0, ng_rate: 0 },
        byMonth: [],
        bulanTerpilih: null,
        labelBulanTerpilih: '',
        detail: [],
        chart: null,

        fmtInt(v) {
            return Number(v || 0).toLocaleString('id-ID');
        },

        async load() {
            this.loading = true;
            this.bulanTerpilih = null;
            this.detail = [];

            try {
                const p = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo });
                const res = await fetch(`{{ $routeData }}?${p}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!res.ok) { this.hasData = false; return; }

                const json = await res.json();
                this.summary = json.summary;
                this.byMonth = json.by_month;
                this.hasData = this.byMonth.length > 0;

                if (this.hasData) {
                    this.$nextTick(() => this.render());
                } else if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
                }
            } finally {
                this.loading = false;
            }
        },

        render() {
            const ctx = document.getElementById('ngRateChart');
            if (!ctx) return;

            if (this.chart) this.chart.destroy();

            const self = this;

            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: this.byMonth.map(m => m.bulan),
                    datasets: [{
                        label: 'NG Rate (%)',
                        data: this.byMonth.map(m => m.ng_rate),
                        backgroundColor: '#b91c1c',
                        borderRadius: 3,
                        borderSkipped: false,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick(evt, els) {
                        if (!els.length) return;
                        self.pilihBulan(self.byMonth[els[0].index]);
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: v => v + '%' },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label(c) {
                                    const m = self.byMonth[c.dataIndex];
                                    return `${m.ng_rate}%  —  ${self.fmtInt(m.total_ng)} NG dari ${self.fmtInt(m.total_check)}`;
                                },
                            },
                        },
                    },
                },
            });
        },

        async pilihBulan(m) {
            this.bulanTerpilih = m.bulan_raw;
            this.labelBulanTerpilih = m.bulan;
            this.detail = [];

            const p = new URLSearchParams({ bulan: m.bulan_raw });
            const res = await fetch(`{{ $routeDetail }}?${p}`, {
                headers: { 'Accept': 'application/json' },
            });

            if (res.ok) this.detail = await res.json();
        },
    };
}
</script>
@endpush
```

- [ ] **Step 2: Verifikasi Blade terkompilasi**

Run: `php artisan view:cache && php artisan view:clear`
Expected: `INFO  Blade templates cached successfully.` lalu `INFO  Compiled views cleared successfully.` — tanpa exception.

- [ ] **Step 3: Jalankan test route agar tidak ada yang rusak**

Run: `php artisan test --filter=VcbNgDashboardTest`
Expected: PASS (8 tests).

- [ ] **Step 4: Commit**

```bash
git add resources/views/vcb/ng.blade.php
git commit -m "feat(ng): tampilan dashboard NG — grafik bulanan + drill-down

Satu view dipakai kedua halaman lewat parameter; dua file identik akan jadi
utang, bukan kejelasan.

Keadaan kosong diperlakukan sebagai isi, bukan hiasan: rentang tanpa data
menampilkan kalimat yang menjelaskan, bukan kanvas kosong yang terbaca
sebagai kerusakan. Halaman NG Total memakai kalimat yang lebih spesifik
karena NG sisi Quality memang belum pernah tercatat sama sekali."
```

---

## Task 5: Entri sidebar

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Test: `tests/Feature/Vcb/VcbNgDashboardTest.php`

**Interfaces:**
- Consumes: route bernama dari Task 3.
- Produces: tidak ada.

- [ ] **Step 1: Tulis test yang gagal**

Append ke `tests/Feature/Vcb/VcbNgDashboardTest.php`:

```php
    public function test_sidebar_memuat_entri_ng_proses_untuk_staff_produksi(): void
    {
        $produksi = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($produksi)
            ->get(route('vcb.produksi'))
            ->assertOk()
            ->assertSee('VCB NG Proses')
            ->assertDontSee('VCB NG Total');
    }

    public function test_sidebar_memuat_entri_ng_total_untuk_staff_qc(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($qc)
            ->get(route('vcb.quality'))
            ->assertOk()
            ->assertSee('VCB NG Total')
            ->assertDontSee('VCB NG Proses');
    }

    public function test_admin_melihat_kedua_entri_sidebar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('vcb.produksi'))
            ->assertOk()
            ->assertSee('VCB NG Proses')
            ->assertSee('VCB NG Total');
    }
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

Run: `php artisan test --filter=VcbNgDashboardTest`
Expected: FAIL — tiga test baru gagal karena teks `VCB NG Proses` / `VCB NG Total` belum ada di HTML.

- [ ] **Step 3: Tambahkan entri di grup Produksi**

Modify `resources/views/layouts/app.blade.php` — di dalam `<div class="nav-group-body" x-show="isOpen('produksi')" x-cloak>`, setelah tautan `vcb.dandori`, tambahkan:

```blade
            <a href="{{ route('vcb.ng-proses') }}" class="nav-item-link {{ str_starts_with($uri,'vcb/ng-proses')?'active':'' }}">
                <i class="bi bi-exclamation-triangle"></i><span class="nav-text">VCB NG Proses</span>
            </a>
```

- [ ] **Step 4: Tambahkan entri di grup Quality**

Modify `resources/views/layouts/app.blade.php` — di dalam `<div class="nav-group-body" x-show="isOpen('quality')" x-cloak>`, setelah tautan `vcb.quality`, tambahkan:

```blade
            <a href="{{ route('vcb.ng-total') }}" class="nav-item-link {{ str_starts_with($uri,'vcb/ng-total')?'active':'' }}">
                <i class="bi bi-exclamation-triangle"></i><span class="nav-text">VCB NG Total</span>
            </a>
```

- [ ] **Step 5: Perbarui penanda grup aktif**

Modify `resources/views/layouts/app.blade.php` — pada blok `@php $vcbActive = match(true) { ... } @endphp` di atas nav. Tanpa langkah ini, membuka halaman NG akan menutup grup accordion-nya sendiri.

Ganti empat baris pertama blok match itu:

```php
            str_starts_with($uri, 'vcb/produksi'),
            str_starts_with($uri, 'vcb/mesin'),
            str_starts_with($uri, 'vcb/dandori')          => 'produksi',
            str_starts_with($uri, 'vcb/quality')           => 'quality',
```

menjadi:

```php
            str_starts_with($uri, 'vcb/produksi'),
            str_starts_with($uri, 'vcb/mesin'),
            str_starts_with($uri, 'vcb/ng-proses'),
            str_starts_with($uri, 'vcb/dandori')          => 'produksi',
            str_starts_with($uri, 'vcb/quality'),
            str_starts_with($uri, 'vcb/ng-total')          => 'quality',
```

Perhatikan `vcb/ng-proses` harus berada di cabang `'produksi'` dan `vcb/ng-total` di cabang `'quality'` — bukan sebaliknya. Keduanya berawalan `vcb/ng`, jadi tertukar tidak akan menimbulkan error, hanya membuka grup yang salah.

- [ ] **Step 6: Jalankan test, pastikan lulus**

Run: `php artisan test --filter=VcbNgDashboardTest`
Expected: PASS (11 tests).

- [ ] **Step 7: Verifikasi Blade & commit**

```bash
php artisan view:cache && php artisan view:clear
git add resources/views/layouts/app.blade.php tests/Feature/Vcb/VcbNgDashboardTest.php
git commit -m "feat(ng): entri sidebar VCB NG Proses & VCB NG Total

Kondisi role mengikuti grup masing-masing, jadi staff_produksi tidak melihat
entri Quality dan sebaliknya. Test mengunci itu — tanpanya, entri yang bocor
ke role yang salah hanya ketahuan kalau ada yang melapor."
```

---

## Task 6: Verifikasi menyeluruh + PR

**Files:** tidak ada file baru. Task ini gerbang sebelum PR.

**Interfaces:**
- Consumes: seluruh output Task 1–5.
- Produces: bukti suite hijau dan nol migrasi tak sengaja.

- [ ] **Step 1: Jalankan seluruh suite**

Run: `php artisan test`
Expected: PASS, nol failure, nol error. Jumlah test bertambah 30 dari basis 519 (19 unit + 11 feature). Angka pastinya boleh berbeda; yang wajib adalah nol kegagalan.

- [ ] **Step 2: Pastikan dashboard lama tidak rusak**

Run: `php artisan test --filter="DashboardServiceTest|OeeServiceTest|AuthorizationTest"`
Expected: PASS. `AuthorizationTest` penting: ia menurunkan daftar route yang bisa dijangkau guest dari route table hidup, jadi enam route baru akan membuatnya gagal bila daftar putihnya tidak ikut diperbarui. Bila gagal, perbarui daftar route di test itu — jangan melemahkan assertion-nya.

- [ ] **Step 3: Pastikan nol migrasi baru ikut terbawa**

Run: `git diff --stat main..HEAD -- database/migrations .github/workflows/deploy.yml`
Expected: keluaran kosong. Bila ada isinya, sesuatu di luar rencana ikut masuk — periksa sebelum lanjut.

- [ ] **Step 4: Gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: `✓ Cakupan migrasi lengkap: 51 file — 11 dijalankan saat deploy, 40 baseline.`

- [ ] **Step 5: Format**

Run: `vendor/bin/pint app tests --test`
Expected: `PASS`.

- [ ] **Step 6: Push dan buka PR**

```bash
git push -u origin feat/vcb-ng-dashboard
```

Buat PR dengan judul `feat(ng): dashboard VCB NG Proses & VCB NG Total (Tahap 2)`. Body wajib memuat:
- bahwa ini menutup permintaan asli yang memicu seluruh pekerjaan NG
- rumus NG Rate dan asumsi bahwa `qty_aktual` berisi barang baik saja
- bahwa nol migrasi ditambahkan, dengan keluaran `git diff --stat` sebagai bukti
- keadaan data saat ini: 15 baris NG di produksi, nol di QC, sehingga VCB NG Total akan lahir kosong dan itu disengaja
- dua hal dari rencana Tahap 1 yang dibatalkan (residual "Tanpa jenis" dan label peringatan historis) beserta alasannya

---

## Catatan untuk implementer

**Jangan mengubah test agar cocok dengan kode.** Bila test gagal, yang salah kodenya — kecuali ekspektasi test memang keliru, dan dalam kasus itu jelaskan alasannya di commit.

**Urutan task tidak boleh ditukar.** Task 3 butuh service dari Task 1–2. Task 4 mengganti view sementara yang dibuat Task 3. Task 5 butuh route bernama dari Task 3.

**Yang paling mudah salah:** `AuthorizationTest` menurunkan daftar route guest dari route table yang hidup. Menambah enam route baru ke grup yang menyertakan `guest` akan membuat test itu gagal sampai daftar putihnya diperbarui. Itu perilaku yang benar — gerbangnya bekerja. Perbarui daftarnya, jangan longgarkan assertion-nya.

**Kalau `php artisan test` gagal di tempat yang tidak disebut rencana ini,** hentikan dan laporkan. Jangan menambal dengan mengubah test lama.
