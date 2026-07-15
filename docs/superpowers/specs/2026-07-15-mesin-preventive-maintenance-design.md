# Design: Mesin Preventive Maintenance (Control Stroke Mesin Upgrade)

**Tanggal:** 2026-07-15
**Status:** Approved (brainstorming)
**Project:** OPTIMA MES — Laravel 13

## Konteks

Halaman **Control Stroke Dies** sudah diupgrade jadi dashboard preventive maintenance
(lihat [2026-07-15-dies-preventive-maintenance-design.md](2026-07-15-dies-preventive-maintenance-design.md)):
counter stroke berjalan sejak preventive terakhir, dibandingkan ke threshold, dengan status
visual, halaman input preventive (reset counter), dan halaman riwayat.

Spec ini **mereplikasi pola itu untuk Mesin** di halaman `maintenance.control-stroke-mesin`,
yang saat ini masih menampilkan total stroke per rentang tanggal filter tanpa konsep threshold
atau preventive.

**Threshold mesin = 300.000 stroke** (angka sementara, user akan update nanti). Ini SENGAJA
beda jauh dari dies (30.000): stroke mesin adalah jumlah SEMUA part yang dikerjakan mesin itu
(banyak dies bergantian di satu mesin), jadi volumenya berlipat dibanding satu dies. Memakai
30.000 untuk mesin akan membuat semua mesin permanen merah dan halaman tidak berguna.

## Scope

**Yang dibangun:**
- Tabel baru `mesin_preventive_log` + model `MesinPreventiveLog` + relasi `MasterMesin::preventiveLogs()`.
- `MaintenanceDashboardService`: konstanta `STROKE_THRESHOLD = 300000`, method `currentStrokeFor()`
  dan `computeCurrentCycle()`; **hapus** `computeStroke()` (jadi mati setelah controller dipindah).
- `ControlStrokeMesinController`: `index()`/`data()` dirombak, tambah `createPreventive()`,
  `storePreventive()`, `history()`.
- 3 halaman penuh (TANPA modal): tabel monitoring, form input preventive, riwayat preventive.
- Generalisasi partial ringkasan stroke agar dipakai bersama Dies & Mesin.
- Wiring migration baru ke `deploy.yml`.

**Yang TIDAK dibangun (out of scope):**
- Threshold per-mesin / per-tipe (flat 300.000 untuk semua — keputusan user).
- Preventive berbasis waktu/jadwal (user memilih berbasis stroke).
- Notifikasi otomatis saat mesin overdue.
- Edit/hapus log preventive yang sudah diinput.
- Perubahan apa pun ke Control Stroke Dies selain rename partial bersama.

## Perbedaan sadar dari Dies

| Hal | Dies | Mesin (spec ini) | Alasan |
|---|---|---|---|
| Threshold | 30.000 | **300.000** | volume stroke mesin = gabungan semua dies yang jalan di mesin itu |
| Tabel log | `dies_preventive_log` | **`mesin_preventive_log`** | pola project: Mesin & Dies selalu sepasang tapi terpisah (MTTR vs MTTR-Dies, dst) |
| Baris ditampilkan | hanya yang punya `TransaksiProduksi` (386 dies, mayoritas noise) | **semua mesin** (tanpa filter `whereHas`) | mesin jumlahnya sedikit; mesin nganggur (0 stroke) tetap informasi berguna, dan ini perilaku `computeStroke()` mesin yang sudah ada |
| Filter | Customer + Part No | **Tipe Mesin + Mesin** | filter yang relevan di domain mesin (filter tanggal dibuang di kedua-duanya) |
| Kolom ke-3 tabel | Proses | **Tipe** | mesin tidak punya proses; tipe berguna buat manajemen |

## Data Model

### Tabel baru `mesin_preventive_log`

| kolom | tipe | catatan |
|---|---|---|
| id | bigint PK | |
| id_mesin | FK → `master_mesin.id` | mesin yang di-preventive |
| tanggal_preventive | date | ≤ hari ini |
| id_karyawan | FK → `master_karyawan.id` | PIC/petugas |
| stroke_at_preventive | integer | snapshot stroke saat preventive — **dihitung server**, bukan input user |
| catatan | text, nullable | |
| created_by | FK → `users.id` | user yang input record |
| timestamps + softDeletes | | pola project |

Index: `id_mesin`, `tanggal_preventive`.

### Model `App\Models\MesinPreventiveLog`

`use SoftDeletes`. `$table = 'mesin_preventive_log'`.
`$fillable = ['id_mesin', 'tanggal_preventive', 'id_karyawan', 'stroke_at_preventive', 'catatan', 'created_by']`.
`$casts = ['tanggal_preventive' => 'date:Y-m-d']`.
Relasi: `mesin()` → `belongsTo(MasterMesin::class, 'id_mesin')`,
`karyawan()` → `belongsTo(MasterKaryawan::class, 'id_karyawan')`,
`createdBy()` → `belongsTo(User::class, 'created_by')`.

`MasterMesin` tambah `preventiveLogs(): HasMany` → `hasMany(MesinPreventiveLog::class, 'id_mesin')`
dengan `orderByDesc('tanggal_preventive')`.

