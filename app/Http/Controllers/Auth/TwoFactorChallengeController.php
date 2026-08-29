<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Langkah kedua login untuk akun yang sudah aktifkan 2FA — LoginRequest
 * memverifikasi password lalu menahan sesi di sini (lihat
 * LoginRequest::authenticate()), bukan langsung Auth::login().
 */
class TwoFactorChallengeController extends Controller
{
    public function __construct(private TwoFactorAuthService $twoFactor) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('2fa.user.id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $userId = $request->session()->get('2fa.user.id');
        if (! $userId) {
            return redirect()->route('login');
        }

        $throttleKey = Str::transliterate('2fa|'.$userId.'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'code' => 'Terlalu banyak percobaan. Coba lagi sebentar lagi.',
            ]);
        }

        $request->validate(['code' => ['required', 'string']]);

        $user = User::find($userId);
        if (! $user) {
            return redirect()->route('login');
        }

        $kode = trim($request->string('code'));
        $cocok = $this->twoFactor->verifyCode($user->two_factor_secret, $kode)
            || $this->twoFactor->consumeRecoveryCode($user, $kode);

        if (! $cocok) {
            RateLimiter::hit($throttleKey);

            throw ValidationException::withMessages([
                'code' => 'Kode tidak valid.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = $request->session()->pull('2fa.remember', false);
        $request->session()->forget('2fa.user.id');
        Auth::login($user, $remember);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard', absolute: false));
    }
}
