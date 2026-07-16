<?php
namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Satu tempat untuk menjawab "tanggal ini masuk akal atau salah ketik?".
 *
 * Rule Laravel `date` dan Carbon::parse() sama-sama menerima tahun 4-digit apa pun, jadi
 * "0026-06-21" (salah ketik "2026-06-21") lolos dan tersimpan diam-diam. Tiga baris dandori
 * produksi masuk lewat celah ini lalu hilang dari setiap laporan VCB yang memfilter per
 * rentang tanggal — tanpa error sama sekali.
 *
 * Batasnya ditaruh di sini karena ada 6 salinan parseDate() yang tersebar di app/Imports/;
 * kalau angkanya ditulis ulang di tiap salinan, cepat atau lambat ada yang ketinggalan.
 * Sisi Form Request memakai rule Laravel dengan literal yang sama — lihat DateRangeGuardTest.
 */
final class SaneDate
{
    /** Transaksi (produksi, QC, dandori, breakdown): OPTIMA belum ada sebelum ini. */
    public const MIN_TRANSAKSI = '2020-01-01';

    /** Instalasi mesin/dies: mesin stamping berumur belasan tahun itu normal di pabrik. */
    public const MIN_INSTALASI = '1990-01-01';

    /**
     * Batas atas sengaja besok, bukan hari ini: app timezone UTC (config/app.php) sedangkan
     * pabrik di WIB (UTC+7), jadi tanggal "hari ini" versi operator bisa satu hari di depan
     * UTC. Memakai hari ini akan menolak input pagi yang sah.
     */
    public static function isSane(?string $ymd, string $min = self::MIN_TRANSAKSI): bool
    {
        if ($ymd === null || $ymd === '') {
            return false;
        }

        return $ymd >= $min && $ymd <= Carbon::now()->addDay()->format('Y-m-d');
    }
}
