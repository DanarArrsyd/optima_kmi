# Filter Mesin VCB (ganti Filter LINE) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ganti filter LINE (checkbox flat) di VCB Produksi & VCB Mesin dengan filter mesin multi-select "Semua Mesin" grouped-by-line, identik LHP Produksi — dipromote jadi shared component dipakai 3 halaman.

**Architecture:** `mesinFilterComp()` Alpine component + markup grouped-chip-list dipromote dari bespoke (LHP Produksi) jadi shared (`layouts/app.blade.php` + `partials/mesin-picker.blade.php`), lalu dipasang di 3 halaman. Backend filter `DashboardService` pindah dari `whereHas('mesin', whereIn('line', ...))` ke `whereIn('id_mesin', ...)` langsung — lebih simpel, ngikutin pola `ProduksiController` yang udah ada.

**Tech Stack:** Laravel 13 (PHP 8.4 di CI/prod), Alpine.js 3.14.1, Blade partial, PHPUnit (SQLite in-memory).

## Global Constraints

- CI & prod jalan PHP 8.4 — jangan pakai sintaks yang cuma ada di PHP 8.5.
- Pint scope cuma `app/` + `tests/` — jalankan `vendor/bin/pint app tests` sebelum commit.
- **Tidak ada migrasi baru** — filter ini query tambahan atas kolom yang udah ada (`master_mesin.id`, `transaksi_produksi.id_mesin`).
- `php artisan test` jalan di SQLite in-memory.
- Nama param HTTP **konsisten** `mesin_ids[]` di ketiga halaman (LHP Produksi, VCB Produksi, VCB Mesin) — sama kayak yang udah dipakai LHP Produksi.
- `mesinFilterComp()` cuma boleh punya SATU definisi, di `layouts/app.blade.php` — dijaga `tests/Feature/Layout/KomponenBersamaTest.php`.
- Markup grouped-chip-list cuma boleh ada di satu partial (`resources/views/partials/mesin-picker.blade.php`), di-`@include` di 3 tempat — bukan disalin.
- LHP Produksi (`input/produksi/index.blade.php`) **perilakunya tidak boleh berubah** setelah refactor — param, filter, dan tampilan tetap sama seperti sebelum plan ini.

---

### Task 1: DashboardService — ganti `$lines` jadi `$mesinIds`

**Files:**
- Modify: `app/Services/DashboardService.php:15-21` (`getVcbProduksi`), `:74-80` (`getVcbProduksiByDate`), `:136-141` (`getVcbMesin`)
- Test: `tests/Unit/DashboardServiceTest.php:151-212`

**Interfaces:**
- Produces: `getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $mesinIds = []): array`, `getVcbProduksiByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanId, ?int $shiftId = null, array $mesinIds = []): array`, `getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $mesinIds = []): array` — dipakai Task 3 & 4.

- [ ] **Step 1: Write the failing tests**

Di `tests/Unit/DashboardServiceTest.php`, ganti seluruh blok 3 test line-filter (baris 151-212, dari `public function test_line_filter_narrows_produksi()` sampai `}` penutup class) dengan:

```php
    public function test_mesin_filter_narrows_produksi(): void
    {
        $b = $this->base();
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        // Karyawan sama di kedua sesi → tanpa filter jadi 1 baris gabungan (4999).
        // Dengan filter mesin id ($b['mesin']->id saja), sesi mesinB harus tersaring.
        $rows = $this->svc->getVcbProduksi(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, [$b['mesin']->id]);
        $this->assertCount(1, $rows);
        $this->assertSame(4000, $rows[0]['total_qty_aktual']);
    }

    public function test_mesin_filter_narrows_mesin(): void
    {
        $b = $this->base();
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, [$mesinB->id]);
        $this->assertCount(1, $rows);
        $this->assertSame($mesinB->id_mesin, $rows[0]['id_mesin']);
        $this->assertSame(999, $rows[0]['total_qty_aktual']);
    }

    public function test_mesin_filter_accepts_multiple_values(): void
    {
        $b = $this->base();
        $this->produksi($b, '2026-07-01', '08:00', '16:00', 4000);

        $mesinB = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 2', 'line' => '2', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 999, 'created_by' => $b['user']->id,
        ]);

        // Mesin ketiga TIDAK diikutkan di filter — kalau filter benar-benar
        // tercabut, count jadi 3 (bukan 2) dan mesin ini bakal ikut muncul.
        $mesinC = MasterMesin::create(['id_mesin' => 'M-'.uniqid(), 'nama_mesin' => 'Press 3', 'line' => '3', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $b['shift']->id, 'id_karyawan' => $b['karyawan']->id,
            'id_mesin' => $mesinC->id, 'id_part_proses' => $b['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 111, 'created_by' => $b['user']->id,
        ]);

        $rows = $this->svc->getVcbMesin(Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'), null, [$b['mesin']->id, $mesinB->id]);
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing([$b['mesin']->id_mesin, $mesinB->id_mesin], array_column($rows, 'id_mesin'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=DashboardServiceTest`
Expected: FAIL — `test_mesin_filter_narrows_produksi` dapat 1 baris tapi `total_qty_aktual` 4999 (filter diabaikan, param ke-4 belum dibaca sebagai id). `test_mesin_filter_narrows_mesin` dapat count 2 bukan 1. `test_mesin_filter_accepts_multiple_values` dapat count 3 bukan 2.

