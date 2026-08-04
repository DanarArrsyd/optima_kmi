<?php

namespace App\Services;

use App\Models\MasterLosstime;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Angka halaman Overview: achievement, OEE pabrik, dan Pareto loss time.
 *
 * Semuanya berskala PABRIK (semua mesin digabung), berbeda dari VCB yang
 * memecah per mesin/operator dan dari OeeService yang per satu mesin.
 *
 * Definisi sengaja MEMINJAM yang sudah dipakai app, bukan bikin baru:
 *   - Achievement : qty_aktual ÷ (waktu kerja × sph_standar_produksi), sama
 *     seperti DashboardService::getVcbMesin(). Pembilangnya barang BAIK saja.
 *   - Availability: jam jendela kalender − downtime breakdown − waktu dandori,
 *     dari MaintenanceDashboardService. Lihat catatan basis kalender di bawah.
 *   - Performance : (qty_aktual + qty_ng) ÷ output teoretis, sama seperti
 *     OeeService. Menilai kecepatan MESIN, jadi barang NG tetap dihitung.
 *   - Quality     : qty_aktual ÷ (qty_aktual + qty_ng), sama seperti OeeService.
 * Kalau salah satu definisi ini diubah, ubah di sumbernya — jangan di sini.
 */
class OverviewService
{
    /** Shift yang dipecah jadi donut sendiri di halaman Overview. */
    public const SHIFT_DONUT = ['Shift 1', 'Shift 2'];

    /** Banyak titik di grafik tren. */
    public const HARI_TREN = 6;

    public function __construct(
        private MaintenanceDashboardService $mesinDashboard = new MaintenanceDashboardService,
    ) {}

    /**
     * Tanggal acuan panel "LAST 24 H": tanggal_produksi TERBARU yang punya data,
     * bukan hari ini.
     *
     * Alasannya dua. (1) App jalan UTC sedangkan pabrik WIB, jadi "hari ini"
     * menurut server bisa bergeser dari hari kerja yang dimaksud orang.
     * (2) LHP diinput per shift per tanggal, sering baru besoknya — "hari ini"
     * bakal menampilkan panel setengah kosong sepanjang pagi dan terlihat
     * seperti produksi anjlok.
     *
     * null = belum ada LHP produksi sama sekali.
     */
    public function tanggalAcuan(): ?Carbon
    {
        $maks = TransaksiProduksi::max('tanggal_produksi');

        return $maks ? Carbon::parse($maks)->startOfDay() : null;
    }

    /**
     * Achievement pabrik pada satu rentang, opsional disaring per shift.
     *
     * Di-pool (jumlah pembilang ÷ jumlah penyebut), BUKAN rata-rata dari
     * achievement per mesin: rata-rata polos memberi bobot sama pada mesin yang
     * jalan 8 jam dan yang jalan 30 menit.
     *
     * @return array{pct: float|null, qty: int, target: float}
     */
    public function achievement(Carbon $from, Carbon $to, ?int $shiftId = null): array
    {
        $rows = TransaksiProduksi::with('partProses:id,sph_standar_produksi')
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->get(['id_part_proses', 'id_shift', 'qty_aktual', 'jam_start', 'jam_finish']);

        $qty = 0;
        $target = 0.0;

        foreach ($rows as $r) {
            $qty += (int) $r->qty_aktual;

            $std = (float) ($r->partProses->sph_standar_produksi ?? 0);
            $jam = (float) $r->waktu_kerja_jam;
            if ($std > 0 && $jam > 0) {
                $target += $std * $jam;
            }
        }

        return [
            // null, bukan 0: "tidak ada target yang bisa dibandingkan" berbeda
            // dari "tercapai 0%". Nol bikin donut terlihat seperti gagal total.
            'pct' => $target > 0 ? round($qty / $target * 100, 2) : null,
            'qty' => $qty,
            'target' => round($target, 2),
        ];
    }

