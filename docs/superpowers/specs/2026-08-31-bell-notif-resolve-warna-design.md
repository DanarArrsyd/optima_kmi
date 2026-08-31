# Bell Notifikasi — Warna Kuning Nunggu Resolve, Bukan Klik

## Konteks

Item notif `note_submitted` (staff kirim note → admin) sekarang pakai `is-unread`
(background kuning `#fefce8`) yang lepas begitu admin klik item-nya (`read_at` keisi).
Admin bisa "matiin" tanda kuning cuma dengan buka dropdown, tanpa beneran nyelesain
note-nya (edit record + centang `resolve_note` → `staff_note_resolved_at`).

## Tujuan

Item `note_submitted` tetap kuning sampai `staff_note_resolved_at` di record aslinya
keisi — bukan sampai diklik. Tipe notif lain (`note_resolved`) TIDAK berubah, tetap
`read_at`-driven kayak sekarang.

## Mekanisme

**Backend** — `NotificationController::poll()` nambah field `resolved` per item:
- Cuma dihitung buat item `type === 'note_submitted'` (default `null`/tak dipakai buat
  tipe lain).
- Query batch per `notifiable_type` (bukan N+1): kumpulin `notifiable_id` per tipe dari
  15 item yg lagi diambil, satu query per tipe ambil `id` + `staff_note_resolved_at`.
- Mapping tipe→model: `produksi`→`TransaksiProduksi`, `qc`→`TransaksiQc`,
  `dandori`→`TransaksiDandoriRincian` (kolom `staff_note_resolved_at` udah ada di
  ketiganya, lihat `ResolvesStaffNote` trait).
- `resolved = staff_note_resolved_at !== null`.

**Frontend** (`notifBell()` + template di `layouts/app.blade.php`) — kelas `is-unread`
(nentuin background kuning) sumbernya beda per tipe:
- `note_submitted` → `!item.resolved`
- tipe lain → `!item.read_at` (perilaku lama, tak disentuh)

## Di luar cakupan

- Badge count (angka merah) & mark-read tetap `read_at`-driven, nol perubahan.
- Update warna nunggu poll interval (60 detik), nol WebSocket/broadcast.
- Nol perubahan skema DB — `staff_note_resolved_at` udah ada.
