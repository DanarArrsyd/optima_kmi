<?php

namespace App\Http\Controllers;

use App\Services\TwoFactorAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\View\View;

/**
 * Setup/nonaktifkan 2FA — dibatasi role admin lewat route (lihat routes/web.php).
 * Login penuh dengan 2FA ada di TwoFactorChallengeController, bukan di sini.
 */
class TwoFactorAuthController extends Controller
{
    public function __construct(private TwoFactorAuthService $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return view('profile.two-factor', ['enabled' => true]);
        }

        // Secret baru tiap kunjungan halaman setup — belum dikonfirmasi = belum tersimpan di user.
        $secret = $request->session()->get('2fa.setup.secret') ?? $this->twoFactor->generateSecretKey();
        $request->session()->put('2fa.setup.secret', $secret);

        return view('profile.two-factor', [
            'enabled' => false,
            'qrSvg' => $this->twoFactor->qrCodeSvg($user, $secret),
            'secret' => $secret,
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $secret = $request->session()->get('2fa.setup.secret');
        if (! $secret) {
            return Redirect::route('profile.two-factor')->with('error', 'Sesi setup kedaluwarsa, ulangi dari awal.');
        }

        if (! $this->twoFactor->verifyCode($secret, trim($request->string('code')))) {
            return Redirect::route('profile.two-factor')->withErrors(['code' => 'Kode tidak valid.']);
        }

        $recoveryCodes = $this->twoFactor->generateRecoveryCodes();
        $this->twoFactor->confirm($request->user(), $secret, $recoveryCodes);
        $request->session()->forget('2fa.setup.secret');

        // Recovery code plain text HANYA muncul sekali, lewat flash — nol disimpan plain di mana pun.
        return Redirect::route('profile.two-factor')->with('recovery_codes', $recoveryCodes);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('twoFactorDisable', [
            'password' => ['required', 'current_password'],
        ]);

        $this->twoFactor->disable($request->user());

        return Redirect::route('profile.two-factor')->with('status', 'two-factor-disabled');
    }
}