**Tidak ada kolom baru** di `master_mesin` atau `transaksi_produksi`.

## Perhitungan: Running Cycle Stroke

Di `app/Services/MaintenanceDashboardService.php`:

```php
public const STROKE_THRESHOLD = 300000;

public function currentStrokeFor(MasterMesin $m): int
{
    $lastPreventive = MesinPreventiveLog::where('id_mesin', $m->id)->max('tanggal_preventive');
    $cycleStart     = $lastPreventive ?? $m->tanggal_instalasi;

    return (int) TransaksiProduksi::query()
        ->where('id_mesin', $m->id)
        ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
        ->sum('qty_aktual');
}
```

`cycleStart` = tanggal preventive terakhir → fallback `MasterMesin.tanggal_instalasi` → fallback
null (all-time). Perbandingan **strict `>`** (sama seperti dies).

`computeCurrentCycle(?int $mesinId = null, ?string $tipeMesin = null): array` — per mesin:
1. `current_stroke` = `$this->currentStrokeFor($m)`
2. `pct` = `round($current_stroke / self::STROKE_THRESHOLD * 100, 1)`
3. `status` = `$pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok')`
4. `last_preventive_date` / `last_preventive_pic` dari log terakhir (eager-load
   `preventiveLogs.karyawan`, ambil `->first()` — jangan query per baris di loop).

**Listing scope:** SEMUA mesin (tidak ada filter `whereHas('transaksiProduksi')` — beda dari
dies, lihat tabel Perbedaan di atas). Filter `tipe_mesin` tetap defensif terhadap kolom yang
mungkin belum ada di hosting (`Schema::hasColumn('master_mesin', 'tipe_mesin')`, pola yang
sudah dipakai `compute()` dan `computeStroke()`).

Output row shape:
```php
[
    'id_mesin'             => int,      // PK master_mesin
    'kode_mesin'           => string,   // MasterMesin.id_mesin (kode bisnis)
    'nama_mesin'           => string,
    'tipe_mesin'           => ?string,
    'current_stroke'       => int,
    'threshold'            => int,      // 300000
    'pct'                  => float,
    'status'               => string,   // 'ok' | 'warning' | 'overdue'
    'last_preventive_date' => ?string,  // 'Y-m-d'
    'last_preventive_pic'  => ?string,
]
```

Sorting (server-side): `overdue` → `warning` → `ok`, lalu `pct` descending dalam grup sama.

`computeStroke()` **dihapus** dari `MaintenanceDashboardService`.

## Routes & Controller

Ditambahkan ke grup `role:admin,staff_maintenance,manager` prefix `maintenance` yang sudah ada:

```
GET  availability/control-stroke-mesin/{mesin}/history     @history            → control-stroke-mesin.history
GET  availability/control-stroke-mesin/{mesin}/preventive   @createPreventive   → control-stroke-mesin.preventive.create
POST availability/control-stroke-mesin/{mesin}/preventive   @storePreventive    → control-stroke-mesin.preventive
```

`{mesin}` route-model-binds ke `MasterMesin`.

`ControlStrokeMesinController` (hapus `use ResolvesMaintenanceRange` — tidak ada filter tanggal lagi):

- `index()` — kirim `mesinList` (buat filter typeahead) + `tipeList`. TIDAK kirim `karyawanList`
  (form preventive sekarang halaman sendiri).
- `data(Request)` — `mesin_id` + `tipe_mesin` saja (tanpa `date_from`/`date_to`/`bulan`), panggil
  `computeCurrentCycle()`, balikan:
  ```php
  ['rows' => $rows, 'summary' => ['total_mesin' => count($rows), 'overdue' => ..., 'warning' => ...]]
  ```
- `createPreventive(MasterMesin $mesin)` — `abort_if` role bukan admin/staff_maintenance → 403.
  Kirim `mesin`, `currentStroke` (via `currentStrokeFor`), `threshold`, `karyawanList`
  (`MasterKaryawan::aktif()->where('departemen','Maintenance')`).
- `storePreventive(MasterMesin $mesin, Request)` — `abort_if` role → 403. Validasi
  `tanggal_preventive` (required|date|before_or_equal:today), `id_karyawan` (required|exists),
  `catatan` (nullable|string|max:1000). Hitung `stroke_at_preventive` server-side via
  `currentStrokeFor($mesin)`, simpan log, `created_by = auth()->id()`, lalu
  `redirect()->route('maintenance.control-stroke-mesin')->with('success', ...)`.
- `history(MasterMesin $mesin)` — semua role di grup boleh. Kirim `mesin`, `logs`
  (`preventiveLogs()->with(['karyawan:id,nama_karyawan','createdBy:id,name'])->orderByDesc('tanggal_preventive')->get()`),
  `currentStroke`, `threshold`.

## Views

**Tidak ada modal sama sekali** — semua halaman penuh (keputusan user setelah modal Dies
bermasalah dengan overlay).

