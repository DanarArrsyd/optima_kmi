# Schedule Preventive Maintenance — PDF Bulanan (Mesin & Dies)

## Konteks

Halaman Control Stroke (Mesin & Dies) sekarang murni reaktif: status
`overdue`/`warning`/`ok` dihitung live dari `MaintenanceDashboardService::computeCurrentCycle()`
/ `MaintenanceDiesDashboardService::computeCurrentCycle()`, tapi nol rencana distribusi kerja.
Kalau banyak mesin/dies nyampe warning bersamaan, tim Maintenance nggak mungkin
preventive semua sekaligus — sebelumnya dibagi manual per minggu (mis. 4 mesin/bulan
= 1 mesin/minggu). Atasan user minta dokumen SCHEDULE PREVENTIVE MAINTENANCE resmi,
formatnya sudah ada (lihat lampiran gambar di percakapan) — kalender sebulan penuh,
kolom PLAN/ACT per mesin/dies, kotak tanda tangan fisik buat ditempel dinding pabrik.

## Tujuan

Tombol baru di sebelah "Refresh" pada halaman Control Stroke Mesin & Dies yang
membuka/download PDF jadwal preventive bulan berjalan, mengikuti template fisik yang
sudah dipakai perusahaan.

## Kriteria masuk jadwal

- Cuma item berstatus `warning` atau `overdue` (dari `computeCurrentCycle()` yang sudah
  ada) — yang `ok` (jauh dari threshold) tidak kebagian slot bulan ini.
- Urutan prioritas: `pct` desc (paling mendesak duluan).

## Kapasitas & distribusi

- Mesin: **1 slot/minggu**. Dies: **5 slot/minggu**. Hardcode dulu (const di service),
  nol UI setting.
- Minggu dihitung dari hari kerja bulan itu (`KalenderKerjaService::isHariKerja()` —
  skip Minggu + `config('hari_libur')`), dikelompokkan per minggu kalender (ISO week
  number dalam bulan tsb).
- Tiap item dapat SATU `tanggal_rencana` (bukan rentang) — hari kerja pertama yang
  masih tersedia di minggu targetnya, urut isi minggu 1 dulu sampai penuh baru geser
  ke minggu 2, dst.
- Item yang nggak kebagian slot bulan ini (kapasitas habis) TIDAK dijadwalkan — bakal
  masuk pertimbangan generate bulan depan (evaluasi ulang dari status live saat itu,
  bukan carry-over otomatis).

## Generate & persistensi

**Tabel baru** (migrasi WAJIB didaftarkan `deploy.yml` — lihat CLAUDE.md):

`preventive_schedules`
- `bulan` (date, tanggal 1 bulan itu — mis. `2026-08-01`)
- `jenis` (enum/string `mesin`|`dies`)
- `nomor_dokumen` (string, format di bawah)
- `generated_at`, `generated_by` (nullable FK users — null kalau via cron)
- unique `(bulan, jenis)` — satu jadwal per bulan per jenis, generate ulang UPDATE bukan
  INSERT baru (hapus items lama, insert baru — snapshot terbaru menang)

`preventive_schedule_items`
- `schedule_id` FK
- `id_mesin` NULLABLE / `id_part_proses` NULLABLE (isi salah satu sesuai `jenis` induk)
- `stroke_snapshot`, `pct_snapshot`, `threshold_snapshot` (int) — nilai SAAT generate,
  bukan live (biar jadwal yg udah dicetak nggak berubah kalau stroke jalan terus)
- `tanggal_rencana` (date)
- `minggu_ke` (tinyint, 1-5) — buat query/urut, bukan sumber kebenaran tanggal

**Nomor dokumen**: `PM-MESIN/{nomor}/{bulan-romawi}/{tahun}` (mesin),
`PM-DIES/{nomor}/{bulan-romawi}/{tahun}` (dies). `{nomor}` reset tiap tahun, hitung
`max(nomor) + 1` dari schedule tahun berjalan — pola sama persis
`BreakdownMesinService::generateNoLkm()` (const ROMAN 1-12, reuse pola bukan reuse
kelasnya — beda domain).