- [ ] **Step 3: Implement — ganti `$lines` jadi `$mesinIds`, filter langsung `id_mesin`**

Di `app/Services/DashboardService.php`, `getVcbProduksi`:

```php
    public function getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $mesinIds = []): array
    {
        $records = TransaksiProduksi::with(['karyawan', 'partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($mesinIds), fn ($q) => $q->whereIn('id_mesin', $mesinIds))
            ->get();
```

`getVcbProduksiByDate`:

```php
    public function getVcbProduksiByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanId, ?int $shiftId = null, array $mesinIds = []): array
    {
        $records = TransaksiProduksi::with(['partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('id_karyawan', $karyawanId)
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($mesinIds), fn ($q) => $q->whereIn('id_mesin', $mesinIds))
            ->orderBy('tanggal_produksi')
            ->orderBy('jam_start')
            ->get();
```

`getVcbMesin`:

```php
    public function getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $mesinIds = []): array
    {
        $records = TransaksiProduksi::with(['mesin', 'partProses'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($mesinIds), fn ($q) => $q->whereIn('id_mesin', $mesinIds))
            ->get();
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=DashboardServiceTest`
Expected: PASS (semua test di file, termasuk `test_shift_filter_narrows_produksi` yang lama — regression check).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app/Services/DashboardService.php tests/Unit/DashboardServiceTest.php
git add app/Services/DashboardService.php tests/Unit/DashboardServiceTest.php
git commit -m "refactor(vcb): DashboardService filter mesin by id, bukan line

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: Promote `mesinFilterComp` jadi shared component + refactor LHP Produksi

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1702-1703` (sisip fungsi baru setelah `filterSearchComp`)
- Create: `resources/views/partials/mesin-picker.blade.php`
- Modify: `resources/views/input/produksi/index.blade.php:160` (x-data), `:197-215` (markup → `@include`), `:785-801` (hapus definisi lokal)
- Modify: `tests/Feature/Layout/KomponenBersamaTest.php:16-28` (tambah entri)
- Test: Create `tests/Feature/Input/ProduksiMesinFilterTest.php`

**Interfaces:**
- Produces: shared JS `mesinFilterComp(mesinList, initialSelected = [])` di `layouts/app.blade.php` — dipakai Task 3 & 4. Signature: `mesinList` array of `{id, code, name, line}`, `initialSelected` array of `id` (integer).
- Produces: shared Blade partial `partials/mesin-picker.blade.php`, dipanggil `@include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])` — nerima `$mesinByLine` (Collection hasil `groupBy('line')` dari Collection `MasterMesin`), harus dipanggil di dalam elemen ber-`x-data="mesinFilterComp(...)"`.

- [ ] **Step 1: Write the failing test**

Buat file baru `tests/Feature/Input/ProduksiMesinFilterTest.php`:

```php
<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter mesin multi-select ("Semua Mesin" grouped by line) di LHP Produksi.
 * mesinFilterComp() dipromote jadi shared component (dipakai juga di VCB) —
 * test ini bukti nol regresi perilaku lama LHP Produksi setelah refactor.
 */
class ProduksiMesinFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    private function duaMesin(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500]);
        $mesinA = MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mesinB = MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR', 'departemen' => 'Produksi', 'is_aktif' => true]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesinA->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 100, 'created_by' => $this->admin->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-09-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 200, 'created_by' => $this->admin->id,
        ]);

        return compact('mesinA', 'mesinB');
    }

    public function test_filter_mesin_ids_menyaring_tabel_lhp_produksi(): void
    {
        $m = $this->duaMesin();

        $html = $this->get(route('input.produksi.index', ['mesin_ids' => [$m['mesinA']->id]]))
            ->assertOk()->getContent();

        $this->assertStringContainsString($m['mesinA']->id_mesin, $html);
        $this->assertStringNotContainsString($m['mesinB']->id_mesin, $html);
    }

    public function test_tanpa_filter_mesin_ids_menampilkan_semua(): void
    {
        $m = $this->duaMesin();

        $html = $this->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringContainsString($m['mesinA']->id_mesin, $html);
        $this->assertStringContainsString($m['mesinB']->id_mesin, $html);
    }

    public function test_halaman_memanggil_komponen_shared_dengan_data_awal(): void
    {
        $m = $this->duaMesin();

        $html = $this->get(route('input.produksi.index', ['mesin_ids' => [$m['mesinA']->id]]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('mesinFilterComp(_mesinForJs, _mesinSelected)', $html);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProduksiMesinFilterTest`
Expected: `test_filter_mesin_ids_menyaring_tabel_lhp_produksi` dan `test_tanpa_filter_mesin_ids_menampilkan_semua` PASS (perilaku LHP Produksi udah bener dari awal, belum disentuh). `test_halaman_memanggil_komponen_shared_dengan_data_awal` FAIL — halaman masih manggil `mesinFilterComp()` tanpa argumen (baris 160 belum diubah).

- [ ] **Step 3: Implement — pindah fungsi ke layout, generalisasi, bikin partial, refactor LHP Produksi**

**3a.** Di `resources/views/layouts/app.blade.php`, sisipkan fungsi baru persis setelah penutup `filterSearchComp` (baris 1702, sebelum `function partGroupComp`):

```js
function filterSearchComp(items, initVal, initLabel, onPick) {
    return {
        items,
        query: initLabel || '',
        val:   (initVal !== undefined && initVal !== null && initVal !== 0 && initVal !== '0') ? initVal : '',
        open:  false,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 40);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        pick(item) {
            this.val = item.v; this.query = item.t; this.open = false;
            if (typeof onPick === 'function') onPick(item);
        },
        clear()   { this.val = ''; this.query = ''; },
        onInput() { this.val = ''; this.open = true; },
    };
}

/* mesinFilterComp — multi-select mesin grouped by line ("Semua Mesin").
   Satu definisi, dipakai LHP Produksi + VCB Produksi (2 instance: Mode 1 &
   Mode 2) + VCB Mesin. Sebelumnya bespoke di LHP Produksi, baca const
   global _mesinList/_mesinSelected — sekarang nerima data lewat parameter
   supaya bisa dipasang lebih dari sekali di satu halaman (VCB Produksi). */
function mesinFilterComp(mesinList, initialSelected = []) {
    return {
        mesinOpen: false,
        selected:  [...initialSelected],
        mesinList,
        get labelSingle() {
            if (this.selected.length !== 1) return '';
            const m = this.mesinList.find(m => m.id == this.selected[0]);
            return m ? m.code : '1 mesin';
        },
        toggle(id)     { const i = this.selected.indexOf(id); i === -1 ? this.selected.push(id) : this.selected.splice(i, 1); },
        isSelected(id) { return this.selected.includes(id); },
        clearAll()     { this.selected = []; },
    };
}
```

**3b.** Buat `resources/views/partials/mesin-picker.blade.php`:

```blade
{{-- Dropdown "Semua Mesin" multi-select, grouped by LINE.
     WAJIB dipanggil di dalam elemen ber-x-data="mesinFilterComp(...)" —
     partial ini nggak buka scope Alpine sendiri.
     Nerima: $mesinByLine (Collection hasil groupBy('line') dari MasterMesin). --}}
<template x-for="id in selected" :key="id">
    <input type="hidden" name="mesin_ids[]" :value="id">
</template>

<button type="button" @click="mesinOpen = !mesinOpen" class="erp-in is-mesin"
        :class="selected.length > 0 ? 'is-set' : ''"
        style="text-align:left;cursor:pointer;display:flex;align-items:center;gap:5px">
    <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
        <span x-show="selected.length === 0">Semua Mesin</span>
        <span x-show="selected.length === 1" x-text="labelSingle"></span>
        <span x-show="selected.length > 1"><span x-text="selected.length"></span> mesin</span>
    </span>
    <i class="bi" :class="mesinOpen ? 'bi-chevron-up' : 'bi-chevron-down'" style="font-size:.6rem;opacity:.5"></i>
</button>

<div x-show="mesinOpen" x-cloak class="erp-mesin-pick">
    @foreach($mesinByLine as $line => $mesinGroup)
    <div class="erp-mesin-line">Line {{ $line }}</div>
    <div class="erp-mesin-chips">
        @foreach($mesinGroup as $m)
        <button type="button" @click="toggle({{ $m->id }})"
                class="erp-chip" :class="isSelected({{ $m->id }}) ? 'is-on' : ''"
                :aria-pressed="isSelected({{ $m->id }}) ? 'true' : 'false'"
                title="{{ $m->id_mesin }} &mdash; {{ $m->nama_mesin }}">
            <span class="kode">{{ $m->id_mesin }}</span>
            <span class="nama">{{ $m->nama_mesin }}</span>
            <i class="bi bi-check-lg tik" aria-hidden="true"></i>
        </button>
        @endforeach
    </div>
    @endforeach
    <button type="button" x-show="selected.length > 0" @click="clearAll()"
            class="erp-mesin-clear">&times; Hapus semua pilihan</button>
</div>
```

**3c.** Di `resources/views/input/produksi/index.blade.php`:

- Baris 160, ganti:
  ```blade
  <form method="GET" action="{{ route('input.produksi.index') }}" id="filterForm"
        x-data="mesinFilterComp()" class="erp-dock-body">
  ```
  jadi:
  ```blade
  <form method="GET" action="{{ route('input.produksi.index') }}" id="filterForm"
        x-data="mesinFilterComp(_mesinForJs, _mesinSelected)" class="erp-dock-body">
  ```

- Baris 162-164 (template hidden-input) **dihapus** — sekarang bagian dari partial.

- Baris 184-215 (tombol trigger + `<div x-show="mesinOpen"...>` grouped list), ganti seluruhnya jadi:
  ```blade
  @include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])
  ```

- Baris 785-801 (definisi lokal `mesinFilterComp`) **dihapus total** — termasuk komentar `/* ── Filter Mesin multi-select ── */` di atasnya. Baris `const _mesinList = @json($_mesinForJs);` dan `const _mesinSelected = @json(array_values($mesinIds));` **tetap ada** — itu data awal (dari `$mesinIds` hasil query string saat page-load), diteruskan sebagai argumen ke `mesinFilterComp()` di baris 160.

**3d.** Di `tests/Feature/Layout/KomponenBersamaTest.php`, tambah satu baris ke const `KOMPONEN` (baris 16-28), setelah `'function saringOrangNonaktif(',`:

```php
        'function saringOrangNonaktif(',
        'function mesinFilterComp(',
        'function partPicker(',
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=ProduksiMesinFilterTest`
Expected: PASS (3/3).

Run: `php artisan test --filter=KomponenBersamaTest`
Expected: PASS — termasuk `test_hanya_satu_definisi_dan_ada_di_layout` dengan data set `'function mesinFilterComp('`, membuktikan definisi lama di `input/produksi/index.blade.php` udah kehapus total (test ini gagal kalau masih ada salinan).

Run full regression: `php artisan test`
Expected: PASS — cek khusus nggak ada test lain yang nyari string `function mesinFilterComp()` tanpa argumen atau markup lama di `input/produksi/index.blade.php`.

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/Input/ProduksiMesinFilterTest.php tests/Feature/Layout/KomponenBersamaTest.php
git add resources/views/layouts/app.blade.php resources/views/partials/mesin-picker.blade.php resources/views/input/produksi/index.blade.php tests/Feature/Layout/KomponenBersamaTest.php tests/Feature/Input/ProduksiMesinFilterTest.php
git commit -m "refactor(mesin-filter): promote mesinFilterComp jadi shared component

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: VCB Mesin — pasang filter mesin shared

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbMesinController.php:16-31`
- Modify: `resources/views/vcb/mesin.blade.php:1-65` (dock), `:124-130` (JS `loadData()`)
- Delete: `tests/Feature/Vcb/VcbLineFilterTest.php`
- Create: `tests/Feature/Vcb/VcbMesinFilterTest.php`

