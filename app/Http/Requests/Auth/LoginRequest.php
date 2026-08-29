<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * TIDAK selalu login penuh: kalau akun sudah aktifkan 2FA, sesi login
     * DITAHAN (nol Auth::login()) dan menunggu kode TOTP di
     * TwoFactorChallengeController. Caller (AuthenticatedSessionController)
     * membedakan lewat needsTwoFactorChallenge().
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        $provider = Auth::guard('web')->getProvider();
        $user = $provider->retrieveByCredentials($this->only('email', 'password'));

        if (! $user || ! $provider->validateCredentials($user, $this->only('email', 'password'))) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        /* Password diperiksa DULU (di atas), baru status aktif — supaya
         * orang yang salah password tidak ikut diberi tahu akunnya
         * dinonaktifkan. Schema::hasColumn menjaga jendela deploy antara
         * rsync dan migrate: kalau kolomnya belum ada, anggap aktif
         * (fail-open) daripada mengunci SEMUA login sampai migrate jalan. */
        if (Schema::hasColumn('users', 'is_aktif') && ! $user->is_aktif) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                'email' => 'Akun ini sudah dinonaktifkan. Hubungi admin.',
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        if (Schema::hasColumn('users', 'two_factor_confirmed_at') && $user->hasTwoFactorEnabled()) {
            $this->session()->put('2fa.user.id', $user->id);
            $this->session()->put('2fa.remember', $this->boolean('remember'));

            return;
        }

        Auth::login($user, $this->boolean('remember'));
    }

    /** Dipanggil SETELAH authenticate() — true kalau login masih menunggu kode TOTP. */
    public function needsTwoFactorChallenge(): bool
    {
        return ! Auth::check() && $this->session()->has('2fa.user.id');
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
