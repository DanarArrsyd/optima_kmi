<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan dasar yang sebelumnya tidak ada sama sekali.
 *
 * Catatan CSP: aplikasi ini memakai style inline di hampir setiap Blade dan
 * Alpine.js (yang butuh 'unsafe-eval'), jadi script-src belum bisa dikunci
 * penuh tanpa refactor besar. Yang dipasang di sini membatasi ASAL script ke
 * diri sendiri + CDN yang memang dipakai (Bootstrap/Chart.js/Alpine lewat
 * jsDelivr, sudah ber-SRI), sehingga payload yang tersuntik lewat data tidak
 * bisa memuat script dari domain penyerang.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "font-src 'self' https://cdn.jsdelivr.net data:",
            "img-src 'self' data: blob:",
            "connect-src 'self'",
            "frame-ancestors 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'upgrade-insecure-requests',
        ]);

        $headers = [
            'Content-Security-Policy' => $csp,
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()',
        ];

        // HSTS hanya bermakna di HTTPS; jangan dikirim saat dev via http.
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $key => $value) {
            $response->headers->set($key, $value);
        }

        $response->headers->remove('X-Powered-By');

        return $response;
    }
}
