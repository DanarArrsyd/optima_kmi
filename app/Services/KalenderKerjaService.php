<?php

namespace App\Services;

use App\Models\MasterShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Kalender kerja pabrik: hari mana yang bekerja, dan berapa jam terjadwalnya.
 *
 * Berdiri sendiri, BUKAN method di OverviewService atau MaintenanceDashboardService,
 * karena dipakai keduanya — dan OverviewService sudah bergantung pada
 * MaintenanceDashboardService. Menaruhnya di salah satu dari dua itu langsung
 * menciptakan ketergantungan melingkar.
 *
 * Sumber datanya:
 *   - Minggu     : diturunkan dari tanggalnya. master_shift cuma punya kolom jam
 *                  Senin–Kamis, Jumat, dan Sabtu — nol kolom Minggu, jadi
 *                  struktur DB memang sudah menyatakan Minggu bukan hari kerja.
 *   - Hari libur : config/hari_libur.php. Tidak mungkin diturunkan (keputusan
 *                  pemerintah, berubah tiap tahun).
 *   - Jam shift  : master_shift, per kelompok hari, dikurangi master_shift_istirahat.
 *   - Shift mana : master_shift.dihitung_availability.
 */
class KalenderKerjaService
{
    /**
     * Memo per instance: jam bersih per kelompok hari.
     *
     * Dihitung sekali lalu dipakai berulang. Tanpa ini, rentang all-time
     * (dipakai halaman MTTR/MTBF dan chatbot) mengulang query shift + istirahat
     * untuk setiap hari dalam rentang.
     *
     * @var array<string, float>|null
     */
    private ?array $jamPerKelompok = null;

    /** Apakah tanggal ini hari kerja pabrik. */
    public static function hariKerja(Carbon $tgl): bool
    {
        if ($tgl->isSunday()) {
            return false;
        }

        return ! in_array($tgl->toDateString(), config('hari_libur', []), true);
    }

    /**
     * Jam terjadwal pada SATU tanggal. 0 kalau bukan hari kerja.
     */
    public function jamHari(Carbon $tgl): float
    {
        if (! self::hariKerja($tgl)) {
            return 0.0;
        }

        return $this->jamPerKelompok()[$this->kelompokHari($tgl)] ?? 0.0;
    }

    /**
     * Total jam terjadwal pada satu rentang tanggal, inklusif kedua ujung.
     *
     * Ini PENYEBUT Availability. Menggantikan jam kalender 24/7 yang dipakai
     * sebelumnya, yang memuat 8 jam per hari tidak terjadwal (pabrik hanya
     * menjalankan Shift 1 & 2) plus seluruh hari Minggu dan hari libur —
     * sehingga Availability hampir selalu mendekati 100%.
     */
    public function jamTerjadwal(Carbon $from, Carbon $to): float
    {
        if ($from->gt($to)) {
            return 0.0;
        }

        $total = 0.0;
        $kursor = $from->copy()->startOfDay();
        $akhir = $to->copy()->startOfDay();

        while ($kursor->lte($akhir)) {
            $total += $this->jamHari($kursor);
            $kursor->addDay();
        }

        return round($total, 2);
    }

    /**
     * Kelompok hari sesuai skema master_shift_istirahat.
     *
     * Senin–Kamis memakai kolom jam_start/jam_finish yang asli; Jumat dan Sabtu
     * punya kolomnya sendiri. Minggu tidak pernah sampai ke sini — sudah
     * disaring hariKerja().
     */
    private function kelompokHari(Carbon $tgl): string
    {
        return match (true) {
            $tgl->isFriday() => 'jumat',
            $tgl->isSaturday() => 'sabtu',
            default => 'senin_kamis',
        };
    }

    /**
     * Jam bersih (durasi shift − istirahat) per kelompok hari, dijumlah lintas
     * semua shift yang ditandai dihitung_availability.
     *
     * @return array<string, float>
     */
    private function jamPerKelompok(): array
    {
        if ($this->jamPerKelompok !== null) {
            return $this->jamPerKelompok;
        }

        // Guard kolom: kode bisa live sebelum migrasi menyusul di prod. Tanpa
        // ini halaman Availability 500 di jeda antara rsync dan migrate.
        $adaKolom = Schema::hasColumn('master_shift', 'dihitung_availability');

        $shifts = MasterShift::query()
            ->when($adaKolom, fn ($q) => $q->where('dihitung_availability', true))
            ->with('istirahat')
            ->get();

        $hasil = ['senin_kamis' => 0.0, 'jumat' => 0.0, 'sabtu' => 0.0];

        foreach ($shifts as $shift) {
            foreach (array_keys($hasil) as $kelompok) {
                [$mulai, $selesai] = $this->jamShift($shift, $kelompok);

                if ($mulai === null || $selesai === null) {
                    continue;
                }

                $durasi = $this->selisihJam($mulai, $selesai);
                $istirahat = 0.0;

                foreach ($shift->istirahat as $i) {
                    if ($i->kelompok_hari === $kelompok) {
                        $istirahat += $this->selisihJam($i->jam_start, $i->jam_finish);
                    }
                }

                // max(0): istirahat yang salah input tidak boleh membuat jam
                // terjadwal negatif dan menyeret Availability ke atas 100%.
                $hasil[$kelompok] += max(0.0, $durasi - $istirahat);
            }
        }

        return $this->jamPerKelompok = array_map(fn (float $j) => round($j, 4), $hasil);
    }

    /**
     * Jam mulai & selesai shift untuk sebuah kelompok hari.
     *
     * Kolom Jumat/Sabtu nullable. Null diartikan "sama seperti Senin–Kamis",
     * BUKAN "tidak dijadwalkan": migrasi yang menambahkannya menulis bahwa
     * jam_start/jam_finish asli dipakai Senin–Kamis, jadi baris lama yang belum
     * pernah disunting punya Jumat/Sabtu null padahal pabriknya jelas bekerja
     * hari itu. Mengartikannya nol akan memotong dua hari kerja per minggu dari
     * penyebut dan melambungkan Availability.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function jamShift(MasterShift $shift, string $kelompok): array
    {
        [$mulai, $selesai] = match ($kelompok) {
            'jumat' => [$shift->jam_start_jumat, $shift->jam_finish_jumat],
            'sabtu' => [$shift->jam_start_sabtu, $shift->jam_finish_sabtu],
            default => [$shift->jam_start, $shift->jam_finish],
        };

        if ($mulai === null || $selesai === null) {
            return [$shift->jam_start, $shift->jam_finish];
        }

        return [$mulai, $selesai];
    }

    /**
     * Selisih dua jam dalam desimal jam, menangani shift yang melewati tengah malam.
     *
     * Shift 3 (22:00 → 06:00) durasinya 8 jam, bukan −16. Tanpa penanganan ini
     * jam terjadwalnya negatif dan menyeret total ke bawah.
     */
    private function selisihJam(mixed $mulai, mixed $selesai): float
    {
        $m = Carbon::parse((string) $mulai);
        $s = Carbon::parse((string) $selesai);

        if ($s->lte($m)) {
            $s->addDay();
        }

        return $m->diffInMinutes($s) / 60;
    }
}
