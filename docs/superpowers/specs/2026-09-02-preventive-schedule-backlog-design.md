# Schedule Preventive — Backlog Persisten (Fase 2, scope: backlog doang)

## Konteks

Fase 1 (live) sudah bikin `generate()` prediktif, tapi item yang KEHABISAN slot
(eligible tapi kapasitas minggu-minggu bulan itu udah penuh) sekarang cuma HILANG —
`generate()` selalu `$schedule->items()->delete()` lalu rebuild dari nol tiap kali
dipanggil, jadi item yang gak kebagian slot nggak pernah jadi baris sama sekali. Nol
jejak "item ini udah nunggu berapa lama", nol cara tau ada backlog tanpa baca kode/DB
manual.

## Tujuan

Item yang eligible tapi kehabisan slot tetap TERCATAT sebagai baris backlog
(`tanggal_rencana = null`), dengan `backlog_since` yang PERSISTEN — kalau item itu
masih backlog di generate bulan berikutnya, `backlog_since` tetap tanggal PERTAMA dia
jadi backlog, bukan di-reset tiap generate.

## Di luar cakupan (fase 2 lanjutan, BUKAN sekarang)

- Revisi immutable (generate ulang MASIH timpa, sama seperti sekarang — cuma item
  backlog-nya yang sekarang persisten lewat `backlog_since`).
- Lock concurrency.
- Notifikasi bell.
- PDF resmi tersimpan vs PDF live terpisah.
- UI baru (KPI card, halaman backlog, dst) — backlog cuma tercatat di DB fase ini,
  nol tampilan baru. KPI strip Control Stroke yang ada SEKARANG bersumber dari status
  stroke LIVE (`computeCurrentCycle()`), BUKAN dari tabel `preventive_schedule_items`
  — nol disentuh, nol ditambah kolom baru di situ.
- PDF Schedule Preventive yang ada SEKARANG tetap nampilin item TERJADWAL doang
  (`tanggal_rencana` non-null) — baris backlog (`tanggal_rencana = null`) SENGAJA nol
  muncul di tabel kalender PDF (nol ada kolom tanggal buat digambar). Nol perubahan
  view PDF di fase ini.

## Skema

Migrasi ALTER `preventive_schedule_items` (kolom baru, additive):
- `tanggal_rencana` — dari NOT NULL jadi NULLABLE (backlog = item tanpa tanggal).
- `minggu_ke` — dari NOT NULL jadi NULLABLE (senada, backlog nol ada minggu).
- `backlog_since` (date, nullable) — tanggal PERTAMA kali item ini jadi backlog.
  `null` kalau item ini nggak lagi backlog (baik karena kebagian slot ATAU karena
  udah nggak eligible lagi).

## Logika `generate()`

⚠️ **`backlog_since` WAJIB nyambung LINTAS BULAN** — kasus nyata yang harus benar:
dies X backlog di jadwal Agustus, generate September (`generate('dies', 2026-09-01)`)
harus TETAP baca `backlog_since` dari Agustus, bukan reset ke tanggal generate
September. Sumber "baris lama" buat REKAM `backlog_since` beda tergantung situasi:

- **Regenerate bulan yang SAMA** (`$schedule->exists` true sebelum disentuh) → sumbernya
  baris LAMA milik `$schedule` itu sendiri (yang sebentar lagi dihapus & dibangun
  ulang).
- **Generate bulan BARU pertama kali** (`$schedule->exists` false) → `$schedule` itu
  sendiri belum punya baris apa pun (baru dibuat). Sumbernya jadwal jenis yang SAMA di
  bulan SEBELUM `$bulan` yang PALING BARU (`PreventiveSchedule::where('jenis',
  $jenis)->where('bulan', '<', $bulan)->orderByDesc('bulan')->first()`) — kalau ada,
  ambil baris backlog-nya. Kalau nggak ada (bulan pertama fitur ini jalan, atau jenis
  itu belum pernah digenerate), nol sumber — semua backlog baru dianggap baru.

Sebelum `$schedule->items()->delete()` (hapus baris lama punya `$schedule` sendiri —
cuma relevan buat kasus regenerate, no-op kalau baru), REKAM dulu `backlog_since`
existing dari SUMBER yang tepat di atas (baris backlog LAMA, `tanggal_rencana IS
NULL`), di-keyed per target (`id_mesin`/`id_part_proses`) — buat referensi carry-over.

Alur assignment TIDAK berubah (proyeksi+prioritas fase 1, restriksi hari+rotasi fase
1, semua utuh) — yang berubah cuma APA YANG TERJADI ke item yang nggak kebagian slot
saat loop assignment selesai (`$idx < $eligible->count()`):

- SEBELUMNYA: item sisa (index `$idx` sampai akhir `$eligible`) nggak pernah jadi
  baris — cukup log (fase 1, tetap ada).
  SEKARANG: item sisa itu TETAP dibikin baris (`tanggal_rencana = null`,
  `minggu_ke = null`), dengan `backlog_since`:
  - Kalau target itu ADA di map backlog lama (dari REKAM di atas) → pakai
    `backlog_since` LAMA (persisten, jangan reset).
  - Kalau BARU pertama kali jadi backlog (nggak ada di map lama) → `backlog_since`
    = tanggal generate SEKARANG (`$bulan` atau hari generate berjalan — pakai
    `now()->timezone('Asia/Jakarta')->toDateString()`, konsisten sama cara fase 1
    nentuin "hari ini").

Item yang KEBAGIAN slot (dapet `tanggal_rencana`) SELALU `backlog_since = null` —
walaupun bulan lalu dia backlog, begitu kebagian slot statusnya bersih, bukan
"backlog yang keselesain". (Item yang eligible-lagi-dan-backlog-lagi BULAN DEPAN
setelah sempat kebagian slot dianggap backlog BARU, `backlog_since` reset ke
generate itu — bukan nyambung ke riwayat backlog sebelum dia sempat kebagian slot.
Ini penyederhanaan sengaja: "backlog_since" berarti "sejak kapan TERUS-MENERUS jadi
backlog", bukan riwayat lifetime penuh.)

## Query PDF & controller — WAJIB filter backlog keluar

`ControlStrokeMesinController::schedulePdf()` /
`ControlStrokeDiesController::schedulePdf()` yang query `$schedule->items` buat
dikirim ke view PDF WAJIB nambah `->whereNotNull('tanggal_rencana')` (atau setara)
supaya baris backlog nggak ikut nyoba digambar di tabel kalender PDF (yang butuh
`tanggal_rencana` buat nentuin kolom PLAN-nya) — kalau nggak difilter, baris backlog
bakal null-pointer atau nongol di kolom yang salah pas render.

## Regresi test lama

Udah dicek manual: SEMUA 12 fixture test `PreventiveScheduleServiceTest` yang ada
sengaja dibikin muat pas di kapasitas bulan itu (nol pernah nyoba bikin item eligible
LEBIH BANYAK dari total slot sebulan) — jadi NOL satu pun yang bakal mengalami jalur
backlog. 12 test itu WAJIB tetep lolos TANPA diubah. Test baru buat backlog ditulis
terpisah (lihat plan).