**Generate otomatis**: scheduled command tanggal 1 tiap bulan (pola sama "Backup
Harian" yg udah ada), generate 2 jadwal terpisah (mesin, dies).

**Generate manual**: tombol "Generate Ulang" di halaman Control Stroke (admin +
staff_maintenance), buat jaga2 cron gagal atau butuh revisi. Timpa (bukan tambah)
jadwal bulan berjalan yg sama.

## PDF

Dependency baru: `barryvdh/laravel-dompdf`.

Layout ngikutin template gambar persis:
- Header: logo PT Kenco (asset yg udah ada di `public/`, cek dulu ada/nggak — kalau
  nggak ada, minta ke user, jangan bikin placeholder logo baru), judul "SCHEDULE
  PREVENTIVE MAINTENANCE", kotak Nomor Dokumen kanan atas.
- Tabel: NO | NAMA MESIN (atau NAMA PART/DIES) | ID MESIN (atau ID DIES) | STANDARD
  STROKE | ACTUAL STROKE | MASTER SCHED (kolom kalender 1 s.d. jumlah hari bulan itu,
  2 baris per item: PLAN & ACT).
- ACTUAL STROKE: angka LIVE dari `currentStrokeFor()` saat PDF di-generate (bukan
  snapshot tabel — nilai ini murni tampilan, beda dari `stroke_snapshot` yg dipakai
  penentuan jadwal).
- Kolom hari Minggu + `hari_libur` ditintain (background merah muda) di SEMUA baris —
  fakta kalender, bukan per-item.
- Baris PLAN: sel di `tanggal_rencana` item itu ditintain merah.
- Baris ACT: AUTO ditintain kalau ada `MesinPreventiveLog`/`DiesPreventiveLog` beneran
  tercatat di bulan itu buat item tsb (tanggal aslinya, bukan tanggal_rencana) — nol
  input manual, murni derived dari data yg udah ada. Kalau belum ada log bulan itu,
  baris ACT kosong semua.
- Footer: 6 poin ketentuan preventive (teks statis dari template, tempel apa adanya),
  kotak tanda tangan (DIBUAT/MENGETAHUI ×5 departemen/DIPERIKSA/MENYETUJUI ×2) — SEMUA
  KOSONG (buat tanda tangan fisik), nol data terisi otomatis.
- "Printed on OPTIMA, at {timestamp}" pojok kiri bawah (pola sama footer PDF/print
  existing kalau ada — cek dulu).

## Route & UI

- `GET /maintenance/control-stroke-mesin/schedule-pdf` → download/stream PDF bulan
  berjalan (generate on-the-fly dari data tabel yg udah ada, BUKAN generate ulang
  jadwalnya — kalau belum ada jadwal bulan ini, render pesan "belum ada jadwal,
  klik Generate Ulang" bukan error 500).
- `GET /maintenance/control-stroke-dies/schedule-pdf` — sama, buat dies.
- `POST /maintenance/control-stroke-mesin/schedule-generate` — manual regenerate
  (admin + staff_maintenance).
- `POST /maintenance/control-stroke-dies/schedule-generate` — sama.
- Tombol "PDF" + tombol "Generate Ulang" di sebelah tombol Refresh existing, kedua
  halaman.

## Di luar cakupan

- Kapasitas per minggu TIDAK bisa diatur dari UI (hardcode).
- Item yg nggak kebagian slot TIDAK auto-carry ke bulan depan sebagai prioritas —
  evaluasi ulang murni dari status live tiap generate.
- Nol pengiriman PDF otomatis (email/WA) — cuma tombol download/view manual.
- Nol perubahan di `computeCurrentCycle()`/threshold/status yg udah ada — fitur ini
  cuma KONSUMEN data itu.
