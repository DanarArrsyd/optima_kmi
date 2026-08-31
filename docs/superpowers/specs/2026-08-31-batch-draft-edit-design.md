# Edit Baris Draft — Batch Input (5 modul)

## Konteks

`batchDraft` (kerangka bersama, `resources/views/layouts/app.blade.php`) dipakai 5
halaman batch input: LHP Produksi, QC, Dandori, Breakdown Mesin, Breakdown Dies.
Tabel "DRAFT TERSIMPAN" cuma punya tombol Hapus — salah isi satu field berarti
hapus seluruh baris lalu ketik ulang dari nol, termasuk sub-blok NG/Dandori/dsb
yang panjang.

## Tujuan

Tambah tombol EDIT di tabel draft, di 5 modul sekaligus lewat satu perubahan di
kerangka bersama. Nol perubahan di server (`StoreXBatchRequest`) — murni state
browser sebelum SUBMIT DRAFT.

## Mekanisme

**Kontrak `cfg` baru (wajib per modul, mirror `cfg.kumpulkan`):**
- `cfg.sebar(row, diri)` — isi form atas dari `row`: set value tiap input/select
  via DOM, panggil `pilih()` pada instance `partPicker` kalau modulnya pakai part
  picker, isi ulang sub-blok (`.ng-rows`, `.dandori-rows`, dst) dari `row.ng` /
  `row.dandori` / setara.

**State baru di `batchDraft`:**
- `editIndex` — `null` = mode tambah baris baru (perilaku lama, tak berubah).
  Angka = sedang mengedit `rows[editIndex]`.

**Alur:**
1. Klik EDIT baris `i` → `edit(i)`: `cfg.sebar(rows[i], diri)`, `editIndex = i`,
   scroll form atas ke view.
2. Label tombol submit jadi "UPDATE BARIS" saat `editIndex !== null`; tombol
   "Batal Edit" muncul di sampingnya.
3. Submit saat mode edit → `simpanKeDraft()` menulis `rows[editIndex] = calon`
   (bukan `push`), lalu `editIndex = null`. Posisi baris di tabel tidak berubah.
4. `cfg.periksa` (validasi tumpang-tindih jam, khusus LHP Produksi/QC) exclude
   `editIndex` sendiri saat mengecek overlap terhadap `rows` lain.
5. "Batal Edit" → buang perubahan form (`resetSebagian()`), `editIndex = null`.
6. Tombol Hapus pada baris yang sedang diedit (`i === editIndex`) dinonaktifkan
   — mencegah baris "hantu edit" (dihapus dari array tapi form atas masih
   menganggap sedang mengedit index yang sudah tak ada).
7. Klik EDIT baris lain saat sudah dalam mode edit → treat sebagai ganti target
   edit (`cfg.sebar` baris baru, `editIndex` pindah), tanpa konfirmasi buang
   perubahan — form atas cuma pratinjau lokal, isinya sudah tersalin balik ke
   `rows[editIndex]` lama? **Tidak** — perubahan belum di-commit ke `rows` sampai
   submit. Jadi pindah target edit tanpa submit membuang perubahan form yang
   sedang diketik. Ini cukup jarang (operator biasanya selesai satu edit dulu)
   dan konsisten dengan perilaku "Batal Edit" — tidak perlu dialog konfirmasi
   tambahan untuk menjaga UI tetap sederhana.

## Per-modul

Tiap modul (`input/produksi`, `input/qc`, `input/dandori`,
`maintenance/breakdown`, `maintenance/breakdown-dies`) menulis `cfg.sebar`
sendiri sesuai field form dan sub-bloknya masing-masing. Field tanggal/shift/
operator (yang sengaja TIDAK direset antar baris tambah — lihat komentar
`resetSebagian` di kerangka) tetap diisi ulang oleh `sebar()` saat edit, supaya
form mencerminkan baris yang sedang diedit secara utuh.

## Di luar cakupan

- Tidak ada endpoint server baru — edit murni pra-submit, di browser.
- Tidak mengubah `StoreXBatchRequest` manapun.
- Tidak ada reorder baris lewat edit (baris tetap di posisi asalnya).
