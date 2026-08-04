<?php

/*
|--------------------------------------------------------------------------
| Hari libur pabrik
|--------------------------------------------------------------------------
|
| Tanggal merah nasional dan cuti bersama, format 'Y-m-d'.
|
| Dipakai untuk DUA hal, dan keduanya bergantung pada daftar ini benar:
|   1. Grafik tren di /overview melewati hari ini — tidak lagi menyisakan
|      kolom kosong di sumbu.
|   2. Jam terjadwal (loading time) untuk Availability = 0 pada hari ini,
|      sehingga Availability-nya null, bukan 0% (lihat OverviewService).
|
| HARI MINGGU TIDAK PERLU DIDAFTARKAN. Minggu diturunkan dari tanggalnya
| sendiri lewat OverviewService::hariKerja(). Konsisten dengan master_shift
| yang hanya punya kolom jam Senin–Kamis, Jumat, dan Sabtu — nol kolom
| Minggu, jadi struktur DB memang sudah menyatakan Minggu bukan hari kerja.
|
| ⚠️ SENGAJA DIKIRIM KOSONG. Tanggal merah adalah keputusan pemerintah yang
| berubah tiap tahun (Idul Fitri bergerak, cuti bersama diputuskan lewat SKB
| tiga menteri). Menebak tanggalnya berbahaya BUKAN karena grafiknya salah,
| tapi karena tanggal yang salah membuat hari produksi NYATA hilang dari
| grafik dan jam terjadwalnya jadi nol — kerugian data yang senyap.
|
| Isi dari kalender resmi, bukan dari ingatan.
|
| Contoh bentuknya:
|
|     '2026-01-01',   // Tahun Baru
|     '2026-03-19',   // Nyepi
|     '2026-12-25',   // Natal
|
| Hari yang TETAP ADA LHP produksinya akan tetap muncul di grafik walau
| terdaftar di sini — lembur di hari libur itu nyata, dan menyembunyikan hari
| yang punya data lebih buruk daripada menampilkan hari libur. Lihat
| OverviewService::tren().
|
*/

return [
    // Belum ada. Tambahkan tanggalnya di sini.
];
