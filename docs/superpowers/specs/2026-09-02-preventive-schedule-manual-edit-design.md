# Schedule Preventive — Edit Manual Tanggal PLAN

## Konteks

`PreventiveScheduleService::generate()` (fase 1: proyeksi+rotasi, fase 2: backlog
persisten) sepenuhnya algoritmik — tiap generate/regenerate, `$schedule->items()->delete()`
lalu bangun ulang dari nol. Admin nggak punya cara ubah tanggal PLAN satu item secara
manual (mis. geser dari tanggal 1 ke tanggal 2 karena hari libur lokal/event yang nggak
tercatat di `config/hari_libur.php`, atau alasan operasional lain) — satu-satunya kontrol
admin sekarang cuma generate/regenerate seluruh bulan lewat algoritma.

## Tujuan

Admin (dan `staff_maintenance`) bisa klik cell tanggal PLAN di halaman Control Stroke
Mesin/Dies, pilih tanggal lain (dibatasi tanggal valid), simpan — item itu "terkunci"
manual dan SELAMAT dari regenerate algoritma berikutnya. Baris backlog (fase 2, nol
tanggal) juga bisa langsung di-assign tanggal lewat mekanisme yang sama, jadi keluar
dari backlog. Admin bisa "reset ke otomatis" balik kalau mau algoritma pegang lagi.

## Skema

Migrasi ALTER `preventive_schedule_items` (kolom baru, additive):
- `is_manual_override` (boolean, default `false`) — `true` = item ini dikunci manual,
  dikecualikan dari delete+rebuild `generate()`.
- `overridden_by` (`foreignId` nullable, `users.id`) — siapa yang terakhir override.
- `overridden_at` (`timestamp` nullable) — kapan terakhir di-override.

Kolom ini independen dari `backlog_since` (fase 2) — keduanya bisa berubah bareng saat
admin assign tanggal ke baris backlog (backlog_since jadi null, is_manual_override jadi
true, dalam satu request yang sama).

## Endpoint baru

Dua endpoint per jenis (mesin & dies), route-gated `admin` + `staff_maintenance` (pola
sama `scheduleGenerate()` existing — `abort_if(! auth()->user()->hasRole([...]), 403)`):

1. **`PATCH /availability/control-stroke-{jenis}/schedule-item/{item}`** — body
   `{ tanggal_rencana: 'YYYY-MM-DD' }`. Validasi WAJIB (422 kalau gagal):
   - `$item->schedule->jenis === $jenis` route (mesin item nggak bisa di-PATCH lewat
     endpoint dies, dan sebaliknya).
   - Tanggal masuk bulan yang sama dengan `$item->schedule->bulan` (nggak bisa geser ke
     bulan lain — beda schedule, beda dokumen).
   - `KalenderKerjaService::hariKerja($tanggal)` true (bukan Minggu/hari_libur).
   - `isoWeekday($tanggal)` masuk `PreventiveScheduleService::HARI_DIIZINKAN[$jenis]`
     (mesin Senin-Kamis, dies Senin-Jumat) — konstanta itu di-expose jadi `public const`
     atau lewat method getter kecil, dipakai bareng backend+validasi, bukan diduplikasi.
   - **Kapasitas mingguan NOL ditegakin** (keputusan sengaja) — admin bisa numpuk lebih
     dari kapasitas nominal (1/minggu mesin, 5/minggu dies) di satu minggu kalau itu yang
     dia mau. Sukses: set `tanggal_rencana`, hitung ulang `minggu_ke` (posisi ISO-week
     relatif dalam bulan itu, pola sama `kelompokkanPerMinggu()`), `is_manual_override =
     true`, `overridden_by/overridden_at` diisi, `backlog_since = null` (kalau item ini
     tadinya backlog).