**Interfaces:**
- Consumes: `DashboardService::getVcbMesin(..., array $mesinIds = [])` (Task 1), shared `mesinFilterComp(mesinList, initialSelected)` + `partials/mesin-picker.blade.php` (Task 2).
- Produces: `VcbMesinController::index()` kirim `$mesin` (flat `Collection<MasterMesin>`, bukan `$lineList` lagi) — dikonsumsi di Blade jadi `$mesinByLine`/`$_mesinForJs`.

- [ ] **Step 1: Write the failing tests**

Hapus file `tests/Feature/Vcb/VcbLineFilterTest.php` (isinya dipecah & ditulis ulang — bagian VCB Mesin di sini, bagian VCB Produksi di Task 4).

Buat file baru `tests/Feature/Vcb/VcbMesinFilterTest.php`:

```php
<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filter mesin multi-select ("Semua Mesin" grouped by line) di VCB Produksi
 * (Mode 1 & 2) dan VCB Mesin — ganti filter LINE lama (PR #177). Nol migrasi;
 * `transaksi_produksi.id_mesin` sudah ada sejak awal.
 */
class VcbMesinFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($this->admin);
    }

    /**
     * Dua operator, masing-masing di mesin beda, tanggal sama —
     * dipakai lintas endpoint (Mode 1, Mode 2, VCB Mesin).
     */
    private function duaMesin(): array
    {
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $part = MasterPart::create(['part_no' => 'P-'.uniqid(), 'part_name' => 'Part']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500]);

        $mesinA = MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mesinB = MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $opA = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $opB = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR B', 'departemen' => 'Produksi', 'is_aktif' => true]);

        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $opA->id,
            'id_mesin' => $mesinA->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 4000, 'created_by' => $this->admin->id,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $shift->id, 'id_karyawan' => $opB->id,
            'id_mesin' => $mesinB->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 2000, 'created_by' => $this->admin->id,
        ]);

        return compact('shift', 'proses', 'opA', 'opB', 'mesinA', 'mesinB');
    }

    public function test_vcb_mesin_data_disaring_oleh_mesin_id(): void
    {
        $m = $this->duaMesin();

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'mesin_ids' => [$m['mesinB']->id],
        ]))->json();

        $this->assertSame(['MESIN B'], $json['labels']);
    }

    public function test_vcb_mesin_data_menerima_multi_mesin(): void
    {
        $m = $this->duaMesin();

        // Mesin ketiga TIDAK diikutkan di filter — kalau filter benar-benar
        // tercabut, labels bakal 3 baris dan ikut memuat MESIN C.
        $mesinC = MasterMesin::create(['id_mesin' => 'C-'.uniqid(), 'nama_mesin' => 'MESIN C', 'line' => 'C', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $opC = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'OPERATOR C', 'departemen' => 'Produksi', 'is_aktif' => true]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20', 'id_shift' => $m['shift']->id, 'id_karyawan' => $opC->id,
            'id_mesin' => $mesinC->id, 'id_part_proses' => $m['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 500, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.mesin.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
            'mesin_ids' => [$m['mesinA']->id, $m['mesinB']->id],
        ]))->json();

        $this->assertCount(2, $json['labels']);
        $this->assertEqualsCanonicalizing(['MESIN A', 'MESIN B'], $json['labels']);
    }

    public function test_vcb_mesin_menampilkan_dropdown_mesin_grouped_by_line(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        MasterMesin::create(['id_mesin' => 'B-'.uniqid(), 'nama_mesin' => 'MESIN B', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.mesin'))->assertOk()->getContent();

        $this->assertStringContainsString('mesinFilterComp(_mesinForJs, [])', $html);
        $this->assertStringContainsString('Line A', $html);
        $this->assertStringContainsString('Line B', $html);
        $this->assertStringContainsString('Semua Mesin', $html);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VcbMesinFilterTest`
