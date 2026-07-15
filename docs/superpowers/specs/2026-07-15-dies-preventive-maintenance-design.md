# Design: Dies Preventive Maintenance (Control Stroke Dies Upgrade)

**Tanggal:** 2026-07-15
**Status:** Approved (brainstorming)
**Project:** OPTIMA MES — Laravel 13

## Konteks

Perusahaan punya target: setiap dies wajib di-**preventive** (perawatan berkala) setiap
**30.000 stroke**. Halaman **Control Stroke Dies** (`maintenance.control-stroke-dies`, dibangun
di [2026-07-15-control-stroke-design.md](2026-07-15-control-stroke-design.md)) saat ini cuma
nunjukin total stroke kumulatif per rentang tanggal filter — tidak ada konsep threshold,
tidak ada alur "sudah dipreventive, counter reset ke 0", dan tidak ada history preventive.

Spec ini **upgrade total** halaman Control Stroke Dies menjadi dashboard monitoring preventive
maintenance: setiap dies punya "siklus" stroke berjalan (running counter) sejak preventive
terakhir (atau sejak instalasi kalau belum pernah), dibandingkan ke threshold 30.000, dengan
status visual, tombol input preventive (reset counter), dan riwayat history per dies.

**Control Stroke Mesin** (`maintenance.control-stroke-mesin`) **tidak** kena scope ini — tetap
seperti sekarang (total stroke tanpa threshold/preventive). Threshold/preventive ini murni
kebijakan perusahaan buat dies, bukan mesin.

## Scope

**Yang dibangun:**
- Tabel baru `dies_preventive_log` — riwayat setiap kejadian preventive per dies.
- `MaintenanceDiesDashboardService::computeCurrentCycle()` — ganti `computeStroke()` yang
  dipakai halaman ini (method `computeStroke()` di service ini SENDIRI boleh dihapus kalau
  tidak dipakai tempat lain — cek dulu; kalau masih dipakai controller/test lain, biarkan dan
  tambahkan method baru di sampingnya).
- Endpoint history per dies (AJAX, buat modal).
- Endpoint input preventive per dies (AJAX POST, buat modal, reset counter).
- View diupgrade total: filter tanggal dibuang, tabel dengan progress bar + status badge +
  aksi, modal input preventive, modal history.
- Role gate: input preventive cuma `admin` + `staff_maintenance` (manager view-only, tombol
  disabled).

**Yang TIDAK dibangun (out of scope):**
- Threshold per-dies (custom, beda-beda) — sama rata 30.000 buat semua dies, konstanta di kode.
- Notifikasi otomatis (email/WA/push) pas dies overdue — cuma visual di halaman.
- Edit/hapus log preventive yang udah diinput (kalau salah input, hubungi admin manual via DB
  — belum ada UI-nya).
- Perubahan apa pun ke Control Stroke Mesin.

## Data Model

### Tabel baru `dies_preventive_log`

| kolom | tipe | catatan |
|---|---|---|
| id | bigint PK | |
| id_part_proses | FK → `master_part_proses.id` | dies yang di-preventive |
| tanggal_preventive | date | tanggal preventive dilakukan, ≤ hari ini |
| id_karyawan | FK → `master_karyawan.id` | PIC/petugas yang melakukan preventive |
| stroke_at_preventive | integer | snapshot stroke yang tercapai saat preventive — **dihitung server**, bukan input user |
| catatan | text, nullable | catatan bebas |
| created_by | FK → `users.id` | user yang input record (bisa beda dari PIC karyawan) |
| timestamps + softDeletes | | pola project |

Index: `id_part_proses`, `tanggal_preventive`.

### Model `App\Models\DiesPreventiveLog`

`use SoftDeletes`. `belongsTo(MasterPartProses::class, 'id_part_proses')`,
`belongsTo(MasterKaryawan::class, 'id_karyawan')`, `belongsTo(User::class, 'created_by')`.

`MasterPartProses` tambah `hasMany(DiesPreventiveLog::class, 'id_part_proses')` (relasi
`preventiveLogs()`), dipakai buat query `MAX(tanggal_preventive)` per dies.

**Tidak ada kolom baru** di `master_part_proses` — status "kapan terakhir preventive" selalu
diturunkan dari `dies_preventive_log`, bukan disimpan redundan.

## Perhitungan: Running Cycle Stroke