    /**
     * Achievement per shift untuk donut Shift 1 & Shift 2.
     *
     * Shift 3 sengaja tidak dipecah jadi donut (keputusan desain), tapi TETAP
     * ikut terhitung di achievement pabrik — dia cuma tidak punya panel sendiri.
     *
     * @return list<array{nama: string, pct: float|null}>
     */
    public function achievementPerShift(Carbon $from, Carbon $to): array
    {
        $shifts = MasterShift::whereIn('nama_shift', self::SHIFT_DONUT)
            ->orderBy('nama_shift')
            ->get(['id', 'nama_shift']);

        $hasil = [];
        foreach (self::SHIFT_DONUT as $nama) {
            $shift = $shifts->firstWhere('nama_shift', $nama);

            $hasil[] = [
                'nama' => $nama,
                // Shift belum ada di master → null, jangan 0%. Panelnya tetap
                // dirender supaya hilangnya master data kelihatan, bukan diam.
                'pct' => $shift ? $this->achievement($from, $to, $shift->id)['pct'] : null,
            ];
        }

        return $hasil;
    }

    /**
     * A / P / Q / OEE skala pabrik pada satu rentang.
     *
     * Kerugian availability = downtime breakdown + waktu dandori. Setup /
     * ganti dies adalah kerugian availability di OEE standar: mesinnya sehat
     * tapi tidak memproduksi apa pun.
     *
     * ⚠️ CATATAN BASIS: penyebutnya jam KALENDER (24/7), bukan jam shift
     * terjadwal — warisan definisi MaintenanceDashboardService, dipertahankan
     * supaya angka di sini tidak bertentangan dengan halaman Availability/MTBF.
     *
     * Batasan yang DIKETAHUI, bukan dugaan: pabrik dikonfirmasi hanya
     * menjalankan Shift 1 & 2 (16 jam), jadi penyebut ini memuat 8 jam per hari
     * yang tidak pernah dijadwalkan dan availability-nya condong tinggi. Kalau
     * kelak diganti ke basis jam shift terjadwal, ganti di
     * MaintenanceDashboardService — jangan di sini — supaya tetap satu definisi.
     * Data jadwalnya sudah ada: master_shift punya jam per kelompok hari
     * (Senin–Kamis / Jumat / Sabtu) plus tabel master_shift_istirahat. Yang
     * belum ada: jadwal Minggu, dan jadwal PER MESIN.
     *
     * @return array{
     *   availability_pct: float|null, performance_pct: float|null,
     *   quality_pct: float|null, oee_pct: float|null, catatan: list<string>
     * }
     */
    public function oee(Carbon $from, Carbon $to): array
    {
        $catatan = [];

        /* ── Availability: di-pool tertimbang jam jendela ────────────────
           Bukan rata-rata polos availability_pct: penyebut tiap mesin beda
           begitu ada yang instalasinya jatuh di tengah rentang. */
        $totalJam = 0.0;
        $totalLoss = 0.0;
        $mesinTanpaInstalasi = 0;
        $dandoriPerMesin = [];

        foreach ($this->mesinDashboard->compute($from, $to) as $row) {
            $jam = $row['operating_hours'] ?? null;
            if ($jam === null) {
                $mesinTanpaInstalasi++;

                continue;
            }
            $totalJam += (float) $jam;

            /*
             * sum_loss, BUKAN sum_downtime: kerugian availability = downtime
             * breakdown + waktu dandori (lihat MaintenanceDashboardService).
             *
             * Baris ini pernah memakai sum_downtime dan itu membuat keputusan
             * "dandori adalah kerugian availability" berhenti di halaman
             * Availability tanpa pernah sampai ke Overview — halaman ini tetap
             * melaporkan 100% padahal halaman sebelahnya sudah tidak. Dua
             * halaman saling bertentangan persis seperti yang dihindari.
             */
            $totalLoss += (float) $row['sum_loss'];

            // Dandori PER MESIN, dipanen dari baris yang sudah di-loop — nol
            // query tambahan. Dipakai mengeluarkan setup dari penyebut
            // Performance di bawah, per mesin, bukan sebagai rasio pabrik:
            // rasio pabrik salah begitu satu mesin banyak dandori dan yang lain
            // tidak.
            $dandoriPerMesin[(int) $row['id_mesin']] = (float) $row['sum_dandori'];
        }

        $availabilityPct = null;
        if ($totalJam > 0) {
            $uptime = max(0.0, $totalJam - $totalLoss);
            $availabilityPct = round($uptime / $totalJam * 100, 2);

            /*
             * Kerugian melebihi jam terjadwal. Nyata, bukan teoretis: perbaikan
             * dijalankan hari Minggu, atau dandori menembus batas shift. Angka
             * 0% tanpa catatan terbaca seperti data biasa; dengan catatan,
             * orang tahu penyebutnya yang perlu diperiksa, bukan mesinnya.
             */
            if ($totalLoss > $totalJam) {
                $catatan[] = sprintf(
                    'Kerugian (%.1f jam) melebihi jam terjadwal (%.1f jam) — Availability dijepit di 0%%. '
                    .'Cek breakdown/dandori yang tercatat di luar jam kerja.',
                    $totalLoss,
                    $totalJam
                );
            }
        } else {
            $catatan[] = 'Availability tak bisa dihitung: nol jam kerja terjadwal di rentang ini '
                .'(hari Minggu/libur, atau belum ada mesin dengan tanggal instalasi terisi).';
        }

        if ($mesinTanpaInstalasi > 0 && $availabilityPct !== null) {
            $catatan[] = "{$mesinTanpaInstalasi} mesin diabaikan dari Availability karena tanggal instalasi belum diset.";
        }

        /* ── Performance & Quality dari LHP produksi ───────────────────── */
        $rows = TransaksiProduksi::with('partProses:id,sph_standar_produksi')
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->get(['id_mesin', 'id_part_proses', 'qty_aktual', 'qty_ng', 'jam_start', 'jam_finish']);

        $totalQty = 0;
        $totalNg = 0;
        $tanpaStd = 0;

        // Dikumpulkan PER MESIN, bukan langsung dijumlah: waktu dandori harus
        // dikeluarkan dari penyebut menurut mesinnya sendiri.
        $teoretisPerMesin = [];
        $jamPerMesin = [];

        foreach ($rows as $r) {
            $totalQty += (int) $r->qty_aktual;
            // qty_ng nullable di DB — (int) null = 0, tapi ditulis eksplisit
            // supaya niatnya jelas dan tidak "dirapikan" jadi ->sum() mentah.
            $totalNg += (int) ($r->qty_ng ?? 0);

            $std = (float) ($r->partProses->sph_standar_produksi ?? 0);
            $jam = (float) $r->waktu_kerja_jam;
            if ($std > 0 && $jam > 0) {
                $mesin = (int) $r->id_mesin;
                $teoretisPerMesin[$mesin] = ($teoretisPerMesin[$mesin] ?? 0.0) + $std * $jam;
                $jamPerMesin[$mesin] = ($jamPerMesin[$mesin] ?? 0.0) + $jam;
            } elseif ($std <= 0) {
                $tanpaStd++;
            }
        }

        $totalProduksi = $totalQty + $totalNg;

        /*
         * ── Waktu dandori dikeluarkan dari penyebut Performance ──
         *
         * Konvensi pencatatan yang disepakati: waktu ganti dies MASUK ke dalam
         * jam_start–jam_finish LHP. Kalau setup dibiarkan di penyebut, ia dihukum
         * DUA KALI — sekali menekan Performance (jam terpakai tanpa output) dan
         * sekali menekan Availability. Di OEE standar penyebut Performance adalah
         * Operating Time, yaitu waktu SETELAH setup dikeluarkan.
         *
         * Diskalakan proporsional per mesin, bukan dikurangi dari baris tertentu:
         * SPH standar berbeda antar part_proses, jadi memilih baris mana yang
         * "kehilangan" jam berarti memilih part mana yang dihukum.
         */
        $teoretis = 0.0;
        $mesinDataMustahil = 0;

        foreach ($teoretisPerMesin as $mesin => $teoretisMesin) {
            $jamMesin = $jamPerMesin[$mesin];
            $dandoriMesin = $dandoriPerMesin[$mesin] ?? 0.0;
            $jamBersih = $jamMesin - $dandoriMesin;

            if ($dandoriMesin <= 0) {
                $teoretis += $teoretisMesin;

                continue;
            }

            if ($jamBersih <= 0) {
                /*
                 * MUSTAHIL di konvensi "include": dandori tak bisa lebih lama dari
                 * jam LHP yang memuatnya. Berarti LHP mesin ini diisi TANPA
                 * memasukkan dandori — konvensi lama, tercampur.
                 *
                 * Kontribusinya dibuang, bukan dipaksa: memakai angka apa pun di
                 * sini menghasilkan Performance yang optimis dan SALAH, dan angka
                 * optimis yang salah menyembunyikan masalah.
                 */
                $mesinDataMustahil++;

                continue;
            }

            $teoretis += $teoretisMesin * ($jamBersih / $jamMesin);
        }

        $performancePct = $teoretis > 0 ? round($totalProduksi / $teoretis * 100, 2) : null;
        $qualityPct = $totalProduksi > 0 ? round($totalQty / $totalProduksi * 100, 2) : null;

        if ($rows->isEmpty()) {
            $catatan[] = 'Tidak ada LHP produksi di rentang ini: Performance & Quality tak bisa dihitung.';
        } elseif ($performancePct === null) {
            $catatan[] = 'Performance tak bisa dihitung: SPH standar proses belum diset.';
        } elseif ($tanpaStd > 0) {
            $catatan[] = "{$tanpaStd} LHP diabaikan dari Performance karena SPH standar belum diset.";
        }

        if ($mesinDataMustahil > 0) {
            $catatan[] = "{$mesinDataMustahil} mesin diabaikan dari Performance: waktu dandori melebihi jam LHP-nya. "
                .'Kemungkinan LHP diisi TANPA memasukkan waktu dandori (konvensi lama, tercampur).';
        }

        $oeePct = ($availabilityPct !== null && $performancePct !== null && $qualityPct !== null)
            ? round($availabilityPct / 100 * $performancePct / 100 * $qualityPct / 100 * 100, 2)
            : null;

        return [
            'availability_pct' => $availabilityPct,
            'performance_pct' => $performancePct,
            'quality_pct' => $qualityPct,
            'oee_pct' => $oeePct,
            'catatan' => $catatan,
        ];
    }

