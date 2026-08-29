<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Spatie\Activitylog\Models\Activity;

/**
 * spatie/laravel-activitylog cuma nyatat perubahan model (create/update/delete)
 * secara otomatis — login/logout/gagal login harus ditulis manual di sini
 * supaya Log Aktivitas juga menangkap siapa masuk-keluar sistem.
 */
class LogAuthActivity
{
    /** Klik ganda / submit ganda pada form login-logout tidak boleh jadi dua baris log. */
    private const JENDELA_DEDUP_DETIK = 3;

    public function handleLogin(Login $event): void
    {
        if ($this->baruSajaDicatat($event->user->id, 'login')) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->event('login')
            ->log('Login berhasil');
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user) {
            return;
        }

        if ($this->baruSajaDicatat($event->user->id, 'logout')) {
            return;
        }

        activity('auth')
            ->causedBy($event->user)
            ->event('logout')
            ->log('Logout');
    }

    private function baruSajaDicatat(int $causerId, string $event): bool
    {
        return Activity::query()
            ->where('log_name', 'auth')
            ->where('event', $event)
            ->where('causer_id', $causerId)
            ->where('created_at', '>=', now()->subSeconds(self::JENDELA_DEDUP_DETIK))
            ->exists();
    }

    public function handleFailed(Failed $event): void
    {
        activity('auth')
            ->event('failed_login')
            ->withProperties(['email' => $event->credentials['email'] ?? null])
            ->log('Login gagal');
    }
}
