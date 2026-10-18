<?php

namespace App\Http\Controllers\Maintenance\Concerns;

use Illuminate\Support\Carbon;

/**
 * Ringkasan MTTR/MTBF (KPI) dan tren bulanannya untuk halaman MTTR & MTBF
 * Mesin/Dies.
 *
 * Angka per mesin/dies TETAP dari compute() service (MaintenanceDashboardService /
 * MaintenanceDiesDashboardService) — trait ini tidak punya rumus MTTR/MTBF sendiri.
 * Yang ada di sini hanya cara MENGGABUNGKAN baris banyak mesin jadi satu angka,
 * dan cara yang sama dipakai KPI layar dan titik grafik tren. Kalau keduanya
 * punya agregasi sendiri-sendiri, titik tren bulan X bisa beda dengan KPI saat
 * rentangnya persis bulan X.
 */
trait TrenBulananMaintenance
{
    /**
     * Plafon titik tren. Tiap titik = satu panggilan compute() (beberapa query +
     * loop kalender per mesin), dan route-nya terbuka untuk guest: rentang
     * "2000-01-01 s.d. hari ini" tanpa plafon = ratusan compute() per klik.
     * Rentang lebih panjang dipotong ke 36 bulan TERAKHIR-nya.
     */
    public const TREN_MAKS_BULAN = 36;

    /**
     * Rata-rata MTTR antar mesin yang punya breakdown — null kalau tidak ada.
     *
     * KPI lama mencetak 0 untuk "tidak ada breakdown" (round(null) = 0); itu
     * dipertahankan di KPI lewat `?? 0`, tapi titik tren butuh null supaya
     * bulan tanpa kejadian tampil sebagai celah, bukan "perbaikan 0 jam".
     */
    private function rataMttr(array $rows): ?float
    {
        $ada = collect($rows)->where('count', '>', 0);

        return $ada->isEmpty() ? null : round((float) $ada->avg('mttr_jam'), 2);
    }

    /**
     * Rata-rata MTBF (jam kerja) antar mesin yang punya jam terjadwal di rentang itu.
     *
     * operating_hours = 0 berarti mesin belum terpasang sepanjang rentang
     * (tanggal_instalasi > akhir rentang) atau rentangnya nol hari kerja. compute()
     * mengembalikan mtbf_jam = 0 untuk baris itu, bukan null — tanpa saringan ini,
     * mesin yang dipasang Juni menyeret rata-rata Jan–Mei ke bawah secara surut.
     */
    private function rataMtbf(array $rows): ?float
    {
        $ada = collect($rows)->whereNotNull('mtbf_jam')->where('operating_hours', '>', 0);

        return $ada->isEmpty() ? null : round((float) $ada->avg('mtbf_jam'), 2);
    }

    /**
     * Satu titik per bulan kalender di [from, to]. Bulan pertama & terakhir
     * dipotong ke batas rentang, jadi titik itu persis sama dengan KPI kalau
     * rentang yang sama dipilih. Tanggal di sini tanggal pabrik (kolom DATE
     * yang diisi user dalam WIB), bukan timestamp UTC — tidak ada konversi zona.
     * Lebih dari TREN_MAKS_BULAN bulan → hanya bulan-bulan terakhirnya.
     *
     * @param  callable(Carbon, Carbon): array  $hitung  baris compute() untuk satu rentang
     * @return list<array{bulan:string, label:string, from:string, to:string, mttr:?float, mtbf:?float, total_bd:int}>
     */
    private function trenBulanan(Carbon $from, Carbon $to, callable $hitung): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $from = $from->max($to->copy()->startOfMonth()->subMonths(self::TREN_MAKS_BULAN - 1));
        $tren = [];

        for ($awal = $from->copy()->startOfMonth(); $awal->lte($to); $awal->addMonth()) {
            $dari = $awal->copy()->max($from);
            $sampai = $awal->copy()->endOfMonth()->startOfDay()->min($to);
            $rows = $hitung($dari, $sampai);

            $tren[] = [
                'bulan' => $awal->format('Y-m'),
                'label' => $awal->locale('id')->translatedFormat('M Y'),
                'from' => $dari->toDateString(),
                'to' => $sampai->toDateString(),
                'mttr' => $this->rataMttr($rows),
                'mtbf' => $this->rataMtbf($rows),
                'total_bd' => (int) collect($rows)->sum('count'),
            ];
        }

        return $tren;
    }
}
