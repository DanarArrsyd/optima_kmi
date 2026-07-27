<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapis kedua proteksi role 'guest': tolak semua request yang mengubah state.
 *
 * Lapis pertama ada di grup route (routes/web.php) — guest hanya terdaftar di
 * grup berisi route GET. Middleware ini menangkap kasus route tulis yang keliru
 * dimasukkan ke grup guest di kemudian hari.
 */
class DenyGuestWrite
{
    /** Route yang tetap boleh dipanggil guest meski method-nya bukan GET. */
    private const ALLOWED_ROUTES = ['logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isGuest() && ! $request->isMethodSafe()) {
            $routeName = $request->route()?->getName();

            if (! in_array($routeName, self::ALLOWED_ROUTES, true)) {
                abort(403, 'Akun tamu hanya bisa melihat data.');
            }
        }

        return $next($request);
    }
}
