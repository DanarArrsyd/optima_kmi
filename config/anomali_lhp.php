<?php

/*
|--------------------------------------------------------------------------
| Ambang anomali LHP produksi
|--------------------------------------------------------------------------
|
| Dipindah dari private const di Admin\AnomalyController karena sekarang dipakai
| DUA tempat: halaman Anomali LHP, dan laporan kandidat SPH teoretis
| (SphTeoretisService) yang membuang baris anomali sebelum menghitung rekor.
|
| Dua salinan angka ini akan melenceng, dan melencengnya SENYAP: halaman anomali
| menandai sebuah baris, laporan kandidat tetap memakainya, dan rekor yang
| meracuni SPH teoretis lolos tanpa jejak. Satu sumber.
|
*/

return [
    /*
     * Achievement (SPH aktual ÷ SPH standar) di atas ini dianggap anomali.
     *
     * Menangkap dua mode salah input sekaligus: qty kelebihan nol, dan jam kerja
     * yang kekecilan karena jam_start/jam_finish salah.
     */
    'pct_maks' => 150,

    /*
     * Waktu kerja di bawah ini (dalam JAM) dianggap anomali. 0.083 jam = 5 menit.
     *
     * Satu shift tak mungkin 5 menit; kalau muncul, jam start/finish-nya salah.
     * Ini mode salah input yang paling merusak rekor SPH: penyebut mendekati nol
     * membuat SPH-nya meledak.
     */
    'wkj_min' => 0.083,
];
