<?php

namespace App\Http\Controllers;

use App\Services\OverviewService;
use Illuminate\View\View;

/**
 * Overview grafik global pabrik.
 *
 * Controller sendiri, bukan method di ChatbotController: halaman ini tidak
 * ada hubungannya dengan AI. Menaruhnya di ChatbotController akan menyeret
 * empat dependensi provider chatbot ke halaman yang tidak memakainya, dan
 * menyesatkan orang berikutnya yang mencari kode grafik ini.
 *
 * Semua perhitungan ada di OverviewService — controller hanya merangkai.
 */
class OverviewController extends Controller
{
    public function __construct(private OverviewService $svc) {}

    public function index(): View
    {
        $acuan = $this->svc->tanggalAcuan();

        // Belum ada satu pun LHP produksi: render halaman kosong yang MENGATAKAN
        // begitu, bukan grafik nol yang terlihat seperti pabrik berhenti.
        if ($acuan === null) {
            return view('overview.index', ['kosong' => true]);
        }

        $oee = $this->svc->oee($acuan, $acuan);
        $tren = $this->svc->tren($acuan);
        $lossTime = $this->svc->lossTimeSemuaScope($acuan);

        return view('overview.index', [
            'kosong' => false,
            'acuan' => $acuan,
            'achievement' => $this->svc->achievement($acuan, $acuan),
            'perShift' => $this->svc->achievementPerShift($acuan, $acuan),
            'oee' => $oee,
            'tren' => $tren,
            'lossTime' => $lossTime,
        ]);
    }
}