    /**
     * Batas seberapa jauh ke belakang pencarian hari kerja boleh menjelajah.
     *
     * Pengali, bukan angka mati: 6 hari tren → maksimal 36 hari kalender.
     * Ada supaya libur panjang (mis. cuti bersama Idul Fitri) tidak membuat
     * loop berjalan tanpa ujung, dan supaya konfigurasi hari libur yang salah
     * isi berhenti di batas yang jelas alih-alih menggantung request.
     */
    private const PENGALI_JELAJAH_TREN = 6;

    /**
     * Apakah tanggal ini hari kerja pabrik.
     *
     * Minggu diturunkan dari tanggalnya, TIDAK perlu didaftarkan di config.
     * Konsisten dengan master_shift yang cuma punya kolom jam Senin–Kamis,
     * Jumat, dan Sabtu — nol kolom Minggu, jadi struktur DB memang sudah
     * menyatakan Minggu bukan hari kerja.
     *
     * Tanggal merah & cuti bersama datang dari config/hari_libur.php karena
     * tidak mungkin diturunkan: keputusan pemerintah, berubah tiap tahun.
     */
    public static function hariKerja(Carbon $tgl): bool
    {
        // Delegasi, bukan salinan: aturan yang sama kini juga menentukan jam
        // terjadwal untuk Availability. Dua salinan akan melenceng, dan
        // melencengnya senyap — tren menyembunyikan hari yang jam terjadwalnya
        // tetap dihitung, atau sebaliknya.
        return KalenderKerjaService::hariKerja($tgl);
    }

