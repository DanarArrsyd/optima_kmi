<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Satu-satunya tempat logika TOTP hidup — setup, verifikasi, recovery code.
 * QR dirender jadi SVG LOKAL (BaconQrCode), bukan lewat API pihak ketiga —
 * secret 2FA tidak pernah keluar server hanya untuk digambar jadi gambar.
 */
class TwoFactorAuthService
{
    private Google2FA $engine;

    public function __construct()
    {
        $this->engine = new Google2FA;
    }

    public function generateSecretKey(): string
    {
        return $this->engine->generateSecretKey();
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->engine->getQRCodeUrl(config('app.name'), $user->email, $secret);

        $renderer = new ImageRenderer(new RendererStyle(200), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($url);
    }

    public function verifyCode(string $secret, string $code): bool
    {
        return $this->engine->verifyKey($secret, $code);
    }

    /** @return list<string> 8 kode sekali-pakai, plain text — HANYA ditampilkan sekali saat konfirmasi. */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::random(10).'-'.Str::random(10),
            range(1, 8)
        );
    }

    /** @param  list<string>  $recoveryCodesPlain */
    public function confirm(User $user, string $secret, array $recoveryCodesPlain): void
    {
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => array_map(fn ($c) => bcrypt($c), $recoveryCodesPlain),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
    }

    /** Kode recovery SEKALI PAKAI — begitu cocok, langsung dicoret dari daftar. */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $i => $hash) {
            if (password_verify($code, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }
}
