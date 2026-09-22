# Filter Mesin (ganti Filter LINE) — VCB Produksi & VCB Mesin

**Tanggal:** 2026-09-22

## Latar Belakang

PR #177 (2026-09-18) nambahin filter LINE (checkbox flat A/B/C/...) ke VCB
Produksi & VCB Mesin. User sekarang minta filter itu diganti: bukan pilih
LINE, tapi pilih **mesin individual** — dropdown "Semua Mesin" yang mesinnya
dikelompokkan per LINE, multi-select, persis kayak filter mesin yang udah
ada di halaman **LHP Produksi** (`resources/views/input/produksi/index.blade.php`).

Komponen itu di LHP Produksi (`mesinFilterComp()`) sekarang **bespoke** —
definisi JS-nya ada inline di file itu sendiri, baca variabel global
`_mesinList`/`_mesinSelected` yang cuma ada sekali di halaman itu. Karena
sekarang mau dipakai di 3 halaman (LHP Produksi + VCB Produksi + VCB Mesin,
dan VCB Produksi butuh 2 instance independen — Mode 1 & Mode 2), komponennya
digeneralisasi jadi shared component di `layouts/app.blade.php`, nerima data
lewat parameter — pola yang sama kayak `taField(items, initText, initVal)`
yang udah ada di sana.

**Tidak ada migrasi baru** — kolom `master_mesin.id`/`line` udah ada, murni
perubahan query + UI.

## Scope

- **Cabut total** filter LINE dari VCB Produksi & VCB Mesin: checkbox
  `line-cb`/`all-line-cb`/`one-line-cb`, param `line[]`, `DashboardService`
  parameter `$lines`, `$lineList` di kedua controller.
- **Tambah** filter mesin multi-select (dropdown "Semua Mesin" grouped by
  LINE) di:
  - VCB Mesin — satu-satunya form filter.
  - VCB Produksi — **kedua form** (Mode 1 `formAll`, Mode 2 `formOne`).
- **Promote** `mesinFilterComp()` jadi shared Alpine component di
  `layouts/app.blade.php`, plus markup-nya (grouped chip list) jadi Blade
  partial bersama `resources/views/partials/mesin-picker.blade.php`.
- **Refactor** LHP Produksi (`input/produksi/index.blade.php`) buat konsumsi
  versi shared ini juga — hapus definisi lokalnya, perilaku LHP Produksi
  **tidak berubah** (param `mesin_ids[]`, filter server tetep
  `whereIn('id_mesin', $mesinIds)`, sudah begitu dari awal).

## Komponen Shared

### JS: `mesinFilterComp(mesinList, initialSelected = [])`

Pindah dari `input/produksi/index.blade.php:787-801` ke
`layouts/app.blade.php`, di dalam blok "Komponen ketik-cari bersama"
(dekat `taField`/`filterSearchComp`), digeneralisasi nerima data lewat
parameter alih-alih baca const global:

```js
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

`KomponenBersamaTest::KOMPONEN` (`tests/Feature/Layout/KomponenBersamaTest.php`)
dapat entri baru `'function mesinFilterComp('` — test itu udah otomatis
menjaga "cuma satu definisi, di layout" begitu ditambah ke daftar konstanta.

### Blade: `resources/views/partials/mesin-picker.blade.php`

Markup dropdown (trigger button + grouped chip list + tombol "Hapus semua
pilihan") dari `input/produksi/index.blade.php:184-215`, dipindah jadi
partial yang nerima satu variabel wajib `$mesinByLine` (Collection hasil
`groupBy('line')`). Partial ini **harus** dipanggil di dalam elemen yang
sudah punya scope `x-data="mesinFilterComp(...)"` (biasanya elemen `<form>`
pembungkusnya) — dia sendiri nggak buka scope Alpine baru:

```blade
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

Ditambah, sebelum `@include`, hidden-input template buat kirim nilai
terpilih ke form (nama param **konsisten** `mesin_ids[]` di ketiga halaman):

```blade
<template x-for="id in selected" :key="id">
    <input type="hidden" name="mesin_ids[]" :value="id">
</template>
```

CSS-nya (`.erp-mesin-pick`, `.erp-mesin-line`, `.erp-mesin-chips`,
`.erp-chip`, `.erp-mesin-clear`) sudah ada di `layouts/app.blade.php`,
dipakai ulang apa adanya — nol perubahan CSS.

## Perubahan Backend

### `app/Services/DashboardService.php`

Tiga method ganti parameter `array $lines = []` → `array $mesinIds = []`,
clause filter diganti dari `whereHas('mesin', whereIn('line', ...))`
(butuh join lewat relasi) jadi langsung `whereIn('id_mesin', ...)` (kolom
native di `TransaksiProduksi`, lebih simpel & lebih cepat):

```php
public function getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $mesinIds = []): array
{
    $records = TransaksiProduksi::with(['karyawan', 'partProses.part', 'mesin'])
        ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
        ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
        ->when(! empty($mesinIds), fn ($q) => $q->whereIn('id_mesin', $mesinIds))
        ->get();
    // ...
}
```

