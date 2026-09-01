# Preventive Schedule — Proyeksi Overdue, Rotasi Hari, Backlog, dan Revisi

## Status

Desain disetujui pada 1 September 2026. Dokumen ini merevisi aturan penjadwalan
preventive bulanan yang sebelumnya hanya memilih item berstatus `warning` atau
`overdue` lalu mengisi slot dari awal bulan.

## Konteks

Fitur Schedule Preventive Maintenance yang sudah berjalan menghasilkan jadwal
bulanan mesin dan dies dari kondisi stroke saat generate. Pendekatan tersebut belum
memperhitungkan laju produksi, sehingga aset yang masih di bawah 80% tetapi akan
melewati threshold sebelum jadwal bulan berikutnya dapat terlewat. Alokasi hari juga
cenderung memakai hari pertama dalam minggu, dan generate ulang menimpa snapshot
lama sehingga dokumen yang sudah dicetak tidak dapat diaudit sebagai revisi terpisah.

Perubahan ini membuat scheduler bersifat prediktif, membatasi hari preventive per
jenis aset, merotasi hari, mempertahankan backlog, dan menyimpan setiap hasil generate
sebagai revisi immutable.

## Tujuan

- Memproyeksikan tanggal overdue dari laju stroke 30 hari terakhir.
- Menjadwalkan aset sebelum proyeksi overdue jika kapasitas memungkinkan.
- Tetap memberi slot terlambat ketika deadline sudah lewat atau slot aman penuh.
- Tidak menghilangkan aset saat kapasitas bulan habis.
- Menghindari pola preventive mesin yang selalu jatuh pada Senin.
- Menyimpan revisi dokumen resmi yang dapat direproduksi dan diaudit.
- Memberi peringatan ringkas untuk item terlambat dan backlog.

## Di luar cakupan

- Pengaturan kapasitas atau hari preventive melalui UI.
- Approval digital berlapis. Approval resmi tetap memakai tanda tangan fisik.
- Email atau WhatsApp.
- Perubahan rumus current cycle dan threshold yang sudah digunakan halaman Control
  Stroke.
- Machine learning atau peramalan selain rata-rata stroke 30 hari.

## Istilah

- **Tanggal generate (`T`)**: tanggal pabrik di zona waktu Asia/Jakarta.
- **Current stroke**: stroke sejak preventive terakhir, memakai rumus Control Stroke
  yang sudah ada.
- **Laju harian**: total stroke fisik pada 30 hari kalender selesai sebelum `T`,
  dibagi tetap dengan 30.
- **Proyeksi overdue**: tanggal perkiraan current stroke mencapai threshold.
- **Slot aman**: slot yang tanggalnya tidak melewati proyeksi overdue.
- **Backlog**: kandidat eligible yang tidak mendapat slot karena seluruh kapasitas
  bulan sudah habis.
- **Revisi aktif**: revisi yang menjadi sumber PLAN pada halaman dan PDF monitoring.

## Konfigurasi

Aturan disimpan dalam satu file config, tanpa UI. Nilai awal:

```php
return [
    'mesin' => [
        'capacity_per_week' => 1,
        'allowed_weekdays' => [1, 2, 3, 4], // Senin-Kamis
    ],
    'dies' => [
        'capacity_per_week' => 5,
        'allowed_weekdays' => [1, 2, 3, 4, 5], // Senin-Jumat
    ],
    'projection_window_days' => 30,
    'safety_horizon_days' => 7,
];
```

Setiap revisi menyimpan snapshot config yang dipakai. Perubahan config pada masa
depan tidak mengubah penjelasan atau hasil revisi lama.

## Sumber Data dan Kalkulasi

### Current stroke

Current stroke tetap memakai aturan domain yang sudah ada:

- Mesin dihitung per `id_mesin` sejak preventive mesin terakhir atau tanggal
  instalasi.
- Dies dihitung per `id_part_proses` sejak preventive dies terakhir atau tanggal
  instalasi part.