Expected: `test_vcb_mesin_data_disaring_oleh_mesin_id` FAIL (dapat 2 label, controller belum baca `mesin_ids`). `test_vcb_mesin_data_menerima_multi_mesin` FAIL (dapat 3 label). `test_vcb_mesin_menampilkan_dropdown_mesin_grouped_by_line` FAIL (markup lama/checkbox line masih ada, bukan dropdown mesin).

- [ ] **Step 3: Implement — controller & view VCB Mesin**

`app/Http/Controllers/Vcb/VcbMesinController.php`:

```php
<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbMesinController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();

        return view('vcb.mesin', compact('shifts', 'mesin'));
    }

    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        $rows = $this->dashboardService->getVcbMesin($dateFrom, $dateTo, $shiftId, $mesinIds);
```

(sisa `data()` — pembangunan `labels`/`targets`/`actuals`/`colors`/response JSON — tidak berubah.)

`resources/views/vcb/mesin.blade.php` — baris 6 saat ini persis `@section('content')` diikuti baris 7 `<div class="erp-ws">`. Sisipkan blok `@php` di antara keduanya (pola sama kayak `input/produksi/index.blade.php:14-16`, BUKAN sebelum `@extends`):

```blade
@extends('layouts.app')
@section('title', 'VCB Mesin')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
@php
$mesinByLine = $mesin->groupBy('line');
$_mesinForJs = $mesin->map(fn ($m) => ['id' => $m->id, 'code' => $m->id_mesin, 'name' => $m->nama_mesin, 'line' => $m->line])->values();
@endphp
<div class="erp-ws">
```

