# Filter LINE — VCB Produksi & VCB Mesin

**Tanggal:** 2026-09-18

> ⚠️ **SUPERSEDED** oleh [2026-09-22-vcb-mesin-filter-design.md](2026-09-22-vcb-mesin-filter-design.md)
> (PR #178). Filter LINE (checkbox flat) di dokumen ini diganti dropdown mesin
> multi-select grouped-by-line — dipertahankan sebagai riwayat desain, bukan
> deskripsi perilaku yang live sekarang.

## Latar Belakang

VCB Produksi dan VCB Mesin sudah punya filter Bulan/Tanggal + Shift. User mau
tambah filter **LINE** (mesin dikelompokkan per line produksi: A, B, C, ...)
supaya bisa bandingin performa antar line.

Kolom `line` sudah ada di `master_mesin` (string bebas, contoh: `A`, `B`, `C`).
Diverifikasi via screenshot Master > Mesin: 26 mesin, line berisi huruf tunggal
(A/B/C, kemungkinan sampai P sesuai jumlah line pabrik). Tidak ada kolom
sub-line terpisah — ID mesin (A-001, A-002, ...) cuma penomoran per mesin,
bukan bagian dari struktur line.

**Tidak ada migrasi baru** — kolom `line` sudah live di prod, filter ini murni
query tambahan.

## Scope

- Filter level LINE saja (bukan per-mesin individual).
- **Multi-select** (checkbox) — user bisa pilih beberapa line sekaligus untuk
  dibandingkan.
- Berlaku di:
  - VCB Mesin (satu-satunya form filter)
  - VCB Produksi — **kedua form**: `dockAll` (Mode 1, semua operator) dan
    `dockOne` (Mode 2, drill-down per operator per tanggal)
- Kosong dicentang = tampilkan semua line (default, tidak ada filter).

## Perubahan Backend

### `app/Services/DashboardService.php`

Tiga method dapat parameter baru `array $lines = []`:
- `getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = [])`
- `getVcbProduksiByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanId, ?int $shiftId = null, array $lines = [])`
- `getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = [])`

Clause baru disisipkan tepat setelah `when($shiftId, ...)` yang sudah ada,
mengikuti pola `whereIn` yang sudah dipakai di `getVcbDandori`:

```php
->when(!empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
```

### `app/Http/Controllers/Vcb/VcbProduksiController.php`

- `index()`: tambah `$lineList = MasterMesin::query()->distinct()->orderBy('line')->pluck('line')->filter()->values();`,
  kirim ke view via `compact`.
- `data()` dan `operatorData()`: baca `$request->input('line', [])` (array of
  string), teruskan sebagai argumen `$lines` ke service.

### `app/Http/Controllers/Vcb/VcbMesinController.php`

- `index()`: sama, load `$lineList`, kirim ke view.
- `data()`: baca `$request->input('line', [])`, teruskan ke `getVcbMesin`.

Tidak perlu validasi ketat terhadap `$lineList` — `whereIn` sudah aman dari
SQL injection lewat parameter binding Eloquent; nilai yang tidak match cuma
menghasilkan 0 baris (bukan celah keamanan).

## Perubahan Frontend

### `resources/views/vcb/mesin.blade.php`

Tambah grup checkbox "Line" di `#mesinDock`, setelah grup Shift, pola sama
seperti checkbox `pakai_tanggal` yang sudah ada di breakdown (`.erp-chk`):

```html
<div class="erp-grp">
    <b>Line</b>
    <div style="display:flex;flex-wrap:wrap;gap:8px">
        @foreach($lineList as $l)
        <label class="erp-chk">
            <input type="checkbox" class="line-cb" value="{{ $l }}">
            {{ $l }}
        </label>
        @endforeach
    </div>
</div>
```

JS `loadData()`: kumpulkan checkbox `.line-cb` yang tercentang, append tiap
value sebagai `line[]` ke `URLSearchParams` (append per item, bukan single
value — `URLSearchParams` mendukung multi-append key yang sama). Query yang
sama dipakai ulang untuk link Export Excel (`exportBtn.href`), jadi otomatis
ikut ter-filter tanpa kode tambahan.

### `resources/views/vcb/produksi.blade.php`

Grup checkbox yang sama ditambahkan di **kedua** form:
- `formAll` (dockAll) — id checkbox pakai prefix `all_line_cb`
- `formOne` (dockOne) — id checkbox pakai prefix `one_line_cb`

mengikuti konvensi prefix `all_`/`one_` yang sudah dipakai field lain di
halaman ini. JS pembangun params masing-masing mode (`loadAll()`/`loadOne()`
atau nama fungsi setara di file ini) diupdate untuk append `line[]` dari
checkbox grup yang sesuai mode-nya.

## Testing

Tambah test ke test suite Vcb yang sudah ada
(`tests/Feature/.../VcbProduksiControllerTest.php`,
`VcbMesinControllerTest.php` — nama pasti dicek saat implementasi):
- Seed 2+ `MasterMesin` dengan `line` beda (mis. `A` dan `B`), masing-masing
  ada transaksi produksi.
- Assert filter `line[]=A` cuma mengembalikan data mesin/operator yang mesin-nya
  line A, line B ter-exclude.
- Assert tanpa filter `line` (default) tetap mengembalikan semua data seperti
  sebelumnya (no regression).