- Stroke fisik adalah `qty_aktual + qty_ng`.

Perubahan ini hanya mengonsumsi hasil current cycle; tidak mengubah threshold,
reset cycle, atau status Control Stroke.

### Window laju

Untuk generate pada tanggal `T`, window mencakup 30 tanggal kalender yang sudah
selesai, yaitu `T-30` sampai `T-1` secara inklusif. Hari `T` tidak masuk karena data
produksinya masih parsial. Window tetap melewati preventive terakhir karena laju
bertujuan memperkirakan pola beban produksi ke depan, bukan mengukur umur cycle.

Query harus berbentuk agregasi batch per seluruh mesin dan seluruh dies. Dilarang
menjalankan satu query laju per aset.

```text
stroke_30_days = SUM(qty_aktual + COALESCE(qty_ng, 0))
daily_rate     = stroke_30_days / 30
remaining      = MAX(threshold - current_stroke, 0)
```

Hari tanpa produksi tetap masuk denominator. Laju disimpan dengan presisi yang cukup
untuk menghindari perubahan tanggal akibat pembulatan tampilan.

### Tanggal proyeksi

- Jika `current_stroke >= threshold`, proyeksi adalah `T`.
- Jika `daily_rate > 0`, proyeksi adalah
  `T + ceil(remaining / daily_rate)` hari kalender.
- Jika `daily_rate = 0` dan `pct >= 80`, proyeksi adalah `T` sebagai safety net.
- Selain itu proyeksi `null`.

Semua perhitungan tanggal memakai tanggal pabrik Asia/Jakarta. Konfigurasi timezone
aplikasi tetap UTC; konversi dilakukan pada boundary service atau tampilan.

## Kriteria Eligible dan Horizon

Item eligible jika memenuhi salah satu kondisi:

- `pct >= 80`, atau
- tanggal proyeksi tidak melewati `tanggal generate bulan berikutnya + 7 hari`.

Contoh: generate 1 September memakai cutoff 8 Oktober. Kandidat yang diproyeksi
overdue pada 3 Oktober ditarik ke jadwal September agar tidak menunggu alokasi awal
Oktober.

Backlog bulan lama tidak otomatis dipertahankan bila hasil kalkulasi terbaru sudah
tidak eligible. Jika tetap eligible, `backlog_since` lama dipertahankan. Preventive
aktual setelah backlog terbentuk menyelesaikan carry backlog tersebut.

## Prioritas

Urutan deterministik:

1. Backlog urgent dari bulan sebelumnya.
2. Aset yang sudah overdue pada `T`.
3. Tanggal proyeksi paling dekat.
4. `backlog_since` paling lama.
5. `pct` paling tinggi.
6. ID aset sebagai tie-breaker stabil.

Satu aset hanya boleh menghasilkan satu item pada satu revisi.

## Pembentukan Slot dan Rotasi

Hari Minggu dan tanggal dalam `config('hari_libur')` selalu dikeluarkan. Hari Sabtu
juga tidak diizinkan karena tidak ada dalam daftar weekday kedua jenis.

### Mesin

- Hari canonical: Senin, Selasa, Rabu, Kamis.
- Kapasitas maksimal satu mesin per minggu kalender.
- Rotasi zero-based: minggu relatif pertama menargetkan Senin, kedua Selasa, ketiga
  Rabu, keempat Kamis, lalu mengulang.
- Jika target berada di luar bulan atau hari libur, pencarian bergeser ke hari valid
  berikutnya secara melingkar dalam minggu yang sama.

### Dies

- Hari canonical: Senin sampai Jumat.
- Kapasitas maksimal lima dies per minggu kalender.
- Maksimal satu dies per tanggal.
- Urutan tanggal memakai rotasi yang sama. Pada minggu normal lima slot akan
  tersebar satu per hari.

Minggu parsial atau minggu dengan hari libur mempunyai kapasitas efektif sebesar
jumlah hari validnya. Kapasitas yang hilang tidak dipindahkan ke minggu lain.