`MaintenanceDiesDashboardService::computeCurrentCycle(?int $partId = null, ?string $customer = null): array`
(tidak ada parameter `$from`/`$to` lagi — running counter, bukan rentang tanggal).

Perhitungan stroke satu dies diekstrak jadi method publik tersendiri, dipakai baik oleh
`computeCurrentCycle()` (loop semua dies) maupun `storePreventive()` di controller (butuh
angka SATU dies sebelum reset):

```php
public function currentStrokeFor(MasterPartProses $pr): int
{
    $lastPreventive = DiesPreventiveLog::where('id_part_proses', $pr->id)->max('tanggal_preventive');
    $cycleStart     = $lastPreventive ?? $pr->part?->tanggal_instalasi;

    return (int) TransaksiProduksi::query()
        ->where('id_part_proses', $pr->id)
        ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
        ->sum('qty_aktual');
}
```

`computeCurrentCycle()` per dies (part+proses):

1. `current_stroke` = `$this->currentStrokeFor($pr)`.
2. `threshold` = konstanta `const STROKE_THRESHOLD = 30000;` di `MaintenanceDiesDashboardService`.
3. `pct` = `round($current_stroke / self::STROKE_THRESHOLD * 100, 1)`.
4. `status` = `$pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok')`.
5. `last_preventive_date`/`last_preventive_pic` diambil dari log preventive terakhir dies ini
   (query terpisah atau di-eager-load, detail teknis di plan — hindari N+1 dengan
   `with('preventiveLogs')` + ambil `first()` dari koleksi yang sudah di-sort, bukan query
   `max()` berulang per baris di loop).

**Listing scope:** tampilkan dies yang punya minimal 1 `TransaksiProduksi` record kapan pun
(bukan cuma rentang tanggal — sudah tidak ada filter tanggal). Ini penting supaya dies yang
baru di-reset ke 0 (stroke = 0 tapi baru aja dipreventive) tetap muncul di list, tidak hilang
dari monitoring. Query: `whereHas('transaksiProduksi')` pada `MasterPartProses` (perlu relasi
baru `hasMany(TransaksiProduksi::class, 'id_part_proses')` di `MasterPartProses` kalau belum
ada — cek dulu, kemungkinan besar belum ada karena arahnya sekarang cuma
`TransaksiProduksi belongsTo MasterPartProses`).

Output row shape:
```php
[
    'id_part_proses'         => int,
    'kode_mesin'              => string,   // part_no (nama key dipertahankan utk konsistensi FE lama — lihat Catatan Implementasi)
    'nama_mesin'              => string,   // part_name
    'nama_proses'             => string,
    'current_stroke'          => int,
    'threshold'                => int,      // 30000, konstan tapi tetap dikirim FE biar ga hardcode di JS
    'pct'                      => float,
    'status'                   => string,   // 'ok' | 'warning' | 'overdue'
    'last_preventive_date'     => ?string,  // 'Y-m-d' atau null
    'last_preventive_pic'      => ?string,  // nama_karyawan atau null
]
```

Sorting: urut status terburuk dulu — `overdue` → `warning` → `ok` — lalu `pct` descending
dalam grup yang sama. Dilakukan di service (server-side), bukan di JS, biar konsisten kalau
nanti dipakai tempat lain (mis. chatbot tool).

## Routes & Controller

Ditambahkan ke grup route Control Stroke Dies yang sudah ada
(`role:admin,staff_maintenance,manager`, prefix `maintenance`):

```php
Route::get('availability/control-stroke-dies/{partProses}/history', [ControlStrokeDiesController::class, 'history'])
    ->name('control-stroke-dies.history');
Route::post('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'storePreventive'])
    ->name('control-stroke-dies.preventive');
```

`{partProses}` route-model-binds ke `MasterPartProses`.

`ControlStrokeDiesController` perubahan:

```php
public function index()
{
    return view('maintenance.control-stroke-dies', [
        'partList'     => MasterPart::orderBy('part_no')->get(),
        'customers'    => MasterPart::whereNotNull('customer')->where('customer', '!=', '')->distinct()->orderBy('customer')->pluck('customer'),
        'karyawanList' => MasterKaryawan::aktif()->where('departemen', 'Maintenance')->orderBy('nama_karyawan')->get(),
    ]);
}

public function data(Request $request)
{
    $partId   = (int) $request->input('part_id', 0) ?: null;
    $customer = trim((string) $request->input('customer', '')) ?: null;
    $rows     = $this->svc->computeCurrentCycle($partId, $customer);

    return response()->json([
        'rows'    => $rows,
        'summary' => [
            'total_dies' => count($rows),
            'overdue'    => collect($rows)->where('status', 'overdue')->count(),
            'warning'    => collect($rows)->where('status', 'warning')->count(),
        ],
    ]);
}

public function history(MasterPartProses $partProses)
{
    $logs = $partProses->preventiveLogs()
        ->with(['karyawan:id,nama_karyawan', 'createdBy:id,name'])
        ->orderByDesc('tanggal_preventive')
        ->get();

    return response()->json([
        'logs' => $logs->map(fn ($l) => [
            'tanggal_preventive'   => $l->tanggal_preventive->format('Y-m-d'),
            'pic'                  => $l->karyawan?->nama_karyawan,
            'stroke_at_preventive' => $l->stroke_at_preventive,
            'catatan'              => $l->catatan,
            'input_by'             => $l->createdBy?->name,
        ]),
    ]);
}

public function storePreventive(MasterPartProses $partProses, Request $request)
{
    abort_unless(in_array(auth()->user()->role, ['admin', 'staff_maintenance']), 403);

    $validated = $request->validate([
        'tanggal_preventive' => 'required|date|before_or_equal:today',
        'id_karyawan'        => 'required|exists:master_karyawan,id',
        'catatan'            => 'nullable|string|max:1000',
    ]);

    $currentStroke = $this->svc->currentStrokeFor($partProses);

    DiesPreventiveLog::create([
        'id_part_proses'       => $partProses->id,
        'tanggal_preventive'   => $validated['tanggal_preventive'],
        'id_karyawan'          => $validated['id_karyawan'],
        'stroke_at_preventive' => $currentStroke,
        'catatan'              => $validated['catatan'] ?? null,
        'created_by'           => auth()->id(),
    ]);

    return response()->json(['ok' => true]);
}
```

`abort_unless` dipilih di dalam method (bukan middleware terpisah) karena route group yang
sama dipakai `index`/`data`/`history` (semua role boleh) dan `storePreventive` (cuma 2 role) —
menambah middleware group baru buat 1 route saja tidak sepadan.

## Views

`resources/views/maintenance/control-stroke-dies.blade.php` — **rewrite total** dari versi
sekarang:

**Filter form:** buang Tanggal Dari/Tanggal Sampai. Sisa: Customer (taField), Part No
(taField), tombol Tampilkan.

**Ringkasan (ganti KPI card lama):** 3 card kecil — Total Dies Dipantau
(`summary.total_dies`), Butuh Preventive Segera (`summary.overdue`, merah),
Mendekati Batas (`summary.warning`, kuning).

**Tabel utama:**
```
Part No | Nama Dies | Proses | Stroke Saat Ini | Progress | Status | Preventive Terakhir | Aksi
```
- Progress: `<div class="progress">` Bootstrap, lebar = `min(pct, 100)%`, warna kelas ikut
  status (`bg-success`/`bg-warning`/`bg-danger`), teks di dalam bar `{current}/{threshold}
  ({pct}%)`.
- Status badge: `OK` (hijau) / `Perlu Perhatian` (kuning) / `SEGERA PREVENTIVE` (merah, bold).
  Baris `overdue` dapat class tambahan (mis. `table-danger` tint) biar nonjol.
- Preventive Terakhir: `{last_preventive_date} — {last_preventive_pic}` atau `Belum pernah`.
- Aksi: tombol "Preventive" (icon `bi-tools`, buka modal input; `disabled` + `title` tooltip
  kalau role user bukan admin/staff_maintenance — cek role dari Blade `@auth`/`$role`, bukan
  cuma JS) + tombol "History" (icon `bi-clock-history`, buka modal history, semua role).

**Modal "Input Preventive"** (Bootstrap modal, per dies — id modal dinamis atau 1 modal
dipakai ulang dengan data di-inject via JS saat tombol diklik):
- Tanggal Preventive (`type=date`, default hari ini, `max` = hari ini)
- PIC (`<select>` dari `$karyawanList`, atau taField kalau daftar panjang — pakai `<select>`
  biasa dulu karena daftar staff maintenance biasanya pendek)
