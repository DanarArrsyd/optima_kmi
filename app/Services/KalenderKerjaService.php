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
     * Jam PRODUKTIF sebuah LHP setelah waktu setup yang tumpang-tindih dikeluarkan.
     *
     * Ini penyebut Performance menurut OEE standar: Operating Time = waktu setelah
     * downtime dan setup dikeluarkan (Nakajima/TPM, ISO 22400-2).
     *
     * Masalahnya berapa banyak setup yang BERADA DI DALAM jam LHP tidak tercatat —
     * transaksi_dandori_rincian cuma menyimpan DURASI, tanpa jam mulai/selesai.
     * Sebagian operator memasukkan setup ke jam_start–jam_finish, sebagian
     * memisahkannya. Tumpang-tindihnya karena itu DITURUNKAN dari dua batas fisik:
     *
     *   1. produksi tak bisa melebihi jam LHP
     *   2. produksi tak bisa melebihi jam terjadwal dikurangi setup
     *
     *   overlap = clamp( jam_LHP − (jam_terjadwal − dandori), 0, dandori )
     *
     * Hasilnya kedua konvensi mendarat di angka yang SAMA, jadi perhitungan kebal
     * konvensi pencatatan — SOP tidak jadi prasyarat, dan baris historis langsung
     * benar tanpa dibersihkan:
     *
     *   include shift penuh : LHP 8, jadwal 8, dandori 2 → produktif 6 ✓
     *   pisah               : LHP 6, jadwal 8, dandori 2 → produktif 6 ✓
     *   include + lembur    : LHP 9, jadwal 8, dandori 2 → produktif 7 ✓
     *   hari sebagian       : LHP 2, jadwal 8, dandori 1 → produktif 2
     *                         (pesimis: mungkin 1, tapi arah aman)
     *
     * ⚠️ WAJIB dipanggil PER TANGGAL, bukan per rentang. Pada rentang sebulan,
     * jam terjadwalnya ~216 jam sementara jam LHP sehari cuma 8 — batas kedua tak
     * pernah mengikat dan pengurangannya mati diam-diam. Itu sudah terjadi sekali.
     *
     * Hari tanpa jadwal (lembur di hari libur) → jadwalnya nol, jadi batas kedua
     * tak bisa dipakai; jatuh ke asumsi konvensi yang disepakati: setup ada DI
     * DALAM jam LHP.
     */
    public function jamProduktif(float $jamLhp, float $dandoriJam, Carbon $tgl): float
    {
        if ($dandoriJam <= 0 || $jamLhp <= 0) {
            return max(0.0, $jamLhp);
        }

        $jadwal = $this->jamHari($tgl);

        $overlap = $jadwal <= 0
            ? $dandoriJam
            : max(0.0, min($dandoriJam, $jamLhp - max(0.0, $jadwal - $dandoriJam)));

        return max(0.0, $jamLhp - $overlap);
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
        return $this->jamTerjadwalRinci($from, $to)['jam'];
    }

    /**
     * Jam terjadwal DAN jumlah hari kerjanya, dari satu penelusuran.
     *
     * Keduanya dikembalikan bersama dengan sengaja. Pemakai yang butuh
     * "rata-rata jam per hari kerja" — mis. mengubah MTBF dari jam ke HARI KERJA —
     * kalau menghitung sendiri jumlah harinya akan memakai aturan hari kerja
     * versi sendiri, dan versi itu melenceng dari yang dipakai di sini tanpa
     * ada yang tahu. Satu penelusuran, satu jawaban.
     *
     * @return array{jam: float, hari: int}
     */
    public function jamTerjadwalRinci(Carbon $from, Carbon $to): array
    {
        if ($from->gt($to)) {
            return ['jam' => 0.0, 'hari' => 0];
        }

        $totalJam = 0.0;
        $totalHari = 0;
        $kursor = $from->copy()->startOfDay();
        $akhir = $to->copy()->startOfDay();

        while ($kursor->lte($akhir)) {
            $jam = $this->jamHari($kursor);
            if ($jam > 0) {
                $totalJam += $jam;
                $totalHari++;
            }
            $kursor->addDay();
        }

        return ['jam' => round($totalJam, 2), 'hari' => $totalHari];
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
