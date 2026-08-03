<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * Overview grafik global.
 *
 * Controller sendiri, bukan method di ChatbotController: halaman ini tidak
 * ada hubungannya dengan AI. Menaruhnya di ChatbotController akan menyeret
 * empat dependensi provider chatbot ke halaman yang tidak memakainya, dan
 * menyesatkan orang berikutnya yang mencari kode grafik ini.
 */
class OverviewController extends Controller
{
    /**
     * Masih rangka: belum ada data yang dilewatkan karena desain grafiknya
     * belum final. Isi menyusul.
     */
    public function index(): View
    {
        return view('overview.index');
    }
}