## Alokasi Tanggal

Allocator memproses kandidat sesuai prioritas:

- Deadline masa depan: pilih slot kosong terakhir yang tidak melewati proyeksi.
- Jika tidak ada slot sebelum deadline tetapi masih ada slot setelahnya: pilih slot
  kosong pertama sesudah deadline dan beri status `late`.
- Jika deadline adalah `T` atau sudah lewat: pilih slot valid kosong paling cepat dan
  beri status `late`.
- Jika seluruh slot bulan habis: item tetap disimpan sebagai backlog tanpa
  `planned_date`.

Kandidat dalam safety horizon awal bulan berikutnya tetap mendapat tanggal dalam
bulan dokumen berjalan.

## Status Item

- `scheduled`: mempunyai PLAN dan PLAN tidak melewati proyeksi.
- `late`: mempunyai PLAN, tetapi PLAN melewati proyeksi atau aset sudah due saat
  generate.
- `backlog`: tidak mempunyai PLAN dan deadline masih di masa depan.
- `backlog_urgent`: tidak mempunyai PLAN serta sudah due/overdue atau merupakan
  backlog lama yang tetap eligible.

Completion tidak disimpan sebagai status mutable pada item revisi. Completion
diturunkan dari preventive log agar tidak muncul dua sumber kebenaran.

## Model Data

### `preventive_schedules`

Satu dokumen per kombinasi bulan dan jenis.

- `bulan`
- `jenis` (`mesin` atau `dies`)
- `nomor_dokumen`
- `active_revision_id` nullable
- timestamps dan soft deletes
- unique `(bulan, jenis)`

### `preventive_schedule_revisions`

- `schedule_id`
- `revision_no`, dimulai dari 0
- `calculation_hash`
- `config_snapshot` JSON
- `generated_at`, `generated_by`
- `revision_reason` nullable untuk Rev. 00 dan wajib untuk revisi manual berikutnya
- ringkasan jumlah `scheduled`, `late`, `backlog`, `backlog_urgent`
- metadata PDF resmi: private path, SHA-256, ukuran, waktu terbit
- timestamps dan soft deletes
- unique `(schedule_id, revision_no)`

`active_revision_id` adalah satu-satunya penanda revisi aktif. Revision row tidak
memiliki flag aktif terpisah.

### `preventive_schedule_items`

- `revision_id`
- `id_mesin` nullable
- `id_part_proses` nullable
- snapshot identity: jenis, kode, nama, ID proses, nama proses
- `current_stroke_snapshot`
- `threshold_snapshot`
- `pct_snapshot`
- `stroke_30_days_snapshot`
- `daily_rate_snapshot`
- `projected_overdue_at` nullable
- `planned_date` nullable
- `allocation_status`
- `eligibility_reason`
- `backlog_since` nullable
- `priority_rank`
- timestamps dan soft deletes

Tepat salah satu dari `id_mesin` dan `id_part_proses` harus terisi sesuai jenis
schedule. Snapshot identity menjadi fallback jika master diubah atau dihapus.

## Revisi dan Immutability

### Generate otomatis

Cron tanggal 1 memperoleh lock berdasarkan jenis dan bulan. Jika dokumen belum ada,
cron membuat Rev. 00. Jika dokumen bulan tersebut sudah ada, cron selesai tanpa
membuat revisi. Kegagalan mesin tidak membatalkan percobaan dies, dan sebaliknya.

### Generate manual

Jika jadwal belum ada, UI menampilkan **Generate Jadwal**. Jika sudah ada, tombol
berubah menjadi **Buat Revisi** dan menampilkan nomor, revisi aktif, pembuat, serta
waktu generate.

Generate ulang selalu melalui halaman preview:

1. Server menghitung kandidat dan alokasi tanpa menulis revisi.
2. UI menampilkan item added, removed, moved, perubahan status, serta perubahan
   proyeksi/laju yang berarti.