Grup "Line" (baris 51-61, checkbox flat) diganti jadi grup "Mesin", dan `<form>`-nya (baris 29) dapet `x-data`:

```blade
    <form id="filterForm" class="erp-dock-body" x-data="mesinFilterComp(_mesinForJs, [])">
        <div class="erp-grp">
            <b>Tanggal Dari</b>
            <input type="date" id="date_from" name="date_from" class="erp-in" value="{{ date('Y-m-01') }}">
        </div>
        <div class="erp-grp">
            <b>Tanggal Sampai</b>
            <input type="date" id="date_to" name="date_to" class="erp-in" value="{{ date('Y-m-d') }}">
        </div>
        <div class="erp-grp">
            <b>Bulan</b>
            <input type="month" id="bulan_filter" class="erp-in">
        </div>
        <div class="erp-grp">
            <b>Shift</b>
            <select id="shift_id" name="shift_id" class="erp-in">
                <option value="">Semua Shift</option>
                @foreach($shifts as $shift)
                    <option value="{{ $shift->id }}">{{ $shift->nama_shift }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-grp">
            <b>Mesin</b>
            @include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])
        </div>
        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-search"></i>Tampilkan</button>
        </div>
    </form>
```

JS `loadData()` — baris `document.querySelectorAll('.line-cb:checked').forEach(cb => params.append('line[]', cb.value));` diganti:

```js
    document.querySelectorAll('#filterForm input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=VcbMesinFilterTest`
Expected: PASS (3/3).

Run: `php artisan test --filter=Vcb` (regresi VCB lain)
Expected: PASS.

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app/Http/Controllers/Vcb/VcbMesinController.php tests/Feature/Vcb/VcbMesinFilterTest.php
git add app/Http/Controllers/Vcb/VcbMesinController.php resources/views/vcb/mesin.blade.php tests/Feature/Vcb/VcbMesinFilterTest.php
git rm tests/Feature/Vcb/VcbLineFilterTest.php
git commit -m "feat(vcb-mesin): ganti filter line jadi dropdown mesin shared

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 4: VCB Produksi — pasang filter mesin shared di kedua form

**Files:**
- Modify: `app/Http/Controllers/Vcb/VcbProduksiController.php:16-67`
- Modify: `resources/views/vcb/produksi.blade.php:1-124` (dock, dua form), `:533` (JS `loadAllOperators()`), `:743` (JS `loadOneOperator()`)
- Modify: `tests/Feature/Vcb/VcbMesinFilterTest.php` (tambah test)

**Interfaces:**
- Consumes: `DashboardService::getVcbProduksi/getVcbProduksiByDate(..., array $mesinIds = [])` (Task 1), shared `mesinFilterComp`/`partials/mesin-picker.blade.php` (Task 2).
- Produces: `VcbProduksiController::index()` kirim `$mesin` (flat `Collection<MasterMesin>`, ganti `$lineList`).

- [ ] **Step 1: Write the failing tests**

Tambahkan di `tests/Feature/Vcb/VcbMesinFilterTest.php`, sebelum `}` penutup class:

```php
    public function test_vcb_produksi_data_disaring_oleh_mesin_id(): void
    {
        $m = $this->duaMesin();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20', 'mesin_ids' => [$m['mesinA']->id],
        ]))->json();

        $this->assertSame(['OPERATOR A'], $json['labels']);
    }

    public function test_vcb_produksi_data_tanpa_filter_mesin_menampilkan_semua(): void
    {
        $this->duaMesin();

        $json = $this->getJson(route('vcb.produksi.data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-20',
        ]))->json();

        $this->assertCount(2, $json['labels']);
    }

    public function test_vcb_produksi_operator_data_disaring_oleh_mesin_id(): void
    {
        $m = $this->duaMesin();

        // Operator A dapat sesi tambahan di mesin B, tanggal beda.
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-21', 'id_shift' => $m['shift']->id, 'id_karyawan' => $m['opA']->id,
            'id_mesin' => $m['mesinB']->id, 'id_part_proses' => $m['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 1000, 'created_by' => $this->admin->id,
        ]);

        $json = $this->getJson(route('vcb.produksi.operator-data', [
            'date_from' => '2026-08-20', 'date_to' => '2026-08-21',
            'karyawan_id' => $m['opA']->id, 'mesin_ids' => [$m['mesinA']->id],
        ]))->json();

        $this->assertCount(1, $json['table']);
        $this->assertSame('20/08/26', $json['table'][0]['tanggal']);
    }

    public function test_vcb_produksi_menampilkan_dropdown_mesin_di_kedua_form(): void
    {
        MasterMesin::create(['id_mesin' => 'A-'.uniqid(), 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $html = $this->get(route('vcb.produksi'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'mesinFilterComp(_mesinForJs, [])'));
        $this->assertSame(2, substr_count($html, "input[name=\"mesin_ids[]\"]"));
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VcbMesinFilterTest`
Expected: 4 test baru FAIL — controller belum baca `mesin_ids`, view masih pakai checkbox line lama.

