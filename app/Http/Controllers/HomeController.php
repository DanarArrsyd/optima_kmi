<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function root()
    {
        return redirect('/dashboard');
    }

    /**
     * User bisa punya banyak role sekarang. Urutan prioritas di bawah SAMA
     * PERSIS dengan versi role-tunggal lama — cuma diperiksa pakai hasRole()
     * (array_intersect) alih-alih perbandingan string tunggal — supaya user
     * yang cuma punya satu role mendarat di halaman yang sama seperti
     * sebelumnya, nol kejutan. Yang baru cuma: staff_maintenance menang
     * duluan kalau dia SALAH SATU dari beberapa role user, bukan satu-
     * satunya role.
     */
    public function dashboard()
    {
        $user = Auth::user();

        return redirect(match (true) {
            $user->hasRole('staff_maintenance') => '/maintenance/mttr',
            $user->hasRole(['admin', 'staff_produksi', 'manager']) => '/vcb/produksi',
            $user->hasRole('staff_qc') => '/vcb/quality',
            // staff_repair tidak boleh membuka VCB mana pun — tanpa cabang
            // ini dia jatuh ke default dan langsung 403 setelah login.
            $user->hasRole('staff_repair') => '/input/repair',
            $user->hasRole('guest') => '/vcb/produksi',
            default => '/vcb/produksi',
        });
    }
}
