<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sesi yang SUDAH terbuka saat akunnya dinonaktifkan admin tidak otomatis
 * putus — LoginRequest cuma menjaga pintu masuk baru. Middleware ini yang
 * memutus sesi lama: tiap request diperiksa ulang, bukan cuma sekali saat
 * login, supaya mantan pegawai yang masih login di komputer lantai produksi
 * tidak bisa lanjut input walau sesinya belum kedaluwarsa.
 *
 * Global (bukan cuma grup auth), sama seperti DenyGuestWrite — supaya route
 * di routes/auth.php ikut tercakup.
 */
class DenyNonaktifSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        /* Schema::hasColumn menjaga jendela deploy antara rsync dan migrate:
         * kalau kolomnya belum ada, anggap aktif (fail-open) daripada
         * membuang SEMUA sesi yang sedang login sampai migrate jalan. */
        if ($user && Schema::hasColumn('users', 'is_aktif') && ! $user->is_aktif) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            /* Halaman login cuma merender $errors (lihat auth/login.blade.php),
             * bukan session('status') ala Breeze bawaan — withErrors supaya
             * pesannya benar-benar terlihat, bukan menghilang diam-diam. */
            return redirect()->route('login')->withErrors(['email' => 'Akun ini sudah dinonaktifkan.']);
        }

        return $next($request);
    }
}