- [ ] **Step 3: Implement — controller & view VCB Produksi**

`app/Http/Controllers/Vcb/VcbProduksiController.php`:

```php
<?php

namespace App\Http\Controllers\Vcb;

use App\Http\Controllers\Controller;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterShift;
use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VcbProduksiController extends Controller
{
    public function __construct(private DashboardService $dashboardService) {}

    public function index()
    {
        $shifts = MasterShift::orderBy('jam_start')->get();
        $karyawan = MasterKaryawan::whereRaw('LOWER(departemen) = ?', ['produksi'])
            ->orderBy('nama_karyawan')
            ->get();
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();

        return view('vcb.produksi', compact('shifts', 'karyawan', 'mesin'));
    }

    /**
     * Endpoint lama: summary per-operator (masih digunakan jika dibutuhkan).
     */
    public function data(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        $rows = $this->dashboardService->getVcbProduksi($dateFrom, $dateTo, $shiftId, $mesinIds);

        $labels = array_column($rows, 'karyawan');
        $achievements = array_column($rows, 'achievement_pct');
        $colors = array_map(fn ($r) => $this->trafficColor($r['traffic_light']), $rows);

        return response()->json([
            'labels' => $labels,
            'achievements' => $achievements,
            'colors' => $colors,
            'table' => $rows,
        ]);
    }

    /**
     * Endpoint baru: per-tanggal untuk satu operator spesifik.
     */
    public function operatorData(Request $request)
    {
        $dateFrom = Carbon::parse($request->input('date_from', today()->toDateString()));
        $dateTo = Carbon::parse($request->input('date_to', today()->toDateString()));
        $karyawanId = (int) $request->input('karyawan_id');
        $shiftId = $request->filled('shift_id') ? (int) $request->input('shift_id') : null;
        $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));

        if (! $karyawanId) {
            return response()->json(['error' => 'Pilih operator terlebih dahulu.'], 422);
        }

        $rows = $this->dashboardService->getVcbProduksiByDate($dateFrom, $dateTo, $karyawanId, $shiftId, $mesinIds);

        $operator = MasterKaryawan::find($karyawanId)?->nama_karyawan ?? '—';

        return response()->json([
            'operator' => $operator,
            'labels' => array_column($rows, 'tanggal'),
            'achievements' => array_column($rows, 'achievement_pct'),
            'colors' => array_map(fn ($r) => $this->trafficHex($r['traffic_light']), $rows),
            'table' => $rows,
        ]);
    }
```

(sisa `trafficColor`/`trafficHex` tidak berubah.)

`resources/views/vcb/produksi.blade.php` — baris 6 saat ini `@section('content')`, diikuti baris kosong lalu blok komentar besar lalu `<div class="erp-ws">` (baris 13). Sisipkan blok `@php` persis setelah `@section('content')`, sebelum blok komentar:

```blade
@extends('layouts.app')
@section('title', 'VCB Produksi')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
@php
$mesinByLine = $mesin->groupBy('line');
$_mesinForJs = $mesin->map(fn ($m) => ['id' => $m->id, 'code' => $m->id_mesin, 'name' => $m->nama_mesin, 'line' => $m->line])->values();
@endphp

{{-- ══════════════════════════════════════════════════════════════
     Ruang kerja ERP. Berbeda dari halaman input: isinya KPI + grafik +
     tabel yang memang perlu digulung, jadi .erp-scroll dibiarkan
     menggulung tegak — bukan dikunci satu layar.
══════════════════════════════════════════════════════════════ --}}
<div class="erp-ws">
```

`formAll` — `<form>` dapet `x-data`, grup "Line" (baris 61-71) diganti grup "Mesin":

