<?php

namespace App\Http\Controllers;

use App\Services\OverviewService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Overview grafik global pabrik.
 *
 * Controller sendiri, bukan method di ChatbotController: halaman ini tidak
 * ada hubungannya dengan AI. Menaruhnya di ChatbotController akan menyeret
 * empat dependensi provider chatbot ke halaman yang tidak memakainya, dan
 * menyesatkan orang berikutnya yang mencari kode grafik ini.
 *
 * Semua perhitungan ada di OverviewService — controller hanya merangkai dan
 * menormalkan rentang tanggal dari request.
 */
class OverviewController extends Controller
{
    public function __construct(private OverviewService $svc) {}

    public function index(Request $request): View
    {
        $acuan = $this->svc->tanggalAcuan();

        // Belum ada satu pun LHP produksi: render halaman kosong yang MENGATAKAN
        // begitu, bukan grafik nol yang terlihat seperti pabrik berhenti.
        if ($acuan === null) {
            return view('overview.index', ['kosong' => true]);
        }

        [$from, $to] = $this->rentang($request, $acuan);

        $oee = $this->svc->oee($from, $to);

        return view('overview.index', [
            'kosong' => false,
            'acuan' => $acuan,
            'from' => $from,
            'to' => $to,
            'difilter' => ! $from->isSameDay($acuan) || ! $to->isSameDay($acuan),
            'labelRentang' => $this->labelRentang($from, $to),
            'achievement' => $this->svc->achievement($from, $to),
            'perShift' => $this->svc->achievementPerShift($from, $to),
            'oee' => $oee,
            'tren' => $this->svc->tren($from, $to),
            'lossTime' => $this->svc->lossTimeSemuaScope($from, $to),
        ]);
    }

    /**
     * Rentang dari query string, dinormalkan.
     *
     * Lewat query string (bukan POST/session) supaya periode bisa DI-BOOKMARK dan
     * dibagikan — manajemen yang melihat "Agustus 2026" bisa mengirim tautannya,
     * dan halaman tetap benar setelah di-refresh.
     *
     * Default = tanggal acuan, satu hari. Nol perubahan untuk papan di lantai
     * produksi: yang berubah hanya bagi orang yang benar-benar menyentuh filter.
     *
     * Tanggal tak valid DIABAIKAN, bukan membuat halaman error. Halaman ini juga
     * dibuka guest dan dipajang di lantai produksi; satu query string rusak tidak
     * boleh mematikan papannya. Yang salah diam-diam jatuh ke default, dan
     * default-nya selalu masuk akal.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rentang(Request $request, Carbon $acuan): array
    {
        $from = $this->tanggal($request->query('date_from')) ?? $acuan->copy();
        $to = $this->tanggal($request->query('date_to')) ?? $from->copy();

        // Terbalik → ditukar, bukan ditolak. Salah urutan itu salah ketik, dan
        // menampilkan pesan error untuk hal yang jelas maksudnya cuma menghalangi.
        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        return [$from->startOfDay(), $to->startOfDay()];
    }

    private function tanggal(mixed $nilai): ?Carbon
    {
        if (! is_string($nilai) || $nilai === '') {
            return null;
        }

        try {
            $tgl = Carbon::createFromFormat('Y-m-d', $nilai);
        } catch (\Throwable) {
            return null;
        }

        /*
         * Pemeriksaan pulang-balik, bukan cuma createFromFormat().
         *
         * createFromFormat TIDAK melempar untuk '2026-13-45' — ia MENGGULUNG:
         * bulan 13 jadi Januari tahun depan, tanggal 45 jadi pertengahan
         * Februari, hasilnya 2027-02-14. Tanggal yang jelas ngawur berubah jadi
         * tanggal valid yang SALAH, dan halaman menampilkan periode yang tak
         * pernah diminta siapa pun tanpa satu tanda pun.
         *
         * Kalau hasil format ulangnya tidak identik dengan masukannya, berarti
         * ada penggulungan. Tolak.
         */
        return $tgl->format('Y-m-d') === $nilai ? $tgl->startOfDay() : null;
    }

    /**
     * Label rentang untuk header panel, mis. "03 AUG" atau "01–31 AUG".
     *
     * Menggantikan "LAST 24 H" yang dulu di-hardcode di delapan panel. Label itu
     * BOHONG begitu rentangnya lebih dari sehari, dan panel yang menampilkan
     * angka sebulan dengan label sehari lebih buruk daripada tidak ada label.
     *
     * Ringkas dengan sengaja: header panel dipaksa satu baris pada font 10px,
     * dan label panjang akan terpangkas elipsis.
     */
    private function labelRentang(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return strtoupper($from->format('d M Y'));
        }

        if ($from->isSameMonth($to)) {
            return strtoupper($from->format('d').'–'.$to->format('d M Y'));
        }

        return strtoupper($from->format('d M').' – '.$to->format('d M Y'));
    }
}
