# Schedule Preventive — Proyeksi Cerdas + Restriksi Hari

## Konteks

`PreventiveScheduleService::generate()` sekarang murni REAKTIF: cuma mesin/dies yang
SUDAH warning/overdue (pct≥80%) SAAT generate yang masuk jadwal, diurut pct desc, dan
tanggal PLAN-nya selalu "hari kerja pertama" di jatah minggunya — kalau minggu itu
cuma punya 1 hari kerja (mis. akhir bulan yang jatuh Sabtu), PLAN jatuh ke Sabtu (5
jam kerja, kependekan buat PM penuh). Karena kapasitas mesin cuma 1/minggu dan slot
selalu ambil index-0, PLAN nyaris selalu jatuh Senin — monoton.

## Tujuan

1. Proyeksi KAPAN sebuah mesin/dies bakal overdue (bukan cuma status SEKARANG),
   supaya yang bakal overdue di tengah bulan (sebelum generate berikutnya) ikut
   kejadwal proaktif — bukan nunggu udah kepepet baru masuk radar bulan depannya.
2. Restriksi hari: PM mesin cuma Senin-Kamis, PM dies Senin-Jumat — nol Jumat/Sabtu
   buat mesin (jam kerja pendek), nol Sabtu buat dies.
3. Rotasi hari per minggu supaya PLAN nggak monoton di hari yang sama terus.

## Bagian 1 — Proyeksi tanggal overdue

**Laju pemakaian** — rata-rata stroke/hari, dihitung dari SUM produksi 30 hari
terakhir (bukan sejak preventive terakhir — cycle baru datanya dikit/ngaco). SATU
query batch per jenis (`GROUP BY id_mesin`/`id_part_proses`), bukan N+1 — dies bisa
ratusan item.

**Tanggal proyeksi** per item:
- `pct ≥ 100` (overdue sekarang) → proyeksi = HARI INI, apapun lajunya.
- `laju > 0` → proyeksi = HARI INI + ceil((threshold − current_stroke) / laju) hari.
- `laju = 0` (nol produksi 30 hari) TAPI `pct ≥ 80` → proyeksi = HARI INI (safety
  net — nggak bisa diproyeksi tapi udah di zona warning).
- Selain itu (laju=0 DAN pct<80) → nol proyeksi (`null`), nol eligible via jalur ini.

**Kriteria masuk jadwal** (union, GANTI filter lama yang cuma `pct≥80`):
- `pct ≥ 80` (perilaku lama, tetap) ATAU
- proyeksi overdue SEBELUM tanggal 1 bulan depan (generate berikutnya).

**Urutan prioritas** — proyeksi paling DEKAT duluan (ascending), ganti dari pct desc.
Item overdue-sekarang otomatis paling depan (proyeksi=hari ini).

## Bagian 2 — Restriksi hari kerja per jenis

```
mesin: Senin–Kamis (isoWeekday 1–4)
dies:  Senin–Jumat (isoWeekday 1–5)
```

Diterapkan SETELAH filter Minggu+`hari_libur` yang sudah ada (tambahan, bukan
pengganti) — mesin/dies dua-duanya tetap nol Minggu/hari libur nasional.

## Bagian 3 — Rotasi hari (nol monoton)

Dalam satu minggu bucket, posisi slot yang diambil dimulai dari offset yang BERPUTAR
per minggu (`mingguKe % jumlah hari diizinkan minggu itu`), bukan selalu index-0.
Mesin (kapasitas 1, s.d. 4 kandidat hari) paling kerasa efeknya — minggu 1 dapet
Senin, minggu 2 Selasa, minggu 3 Rabu, minggu 4 Kamis, minggu 5 puter balik ke Senin.
Dies (kapasitas 5, s.d. 5 kandidat hari) nyaris selalu makai semua hari yang ada,
rotasi nol banyak ngaruh di situ — itu wajar, bukan bug.

Kapasitas yang nggak kebagian hari (minggu itu kandidatnya lebih sedikit dari
kapasitas) tetap HANGUS seperti sebelumnya — nol berubah.

## Di luar cakupan

- Nol perubahan tampilan PDF/UI — proyeksi & rotasi murni logika internal penentuan
  jadwal, nol kolom baru yang nampilin angka proyeksi/laju.
- Nol perubahan `computeCurrentCycle()`/threshold/status di
  `MaintenanceDashboardService`/`MaintenanceDiesDashboardService` — fitur ini cuma
  KONSUMEN data itu, ditambah satu query baru (laju) yang berdiri sendiri.
- Nol UI setting buat window laju (30 hari) atau daftar hari diizinkan — hardcode,
  sama seperti kapasitas mingguan sebelumnya.
- Test lama (`PreventiveScheduleServiceTest`) yang nggak nge-pin `Carbon::setTestNow()`
  WAJIB ditinjau ulang — logika baru bergantung waktu (window 30 hari relatif ke
  "sekarang"), fixture yang nulis tanggal produksi hardcode tanpa `setTestNow` bakal
  jadi rapuh (lolos/gagal tergantung kapan suite dijalankan).