- Catatan (`<textarea>`, opsional)
- Preview teks: "Stroke akan direset dari **{current_stroke}** ke **0**" (di-update via JS pas
  modal dibuka, ambil dari data baris yang diklik)
- Tombol Submit → `POST` ke `route('maintenance.control-stroke-dies.preventive', $id)` →
  sukses → tutup modal → `loadData()` ulang.

**Modal "History"**: tabel `Tanggal | PIC | Stroke Tercapai | Catatan | Diinput Oleh`, diisi
dari `GET route('maintenance.control-stroke-dies.history', $id)` saat modal dibuka, urut
terbaru dulu. Kalau kosong: "Belum ada riwayat preventive."

**Sorting**: data dari backend sudah terurut (lihat bagian Perhitungan), FE tinggal render
apa adanya, tidak perlu sort ulang di JS.

**Tidak ada chart** (konsisten dengan keputusan sebelumnya — chart sudah dihapus dari kedua
halaman Control Stroke).

## Testing

phpunit:
- **`computeCurrentCycle()` (unit, service):**
  - Dies belum pernah preventive, ada `tanggal_instalasi` → cycle mulai dari instalasi.
  - Dies belum pernah preventive, `tanggal_instalasi` null → all-time dari transaksi pertama.
  - Dies pernah preventive → `current_stroke` cuma sum transaksi SETELAH `tanggal_preventive`
    terakhir (transaksi sebelum preventive tidak ikut kehitung).
  - 2 preventive log berturut-turut → yang dipakai `MAX(tanggal_preventive)`, bukan yang
    pertama diinput / bukan by id.
  - `pct`/`status` benar di 3 ambang: < 80 (`ok`), 80–99.99 (`warning`), ≥ 100 (`overdue`).
  - Dies tanpa transaksi sama sekali → tidak muncul di hasil (listing scope).
  - Dies dengan transaksi tapi stroke 0 (baru habis di-reset) → TETAP muncul (listing scope,
    beda dari `computeStroke()` lama yang skip `total===0`).
  - Sorting: overdue di atas warning di atas ok, dalam grup sama urut `pct` desc.
- **`storePreventive()` (feature):**
  - Admin/staff_maintenance bisa input, log tersimpan, `stroke_at_preventive` = current cycle
    stroke SEBELUM reset (snapshot benar, bukan 0).
  - Manager dapat 403.
  - `staff_produksi`/`staff_qc` dapat 403 (di luar role grup, sudah kegate middleware route).
  - Validasi: `tanggal_preventive` di masa depan ditolak; `id_karyawan` tidak ada ditolak.
  - Setelah preventive tersimpan, `computeCurrentCycle()` dies itu balik ke `current_stroke: 0`
    (verifikasi reset benar-benar berlaku di request berikutnya).
- **`history()` (feature):** return log terurut terbaru dulu, hanya milik dies yang diminta
  (dies lain tidak ikut kebawa), include nama PIC & nama user input.
- **Role gate `index()`/`data()`:** `staff_produksi`/`staff_qc` tetap 403 (sudah ada dari fase
  sebelumnya, tidak berubah).

## Catatan Implementasi

- Field JSON `kode_mesin`/`nama_mesin` pada row (isinya `part_no`/`part_name`) adalah
  penamaan yang salah sejak fase sebelumnya (lihat catatan di
  [2026-07-15-control-stroke-design.md](2026-07-15-control-stroke-design.md)) — **dipertahankan
  apa adanya** di spec ini untuk minimalkan perubahan, JANGAN di-rename di task ini (out of
  scope, bisa jadi task cleanup terpisah kalau user minta nanti).
- `computeStroke()` lama di `MaintenanceDiesDashboardService` — implementer cek dulu apakah
  masih dipakai (mis. oleh chatbot tool atau test lain) sebelum memutuskan hapus atau biarkan
  berdampingan dengan `computeCurrentCycle()`.
- `stroke_at_preventive` WAJIB dihitung server-side saat `storePreventive()`, TIDAK boleh
  terima nilai dari request/client — mencegah user memalsukan riwayat.
- Tidak ada migration yang mengubah tabel `transaksi_produksi` atau `master_part_proses` —
  semua data preventive baru murni di tabel baru `dies_preventive_log`.