    /**
     * Tren achievement & OEE per HARI KERJA, mundur dari tanggal acuan.
     *
     * Minggu dan hari libur dilewati, bukan ditampilkan kosong. Kolom kosong di
     * sumbu memakan ruang untuk nol informasi dan membuat tren terlihat anjlok
     * padahal pabriknya memang tutup.
     *
     * ⚠️ Konsekuensinya jumlah titik tetap $hari tapi rentang KALENDER-nya
     * melebar. Label panel karena itu harus menyebut "hari kerja", bukan
     * "days" — kalau tidak, orang membaca 6 titik sebagai 6 hari terakhir.
     *
     * HARI LIBUR YANG PUNYA LHP TETAP DITAMPILKAN. Lembur di hari libur itu
     * nyata, dan menyembunyikan hari yang punya data lebih buruk daripada
     * menampilkan hari libur: angkanya hilang dari tren tanpa jejak. Karena itu
     * kelayakan sebuah tanggal = hari kerja ATAU ada produksinya.
     *
     * OEE dihitung ULANG per hari (bukan rentang penuh sekali) karena
     * Availability tidak linier terhadap panjang rentang: penyebutnya jam
     * jendela, jadi angka 6 hari bukan rata-rata 6 angka harian.
     *
     * @return list<array{tanggal: string, label: string, achievement_pct: float|null, oee_pct: float|null}>
     */
    /** Titik harian maksimum sebelum tren beralih ke agregasi mingguan. */
    public const MAKS_TITIK_HARIAN = 31;