```blade
    <form id="formAll" class="erp-dock-form" x-data="mesinFilterComp(_mesinForJs, [])">
            <div class="erp-grp">
                <b>Dari Tanggal</b>
                <input type="date" id="all_date_from" class="erp-in" value="{{ now('Asia/Jakarta')->startOfMonth()->toDateString() }}">
            </div>
            <div class="erp-grp">
                <b>Sampai Tanggal</b>
                <input type="date" id="all_date_to" class="erp-in" value="{{ now('Asia/Jakarta')->toDateString() }}">
            </div>
            <div class="erp-grp">
                <b>Bulan</b>
                <input type="month" id="all_bulan_filter" class="erp-in">
            </div>
            <div class="erp-grp">
                <b>Shift</b>
                <select id="all_shift_id" class="erp-in">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Mesin</b>
                @include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])
            </div>
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri">
                    <i class="bi bi-funnel me-1"></i>Tampilkan
                </button>
            </div>
        </form>
```

`formOne` — sama pola, grup "Line" (baris 113-123) diganti:

```blade
    <form id="formOne" class="erp-dock-form" x-data="mesinFilterComp(_mesinForJs, [])">
            <div class="erp-grp">
                <b>Dari Tanggal</b>
                <input type="date" id="one_date_from" class="erp-in" value="{{ now('Asia/Jakarta')->startOfMonth()->toDateString() }}">
            </div>
            <div class="erp-grp">
                <b>Sampai Tanggal</b>
                <input type="date" id="one_date_to" class="erp-in" value="{{ now('Asia/Jakarta')->toDateString() }}">
            </div>
            <div class="erp-grp">
                <b>Bulan</b>
                <input type="month" id="one_bulan_filter" class="erp-in">
            </div>
            <div class="erp-grp">
                <b>Operator <span class="text-danger">*</span></b>
                <select id="one_karyawan_id" class="erp-in">
                    <option value="">— Pilih Operator —</option>
                    @foreach($karyawan as $k)
                        <option value="{{ $k->id }}"
                                data-nonaktif="{{ ! $k->is_aktif && $k->tanggal_nonaktif ? $k->tanggal_nonaktif->format('Y-m-d') : '' }}">{{ $k->nama_karyawan }}</option>
                    @endforeach
                </select>
                <small id="one_operator_note" style="color:#b91c1c;font-size:10px"></small>
            </div>
            <div class="erp-grp">
                <b>Shift</b>
                <select id="one_shift_id" class="erp-in">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Mesin</b>
                @include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])
            </div>
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                @if(! auth()->user()->isGuest())
                <a id="oneExportBtn" href="#" class="erp-btn">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export
                </a>
                @endif
            </div>
        </form>
```

JS `loadAllOperators()` — baris `document.querySelectorAll('.all-line-cb:checked').forEach(cb => params.append('line[]', cb.value));` diganti:

```js
    document.querySelectorAll('#formAll input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
```

JS `loadOneOperator()` — baris `document.querySelectorAll('.one-line-cb:checked').forEach(cb => params.append('line[]', cb.value));` diganti:

```js
    document.querySelectorAll('#formOne input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=VcbMesinFilterTest`
Expected: PASS (7/7 — 3 dari Task 3 + 4 baru).

Run full regression: `php artisan test`
Expected: PASS (seluruh suite, termasuk `VcbTanggalDanDrilldownTest`, `VcbAchievedCounterTest`, `KomponenBersamaTest`, `ProduksiMesinFilterTest` yang baca halaman-halaman ini).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint app/Http/Controllers/Vcb/VcbProduksiController.php tests/Feature/Vcb/VcbMesinFilterTest.php
git add app/Http/Controllers/Vcb/VcbProduksiController.php resources/views/vcb/produksi.blade.php tests/Feature/Vcb/VcbMesinFilterTest.php
git commit -m "feat(vcb-produksi): ganti filter line jadi dropdown mesin shared (Mode 1 & 2)

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Verifikasi Manual (Browser)

Setelah 4 task selesai, sebelum PR:

1. Login admin, buka **Input > LHP Produksi** — buka Filter, pastikan dropdown "Semua Mesin" masih kerja normal (grouped by Line, klik chip toggle, badge "N mesin"/kode tunggal, tombol "Hapus semua pilihan"). Perilaku harus identik sebelum refactor.
2. Buka **VCB Mesin** — dropdown "Semua Mesin" muncul, grouped by Line, pilih 1-2 mesin, klik Tampilkan, pastikan chart & tabel cuma nampilin mesin yang dipilih.
3. Buka **VCB Produksi** — cek dropdown mesin muncul di Mode 1 (Semua Operator) dan Mode 2 (Per Operator), masing-masing independen (pilih mesin beda di tiap mode, pastikan nggak saling pengaruh).
4. Kosongkan semua pilihan mesin di ketiga halaman, pastikan kembali nampilin semua data.