Sama polanya buat `getVcbProduksiByDate` (parameter ke-5) dan `getVcbMesin`
(parameter ke-4). Komentar lama soal mesin soft-deleted "hilang" dari
`whereHas` (`DashboardService.php:20`) **dihapus** — `whereIn('id_mesin',
...)` langsung cocok ke ID, nggak lewat relasi query, jadi soft-delete
mesin nggak mempengaruhi hasil filter (beda dari sebelumnya).

### `app/Http/Controllers/Vcb/VcbProduksiController.php` &
`app/Http/Controllers/Vcb/VcbMesinController.php`

- `index()` — ganti `$lineList` jadi:
  ```php
  $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->get();
  ```
  dikirim ke view via `compact(..., 'mesin')`. Grouping (`$mesinByLine`,
  `$_mesinForJs`) dilakuin di Blade, persis pola LHP Produksi (lihat bagian
  View di bawah) — bukan di controller.
- `data()` / `operatorData()` — ganti parsing `$lines` jadi:
  ```php
  $mesinIds = array_filter(array_map('intval', (array) $request->input('mesin_ids', [])));
  ```
  (identik `ProduksiController::index()`), teruskan sebagai argumen
  `$mesinIds` ke service.

## Perubahan View

### `resources/views/vcb/mesin.blade.php`

- Form filter (`id="filterForm"`) yang sebelumnya nggak punya Alpine scope
  sama sekali, sekarang dapet `x-data="mesinFilterComp(_mesinForJs, [])"`.
- Blok `@php $mesinByLine = $mesin->groupBy('line'); $_mesinForJs = $mesin->map(...)->values(); @endphp`
  ditaruh di atas (persis pola `input/produksi/index.blade.php:15-16`).
- Grup "Line" (checkbox flat) diganti jadi grup "Mesin" isinya
  `@include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])` +
  hidden-input template.
- JS `loadData()`: baris `document.querySelectorAll('.line-cb:checked').forEach(cb => params.append('line[]', cb.value));`
  diganti:
  ```js
  document.querySelectorAll('#filterForm input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
  ```
  (Alpine `x-for` udah nulis hidden input-nya ke DOM secara reaktif — JS
  tinggal baca DOM, sama kayak checkbox sebelumnya, cuma sumbernya beda.)

### `resources/views/vcb/produksi.blade.php`

- `formAll` & `formOne` masing-masing dapet `x-data="mesinFilterComp(_mesinForJs, [])"`
  sendiri-sendiri — dua instance state independen, satu `_mesinForJs` yang
  sama (daftar mesin sama buat seluruh halaman).
- Grup "Line" di kedua form diganti grup "Mesin" (`@include('partials.mesin-picker', ...)`
  + hidden-input template) — dengan `name="mesin_ids[]"` yang sama di
  kedua form (aman, karena tiap form kirim request `fetch` sendiri-sendiri,
  bukan submit HTML biasa yang bisa nabrak).
- JS `loadAllOperators()` & `loadOneOperator()`: baris
  `querySelectorAll('.all-line-cb:checked')` / `.one-line-cb:checked` diganti
  baca hidden input di form masing-masing:
  ```js
  document.querySelectorAll('#formAll input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
  document.querySelectorAll('#formOne input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
  ```

### `resources/views/input/produksi/index.blade.php`

- Baris `x-data="mesinFilterComp()"` (line 160) → `x-data="mesinFilterComp(_mesinForJs, _mesinSelected)"`.
- Blok markup grouped-chip-list (line 197-215) diganti
  `@include('partials.mesin-picker', ['mesinByLine' => $mesinByLine])`.
- Definisi lokal `function mesinFilterComp() {...}` (line 787-801) **dihapus**
  — sekarang dari layout. `const _mesinList`/`const _mesinSelected`
  (line 785-786) **tetap** — itu data awal yang di-generate server-side dari
  `$mesinIds` (state filter aktif saat page-load GET), dipakai sebagai
  argumen ke `mesinFilterComp()`.

## Testing

- `tests/Feature/Vcb/VcbLineFilterTest.php` → **rename**
  `tests/Feature/Vcb/VcbMesinFilterTest.php`, isinya ditulis ulang: ganti
  seed `line` jadi assert lewat `id`, request param `line` → `mesin_ids`,
  assertion markup HTML (`class="line-cb"`, `querySelectorAll('.line-cb...')`)
  → assertion markup baru (`erp-chip`, `data-` id mesin, `mesin_ids[]`,
  panggilan `mesinFilterComp(`).
- `tests/Unit/DashboardServiceTest.php` — 3 test line-filter
  (`test_line_filter_narrows_produksi`, `test_line_filter_narrows_mesin`,
  `test_line_filter_accepts_multiple_values`) ditulis ulang jadi filter by
  `id_mesin` bukan `line`.
- `tests/Feature/Layout/KomponenBersamaTest.php` — tambah
  `'function mesinFilterComp('` ke `KOMPONEN` const.
- Regresi LHP Produksi: tambah 1 test baru yang assert
  `route('input.produksi.index')` dengan `mesin_ids[]` terisi tetap
  nyaring transaksi dengan benar setelah refactor (bukti nol regresi
  perilaku lama, bukan cuma "halaman masih render").