    /**
     * Tren achievement & OEE pada sebuah RENTANG.
     *
     * Dua mode, dipilih dari panjang rentangnya:
     *   ≤ 31 hari kerja → satu titik per hari kerja
     *   > 31 hari kerja → satu titik per minggu ISO
     *
     * Batas itu bukan kosmetik. Tiap titik memanggil achievement() DAN oee(),
     * dan oee() memanggil MaintenanceDashboardService::compute() — sekitar lima
     * query per titik. Rentang enam bulan tanpa batas ini berarti ~130 titik dan
     * ~650 query dalam satu request. Agregasi mingguan menahannya di ~26 titik
     * apa pun panjang rentangnya, dan grafik dengan 130 titik juga tak terbaca.
     *
     * RENTANG SATU HARI diperlebar mundur menjadi HARI_TREN hari kerja yang
     * berakhir di hari itu. Aturan yang sama berlaku untuk keadaan default
     * (tanpa filter, rentangnya satu hari) maupun untuk pengguna yang memilih
     * satu tanggal — jadi tidak ada cabang "sedang difilter atau tidak" yang
     * bisa melenceng. Efeknya default halaman ini tidak berubah sama sekali:
     * tetap enam titik seperti sebelum filter ada.
     *
     * Minggu dan hari libur dilewati; hari libur yang PUNYA LHP tetap muncul.
     * Lihat hariKerja() dan catatan di config/hari_libur.php.
     *
     * @return list<array{tanggal: string, label: string, achievement_pct: float|null, oee_pct: float|null}>
     */
    public function tren(Carbon $from, ?Carbon $to = null, int $hari = self::HARI_TREN): array
    {
        $to ??= $from;

        $hariKerja = $from->isSameDay($to)
            ? $this->hariKerjaMundur($to, $hari)
            : $this->hariKerjaDalamRentang($from, $to);

        if ($hariKerja === []) {
            return [];
        }

        return count($hariKerja) <= self::MAKS_TITIK_HARIAN
            ? $this->trenHarian($hariKerja)
            : $this->trenMingguan($hariKerja);
    }

