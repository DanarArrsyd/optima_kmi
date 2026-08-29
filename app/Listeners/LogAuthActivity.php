<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * spatie/laravel-activitylog cuma nyatat perubahan model (create/update/delete)
 * secara otomatis — login/logout/gagal login harus ditulis manual di sini
 * supaya Log Aktivitas juga menangkap siapa masuk-keluar sistem.
 */
class LogAuthActivity
{
    public function handleLogin(Login $event): void
    {
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

        activity('auth')
            ->causedBy($event->user)
            ->event('logout')
            ->log('Logout');
    }

    public function handleFailed(Failed $event): void
    {
        activity('auth')
            ->event('failed_login')
            ->withProperties(['email' => $event->credentials['email'] ?? null])
            ->log('Login gagal');
    }
}