3. Jika hash identik, proses berhenti dengan pesan **Tidak ada perubahan**.
4. Jika berbeda, user mengisi alasan revisi.
5. Konfirmasi membawa expected active revision dan hash preview.
6. Server memperoleh lock dan menghitung ulang. Preview basi ditolak.
7. Revision, items, dan PDF resmi dibuat lengkap.
8. `active_revision_id` diganti secara atomic hanya setelah seluruh hasil siap.

Revisi gagal tidak boleh mengganti revisi aktif. Revisi yang sudah aktif tidak boleh
diubah. Generate identik tidak membuat revision atau notification.

### Hash

`calculation_hash` dibuat dari representasi canonical config snapshot, kandidat,
snapshot kalkulasi, prioritas, tanggal alokasi, dan status. Timestamp, actor,
revision reason, serta ACT live tidak masuk hash.

## Concurrency dan Idempotensi

- Lock lintas request dipakai per `jenis + bulan`.
- Unique `(schedule_id, revision_no)` menjadi pertahanan DB kedua.
- Konfirmasi membawa ID revisi aktif yang dilihat saat preview.
- Double-click hanya boleh menghasilkan satu revisi.
- Lock sibuk menghasilkan pesan ramah, bukan error 500.
- Aktivasi dan pointer revision memakai transaction.

## PDF

### PDF resmi revisi

- Dibuat saat revisi diaktifkan dan disimpan pada disk private.
- Akses hanya lewat route role-gated.
- Isi memakai snapshot revisi, termasuk PLAN, config, identity, stroke, laju,
  proyeksi, status, dan ACT yang sudah ada saat penerbitan.
- SHA-256 dan ukuran file disimpan.
- File revisi lama tidak pernah dirender ulang atau diganti.
- Kegagalan render atau penyimpanan menggagalkan aktivasi revisi.

### PDF monitoring live

- Dirender on demand dari PLAN revisi aktif.
- ACT dan completion dibaca dari seluruh preventive log yang relevan pada bulan itu.
- Semua tanggal ACT ditandai, bukan hanya log pertama.
- Header wajib menyatakan **MONITORING LIVE — BUKAN DOKUMEN REVISI BEKU**.
- File tidak disimpan permanen.

PDF resmi dan live memakai layout mesin/dies yang sudah ada, termasuk kolom PROSES
khusus dies. Baris backlog ditempatkan setelah baris terjadwal, tanpa sel PLAN, dengan
label dan warna yang jelas.

## UI Control Stroke

Halaman mesin dan dies menampilkan:

- KPI eligible, scheduled, late, backlog, dan backlog urgent.
- Banner dokumen aktif: nomor, revisi, pembuat, dan waktu generate WIB.
- Tombol **Generate Jadwal** atau **Buat Revisi** sesuai state.
- Tombol **PDF Resmi Rev. XX**, **PDF Monitoring Live**, dan **Riwayat Revisi**.
- Daftar item bermasalah yang dapat dibuka dari notification.

Preview revisi memakai halaman khusus, bukan modal kecil, agar diff tetap terbaca.

## Notification

Setelah revisi aktif memiliki item `late`, `backlog`, atau `backlog_urgent`, sistem
membuat satu bell notification ringkasan untuk admin dan `staff_maintenance`.
Notification tidak dibuat per aset.

- Klik notification membuka daftar item bermasalah.
- Notification revisi lama diselesaikan ketika revisi baru aktif.
- Preventive aktual menyelesaikan item secara derived.
- Notification selesai otomatis ketika seluruh item bermasalah pada revisi tersebut
  sudah mempunyai preventive aktual yang relevan.
- Generate identik tidak membuat notification baru.

## Authorization

- Generate, preview, dan aktivasi revisi: `admin`, `staff_maintenance`.
- PDF aktif, PDF live, serta riwayat revisi: `admin`, `staff_maintenance`, `manager`,
  `guest`.
