<?php

namespace Tests\Feature\Sistem;

use App\Services\BackupService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BackupBukaKunciPerintahTest extends TestCase
{
    public function test_melepas_lock_yang_sedang_dipegang(): void
    {
        // Simulasikan lock 'backup-berjalan' yang macet: dipegang tapi tidak
        // pernah dilepas (persis request manual yang dibunuh paksa PHP-FPM
        // sebelum blok `finally` BackupService::jalankan() sempat jalan).
        $kunci = Cache::lock(BackupService::NAMA_LOCK, 1800);
        $this->assertTrue($kunci->get());

        $this->artisan('backup:buka-kunci')->assertSuccessful();

        // Bukti nyata lock sungguhan lepas: pemegang BARU (owner token
        // berbeda) sekarang bisa mengambilnya, bukan cuma perintahnya keluar
        // dengan kode sukses.
        $kunciBaru = Cache::lock(BackupService::NAMA_LOCK, 10);
        $this->assertTrue($kunciBaru->get());
        $kunciBaru->release();
    }

    public function test_aman_dipanggil_saat_tidak_ada_lock_yang_dipegang(): void
    {
        $this->artisan('backup:buka-kunci')->assertSuccessful();
    }
}