    /** @param list<Carbon> $hariKerja */
    private function trenHarian(array $hariKerja): array
    {
        $hasil = [];
        foreach ($hariKerja as $tgl) {
            $hasil[] = [
                'tanggal' => $tgl->toDateString(),
                'label' => $tgl->format('d-M'),
                'achievement_pct' => $this->achievement($tgl, $tgl)['pct'],
                'oee_pct' => $this->oee($tgl, $tgl)['oee_pct'],
            ];
        }

        return $hasil;
    }

    /**
     * Satu titik per minggu ISO.
     *
     * Batas mingguannya DIJEPIT ke hari kerja pertama & terakhir yang benar-benar
     * ada di minggu itu, bukan Senin–Minggu penuh. Tanpa penjepitan, minggu di
     * ujung rentang menarik tanggal di luar rentang yang diminta ke dalam
     * hitungan — angkanya jadi memuat hari yang tidak pernah dipilih.
     *
     * @param  list<Carbon>  $hariKerja
     */
    private function trenMingguan(array $hariKerja): array
    {
        $perMinggu = [];
        foreach ($hariKerja as $tgl) {
            $perMinggu[$tgl->isoWeekYear.'-'.str_pad((string) $tgl->isoWeek, 2, '0', STR_PAD_LEFT)][] = $tgl;
        }

        $hasil = [];
        foreach ($perMinggu as $kunci => $hari) {
            $awal = $hari[0];
            $akhir = $hari[count($hari) - 1];

            $hasil[] = [
                'tanggal' => $awal->toDateString(),
                // Label pakai tanggal awal minggunya, bukan nomor minggu: nomor
                // minggu ISO tidak dikenali orang produksi tanpa melihat kalender.
                'label' => $awal->format('d-M'),
                'achievement_pct' => $this->achievement($awal, $akhir)['pct'],
                'oee_pct' => $this->oee($awal, $akhir)['oee_pct'],
            ];
        }

        return $hasil;
    }

    /**
     * Hari kerja MUNDUR dari sebuah tanggal, sebanyak $hari.
     *
     * @return list<Carbon>
     */
    private function hariKerjaMundur(Carbon $akhir, int $hari): array
    {
        $jelajahMaks = $hari * self::PENGALI_JELAJAH_TREN;
        $adaProduksi = $this->tanggalAdaProduksi($akhir->copy()->subDays($jelajahMaks), $akhir);

        $tanggal = [];
        $kursor = $akhir->copy();

        for ($langkah = 0; $langkah <= $jelajahMaks && count($tanggal) < $hari; $langkah++) {
            if ($this->layakTampil($kursor, $adaProduksi)) {
                $tanggal[] = $kursor->copy();
            }
            $kursor->subDay();
        }

        return array_reverse($tanggal);
    }

    /**
     * Semua hari kerja DI DALAM sebuah rentang.
     *
     * @return list<Carbon>
     */
    private function hariKerjaDalamRentang(Carbon $from, Carbon $to): array
    {
        $adaProduksi = $this->tanggalAdaProduksi($from, $to);

        $tanggal = [];
        $kursor = $from->copy()->startOfDay();
        $akhir = $to->copy()->startOfDay();

        while ($kursor->lte($akhir)) {
            if ($this->layakTampil($kursor, $adaProduksi)) {
                $tanggal[] = $kursor->copy();
            }
            $kursor->addDay();
        }

        return $tanggal;
    }