1. `resources/views/maintenance/control-stroke-mesin.blade.php` — **rewrite**, mirror
   `control-stroke-dies.blade.php`:
   - Filter: Tipe Mesin (select) + Mesin (taField), tombol Tampilkan. Tanpa filter tanggal.
   - 3 kartu ringkasan berwarna: Total Mesin Dipantau (netral), Segera Preventive (merah),
     Mendekati Batas (kuning).
   - Tabel: `Mesin | Nama Mesin | Tipe | Stroke Saat Ini | Status | Preventive Terakhir | Aksi`.
     Progress bar + `{current} / {threshold}` + `{pct}%` + baris "sisa N stroke" / "lewat N stroke",
     badge status ikon+teks, tint baris (merah overdue / kuning warning), kolom `white-space:nowrap`.
   - Aksi: link **Preventive** (disabled button kalau role bukan admin/staff_maintenance) +
     link **Riwayat**.
   - Header kanan: "Target preventive: **300.000 stroke** / mesin".

2. `resources/views/maintenance/control-stroke-mesin-preventive.blade.php` — mirror
   `control-stroke-dies-preventive.blade.php`: page-header + Kembali, ringkasan stroke (partial),
   peringatan reset, form (Tanggal / PIC / Catatan), tombol Simpan & Reset ke 0 / Batal.

3. `resources/views/maintenance/control-stroke-mesin-history.blade.php` — mirror
   `control-stroke-dies-history.blade.php`: kartu identitas Mesin (kode, nama, tipe, line) +
   ringkasan stroke (partial), kartu Ringkasan Preventive (total preventive, preventive terakhir,
   tombol Input Preventive Baru), tabel riwayat (`Tanggal | PIC | Stroke Tercapai | Catatan |
   Diinput Oleh`) + empty state.

**Partial bersama:** rename `resources/views/maintenance/partials/dies-stroke-summary.blade.php`
→ `resources/views/maintenance/partials/stroke-summary.blade.php` (isinya sudah generic —
butuh `$currentStroke` + `$threshold`). Update 2 `@include` di halaman Dies (preventive &
history) agar menunjuk nama baru.

## Deploy

**WAJIB:** tambahkan migration baru ke step targeted-migrate di `.github/workflows/deploy.yml`
(prod pakai `--path=` eksplisit karena schema drift — blanket `migrate` meledak):

```
--path=database/migrations/2026_07_15_000003_create_mesin_preventive_log_table.php
```

Kalau lupa, tabel tidak pernah dibuat di prod dan seluruh halaman 500 (persis yang nyaris
terjadi di fase Dies).

## Testing

phpunit:
- **`currentStrokeFor()` (unit):** fallback ke `tanggal_instalasi` saat belum pernah preventive;
  all-time saat instalasi & preventive dua-duanya null; hanya hitung transaksi SETELAH preventive
  terakhir; pakai `MAX(tanggal_preventive)` (bukan yang paling awal / bukan by id).
- **`computeCurrentCycle()` (unit):** `pct`/`status` benar di 3 ambang (<80 ok, 80–99.99 warning,
  ≥100 overdue) memakai threshold 300.000; sorting overdue→warning→ok lalu pct desc; **mesin
  tanpa transaksi tetap muncul dengan `current_stroke: 0`** (beda dari dies — kunci perbedaan
  listing scope); filter `mesin_id` & `tipe_mesin` mempersempit hasil.
- **`storePreventive()` (feature):** admin & staff_maintenance bisa input → redirect + flash,
  log tersimpan, `stroke_at_preventive` = stroke SEBELUM reset (bukan 0); manager 403;
  `staff_produksi` 403 (kegate middleware); tanggal masa depan ditolak (`assertSessionHasErrors`);
  `id_karyawan` tak dikenal ditolak; setelah tersimpan `data()` menunjukkan `current_stroke: 0`.
- **`createPreventive()` (feature):** admin bisa buka form (`assertViewIs`, `currentStroke` &
  `threshold` benar); manager 403.
- **`history()` (feature):** view benar, log hanya milik mesin yang diminta (mesin lain tidak
  terbawa), manager boleh lihat.
- **`data()` (feature):** JSON `rows` + `summary.total_mesin/overdue/warning` benar.
- **Role gate `index()`:** `staff_produksi`/`staff_qc` 403 (sudah ada, tidak berubah).

## Catatan Implementasi

- `MasterMesin` **tidak punya `$casts`**, jadi `tanggal_instalasi` bernilai string `'Y-m-d'`
  (bukan Carbon) — beda dari `MasterPart` yang punya cast `date`. Aman dipakai langsung sebagai
  `$cycleStart` di query SQL; JANGAN panggil `->format()` di atasnya tanpa `Carbon::parse()`.
- `stroke_at_preventive` WAJIB dihitung server-side, TIDAK boleh terima nilai dari request —
  mencegah pemalsuan riwayat.
- Threshold 300.000 adalah angka sementara. Simpan sebagai konstanta bernama yang jelas supaya
  perubahan cukup satu baris.
- Jangan sentuh `MasterMesin.kapasitas_max_stroke` — itu throughput stroke/jam, konsep berbeda
  dari counter kumulatif.
- Control Stroke Dies tidak boleh berubah perilakunya; satu-satunya sentuhan adalah update
  `@include` akibat rename partial.