- Guest tetap read-only.
- Seluruh route file private tetap melewati authorization; path storage tidak pernah
  diekspos langsung.

## Error Handling

- Schema baru belum tersedia: halaman Control Stroke tetap hidup dan tombol generate
  disembunyikan dengan pesan fitur belum siap.
- Nol kandidat: Rev. 00 boleh diterbitkan sebagai dokumen kosong.
- Preview identik: nol write.
- Preview basi: aktivasi ditolak dan user diminta menghitung ulang.
- Lock sibuk: user mendapat pesan generate sedang berjalan.
- Asset master hilang: renderer memakai snapshot identity.
- PDF gagal: revisi aktif lama tetap berlaku.
- Kesalahan satu jenis saat cron dicatat tanpa menyembunyikan hasil jenis lain.

## Rollout dan Kompatibilitas

Perubahan dilakukan sebagai migrasi kompatibel dan bertahap:

1. Tambah tabel revisions serta kolom snapshot/status dan active pointer secara
   additive.
2. Backfill seluruh schedule existing menjadi Rev. 00 beserta itemnya.
3. Deploy code dual-read dengan `Schema::hasTable` dan `Schema::hasColumn` selama
   transisi.
4. Setelah backfill terbukti, migration lanjutan memperketat foreign key, nullability,
   dan constraint XOR target bila didukung DB.
5. Relasi lama dipertahankan sampai semua pembaca berpindah ke revision.
6. Tidak ada penghapusan data existing dalam rollout awal.

Setiap migration baru wajib ditambahkan ke step targeted migration di
`.github/workflows/deploy.yml` dan harus lolos
`scripts/check-migration-coverage.sh`. Implementasi harus kompatibel dengan PHP 8.4.

## Strategi Pengujian

### Unit

- Window tepat 30 hari selesai dan mengecualikan hari generate.
- Stroke memasukkan `qty_ng`; denominator tetap 30.
- Projection untuk overdue, laju positif, laju nol, dan cutoff safety +7.
- Carry backlog, reset `backlog_since`, dan priority deterministik.
- Rotasi mesin/dies, hari libur, minggu parsial, serta kapasitas efektif.
- Slot aman, slot late, dan backlog saat kapasitas habis.
- Canonical hash stabil dan berubah hanya ketika hasil material berubah.

### Feature

- Authorization seluruh role.
- Generate Rev. 00, preview revision, no-op, stale preview, dan alasan wajib.
- Double-click atau dua request paralel tidak membuat dua revision.
- Cron tidak membuat revision baru ketika schedule bulan berjalan sudah ada.
- Notification dibuat, dideduplikasi, dan diselesaikan.
- PDF resmi tersimpan private dan checksum stabil.
- PDF live menampilkan seluruh tanggal ACT.
- Historical revision dapat diunduh tanpa berubah.
- Schema guard mencegah 500 sebelum migration tersedia.

### Gate dan verifikasi visual

- Full `php artisan test`.
- `./scripts/check-migration-coverage.sh`.
- `vendor/bin/pint app tests --test`.
- Render PDF mesin dan dies untuk bulan 28, 30, dan 31 hari.
- Periksa visual hari libur, rotasi, late, backlog, kolom proses dies, tanda tangan,
  dan pagination sebelum merge.
- Verifikasi tidak dilakukan dengan membuat transaksi PREVENTIVE percobaan di
  production.

## Kriteria Selesai

- Kandidat prediktif masuk sesuai window, cutoff, dan priority yang disepakati.
- Mesin dan dies hanya dijadwalkan pada hari yang diizinkan.
- Asset tidak hilang ketika kapasitas habis.
- Generate ulang menghasilkan revision baru hanya bila hasil material berubah.
- Dokumen resmi revision lama immutable dan PDF live tetap menunjukkan ACT terbaru.
- Notification menyampaikan risiko tanpa spam per aset.
- Rollout aman terhadap schema drift dan jendela deploy kode-sebelum-migration.