    /**
     * Hari libur yang PUNYA LHP tetap layak tampil.
     *
     * Lembur di hari libur itu nyata, dan menyembunyikan hari yang punya data
     * membuat angkanya hilang dari tren tanpa jejak.
     *
     * @param  list<string>  $adaProduksi
     */
    private function layakTampil(Carbon $tgl, array $adaProduksi): bool
    {
        return self::hariKerja($tgl) || in_array($tgl->toDateString(), $adaProduksi, true);
    }

    /**
     * Tanggal yang punya LHP dalam sebuah jendela — SATU query, bukan per hari.
     *
     * @return list<string>
     */
    private function tanggalAdaProduksi(Carbon $from, Carbon $to): array
    {
        return TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->distinct()
            ->pluck('tanggal_produksi')
            ->map(fn ($t) => Carbon::parse($t)->toDateString())
            ->all();
    }

    /**
     * Pareto loss time dandori per jenis, urut menurun.
     *
     * Yang DIGRAFIKKAN adalah over_menit — waktu yang LEWAT DARI STANDAR —
     * supaya sama dengan halaman VCB Dandori. Satuan MENIT, bukan jam.
     *
     *     over_menit = max(0, actual_menit − std_menit)
     *
     * actual_menit tetap dibawa serta di kunci 'aktual', dan itu bukan hiasan:
     * Availability mengurangi actual_menit, bukan over_menit (lihat
     * MaintenanceDashboardService). Tanpa angka aktual di sini, halaman ini
     * memajang Pareto yang jumlahnya tidak pernah bisa direkonsiliasi dengan
     * angka OEE di sebelahnya — dan orang wajar menyimpulkan salah satunya
     * salah hitung. Aktual ditampilkan di tooltip: nol biaya ruang di layout
     * yang sudah terkunci setinggi viewport.
     *
     * Diagregasi di DB (GROUP BY), bukan di PHP: all-time bisa puluhan ribu
     * baris dan memuat semuanya ke memori pernah membunuh suite lokal secara
     * diam-diam.
     *
     * leftJoin + deleted_at IS NULL meniru eager-load ->with('losstime'):
     * losstime yang sudah dihapus jangan menghilangkan barisnya, tapi juga
     * jangan memakai namanya.
     *
     * @param  Carbon|null  $from  null = tanpa batas bawah (ALL TIME)
     * @param  Carbon|null  $to  null = tanpa batas atas
     * @return list<array{jenis: string, menit: float, aktual: float}>
     */
    public function lossTime(?Carbon $from = null, ?Carbon $to = null): array
    {
        return TransaksiDandoriRincian::query()
            ->leftJoin('master_losstime', function ($join) {
                $join->on('transaksi_dandori_rincian.id_losstime', '=', 'master_losstime.id')
                    ->whereNull('master_losstime.deleted_at');
            })
            ->when($from, fn ($q) => $q->whereDate('transaksi_dandori_rincian.tanggal', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('transaksi_dandori_rincian.tanggal', '<=', $to->toDateString()))
            ->groupBy('master_losstime.jenis_losstime')
            ->select([
                DB::raw("COALESCE(master_losstime.jenis_losstime, 'Tidak diketahui') as jenis"),
                DB::raw('SUM(transaksi_dandori_rincian.over_menit) as menit'),
                DB::raw('SUM(transaksi_dandori_rincian.actual_menit) as aktual'),
            ])
            ->orderByDesc('menit')
            ->get()
            ->map(fn ($r) => [
                // Master menyimpan 'Tunggu_Dies'; yang dibaca orang 'Tunggu Dies'.
                // Penggantian di lapisan tampilan, bukan di data.
                'jenis' => str_replace('_', ' ', (string) $r->jenis),
                'menit' => round((float) $r->menit, 1),
                'aktual' => round((float) $r->aktual, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * Tiga dataset Pareto untuk tombol LAST 24 H / THIS MONTH / ALL TIME.
     *
     * Ketiganya dihitung di server sekaligus, bukan lewat endpoint AJAX per
     * klik: hasilnya sudah teragregasi per jenis (belasan baris, bukan ribuan),
     * jadi mengirim ketiganya lebih murah daripada menambah route + state
     * loading di browser.
     *
     * @return array<string, list<array{jenis: string, menit: float}>>
     */
    public function lossTimeSemuaScope(Carbon $from, ?Carbon $to = null): array
    {
        $to ??= $from;

        /*
         * DUA scope, bukan tiga. Dulu harian/bulanan/semua — tiga tombol yang
         * masing-masing menetapkan rentangnya sendiri, terpisah dari rentang
         * halaman. Setelah halaman punya filter tanggal, dua dari tiga tombol itu
         * jadi kontrol kedua untuk dimensi yang sama, dan panel Pareto bisa
         * menampilkan rentang berbeda dari tujuh panel lain di halaman yang sama.
         *
         * 'semua' DIPERTAHANKAN karena ia pertanyaan yang berbeda, bukan rentang
         * yang berbeda: penyumbang loss terbesar sepanjang masa, lepas dari
         * periode apa pun yang sedang dilihat.
         */
        return [
            'rentang' => $this->lossTime($from, $to),
            'semua' => $this->lossTime(),
        ];
    }

    /** Jenis loss time yang terdaftar di master tapi nol kejadian — tidak dipakai grafik, dipakai catatan. */
    public function jumlahJenisLosstime(): int
    {
        return MasterLosstime::count();
    }

    /** Ambang bawah: di bawah ini warnanya merah penuh. */
    public const AMBANG_MERAH = 20.0;

    /** Ambang atas: di atas ini warnanya hijau penuh. */
    public const AMBANG_HIJAU = 80.0;

    /**
     * Warna cincin donut menurut nilai achievement-nya.
     *
     * ≤20% merah penuh, ≥80% hijau penuh, di antaranya digradasi lewat kuning.
     * Angkanya diinterpolasi, bukan dipetakan ke tiga kotak diskret: batas
     * mendadak membuat 79% dan 81% terlihat seperti dua kelas berbeda padahal
     * selisihnya dua poin.
     *
     * Tiga simpul warnanya SENGAJA mengambil aksen fungsional design system
     * (--c-danger, aksen Anomali LHP, --c-success), bukan merah/kuning/hijau
     * penuh. Warna jenuh menabrak palet monokrom industrial di seluruh app.
     *
     * Ada di service, bukan di JS, karena dua alasan: nilainya dipakai DUA
     * tempat (dataset donut dan kotak legenda, yang harus selalu sama), dan
     * ambang 20/80 jadi bisa diuji alih-alih jadi angka sakti di dalam Blade.
     *
     * null = achievement tak bisa dihitung → abu netral, bukan merah. Merah
     * berarti "buruk"; tak ada data bukan buruk, cuma tak diketahui.
     */
    public static function warnaAchievement(?float $pct): string
    {
        if ($pct === null) {
            return '#e5e7eb';
        }

        $simpul = [
            [185, 28, 28],   // #b91c1c  --c-danger
            [202, 138, 4],   // #ca8a04  aksen Anomali LHP
            [21, 128, 61],   // #15803d  --c-success
        ];

        $t = max(0.0, min(1.0, ($pct - self::AMBANG_MERAH) / (self::AMBANG_HIJAU - self::AMBANG_MERAH)));

        // Dua segmen: merah→kuning lalu kuning→hijau.
        [$dari, $ke, $lokal] = $t < 0.5
            ? [$simpul[0], $simpul[1], $t * 2]
            : [$simpul[1], $simpul[2], ($t - 0.5) * 2];

        $rgb = array_map(
            fn (int $a, int $b) => (int) round($a + ($b - $a) * $lokal),
            $dari,
            $ke
        );

        return sprintf('#%02x%02x%02x', ...$rgb);
    }
}