2. **`POST /availability/control-stroke-{jenis}/schedule-item/{item}/reset`** — nol body.
   Set `is_manual_override = false`, `tanggal_rencana = null`, `minggu_ke = null`,
   `overridden_by/overridden_at = null`. Item balik jadi kandidat backlog/algoritma di
   generate berikutnya — backlog_since diisi `now()` (baru pertama kali backlog lagi,
   BUKAN carry-over dari backlog_since sebelum item ini sempat di-override — override
   dianggap "reset bersih", sama semangatnya dengan aturan fase 2 "backlog_since reset
   kalau sempat kebagian slot lalu backlog lagi").

Kedua endpoint pakai activitylog (`spatie/laravel-activitylog`, sudah dipakai fitur lain
di proyek ini): log properti `dari_tanggal`/`ke_tanggal` (PATCH) atau `tanggal_direset`
(reset), subject `PreventiveScheduleItem`, causer user yang login.

## `generate()` — kompatibilitas dengan item locked

Satu baris berubah di `PreventiveScheduleService::generate()`:

```php
$schedule->items()->delete();
```

jadi:

```php
$schedule->items()->where('is_manual_override', false)->delete();
```

Item locked SELAMAT dari rebuild. `eligibleItems()` (penentu SIAPA yang eligible bulan
ini) TIDAK berubah — kalau target (mesin/part_proses) item locked itu masih eligible,
dia tetap muncul di `$eligible` collection algoritma. Ini menciptakan potensi DUPLIKAT:
satu target bisa punya 2 baris (1 locked manual, 1 hasil algoritma) kalau target itu
masih eligible pas regenerate.

**Keputusan sengaja**: item locked DIKECUALIKAN dari `$eligible` sebelum loop assignment
jalan, biar nggak dobel. Filter tambahan di `eligibleItems()` (atau caller-nya):

```php
$idTargetLocked = $schedule->items()->where('is_manual_override', true)->pluck($kolomId)->all();
$eligible = $eligible->reject(fn ($row) => in_array($row[$kolomId], $idTargetLocked, true));
```

Kapasitas minggu (Q4 keputusan brainstorming: opsi B) NOL dikurangi oleh item locked —
algoritma isi kapasitas penuh dari 0 di tiap minggu kayak biasa, locked item dianggap
"nempel" independen di luar akuntansi algoritma. Potensi numpuk lebih dari kapasitas
nominal di minggu yang kebetulan sama dengan minggu locked — disengaja, konsisten sama
keputusan kapasitas nggak ditegakin ketat pas ada override manual.

## Frontend

Komponen Alpine.js kecil baru (ikut konvensi 11 komponen bersama existing, didaftarkan
di layout, dijaga test yang sama polanya dengan `KomponenBersamaTest`) — sebut
`scheduleItemEdit`. Per cell PLAN yang di-generate blade (bukan PDF — PDF nol berubah,
lihat "Di luar cakupan"):

- Klik cell tanggal (halaman Control Stroke, BUKAN PDF) → dropdown/date-picker muncul
  in-place, isinya CUMA tanggal valid (hari kerja + HARI_DIIZINKAN[jenis], dalam bulan
  schedule) — tanggal lain didisable, nggak bisa diklik sama sekali (klien-side gate
  sesuai keputusan brainstorming Q2, backend tetap validasi ulang server-side sebagai
  pertahanan kedua — pola defense-in-depth standar, bukan percaya klien doang).
- Pilih tanggal → `fetch PATCH` endpoint di atas → sukses: cell update in-place + badge
  kecil (ikon kunci) muncul nandain item ini locked manual. Gagal (422/403): pesan error
  inline, cell nggak berubah.
- Cell/baris yang locked dapet tombol kecil "Reset ke Otomatis" → `fetch POST` endpoint
  reset → cell balik kosong (backlog) atau nunggu regenerate berikutnya isi ulang.
- Baris backlog (nol tanggal, dari fase 2) — cell PLAN-nya kosong tapi TETEP clickable,
  buka dropdown yang sama, assign tanggal = keluar dari backlog + jadi locked.

## Otorisasi

Sama persis `scheduleGenerate()` existing: `admin` + `staff_maintenance`. `guest` dan
role lain 403 (halaman Control Stroke sendiri boleh dilihat guest read-only, tapi cell
PLAN nol interaktif buat mereka — Alpine component cek role dari data yang udah
di-passing ke Blade, backend tetap jadi gerbang final via `abort_if`).

## Di luar cakupan

- **PDF nol berubah** — `preventive-schedule-pdf.blade.php` tetap baca `tanggal_rencana`
  apa adanya, nol perlu tau item itu locked manual atau hasil algoritma. Badge kunci CUMA
  di halaman Control Stroke, nggak muncul di PDF.
- **Concurrency lock** — 2 admin edit item yang sama nyaris bersamaan: last-write-wins,
  nggak ada mekanisme lock/conflict-detection. Resiko dianggap kecil (tim kecil, 1
  departemen maintenance).
- **Notifikasi bell** — nol notif ke siapa pun soal perubahan tanggal manual.
- **Concurrency lock buat regenerate vs edit manual** — kalau admin lagi buka dropdown
  edit item X, lalu ADMIN LAIN klik "Generate ulang" di tab lain sebelum admin pertama
  save — regenerate nggak nunggu, jalan normal (item X yang belum di-PATCH bukan locked,
  bisa ikut kehapus+rebuild). Race window kecil, diterima sebagai resiko fase ini.
- **Bulk edit / drag-multi-select** — satu cell satu waktu, nol select-multiple-lalu-geser-bareng.
- **Validasi "kapasitas mingguan"** — sengaja nol ditegakin (lihat bagian generate()).

## Regresi fase 1 & fase 2

- 15 test lama `PreventiveScheduleServiceTest` (12 fase 1 + 3 fase 2 backlog) WAJIB tetap
  lolos tanpa diubah — perubahan `generate()` (filter delete + filter eligible) harus
  backward-compatible: kalau NOL ada item locked (kasus semua test lama), perilaku identik
  sama sebelumnya (`where('is_manual_override', false)` match semua baris karena kolom
  baru default `false`; filter reject di `eligibleItems()` no-op karena `$idTargetLocked`
  kosong).
- Test baru dibutuhkan: PATCH sukses, PATCH tanggal invalid (luar bulan/hari
  libur/HARI_DIIZINKAN) → 422, reset-ke-otomatis, `generate()` skip item locked dari
  delete, `generate()` exclude target locked dari `$eligible` (nol duplikat), assign
  tanggal ke baris backlog (`backlog_since` jadi null + `is_manual_override` jadi true),
  guest/staff_produksi/staff_qc 403 di kedua endpoint, activitylog kecatet buat PATCH dan
  reset.
