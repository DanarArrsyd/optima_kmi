<?php

namespace App\Services\Backup;

use App\Contracts\PembuatDump;
use RuntimeException;
use Symfony\Component\Process\Process;

class MysqldumpPembuatDump implements PembuatDump
{
    public function __construct(private string $biner = '/usr/bin/mysqldump') {}

    /**
     * Argumen dipisah dari eksekusi supaya bisa diuji tanpa MySQL.
     *
     * Kata sandi TIDAK pernah masuk ke sini — ia dititipkan lewat berkas konfig
     * mode 0600, karena argumen perintah terlihat di daftar proses seluruh mesin
     * dan ini shared hosting.
     */
    public function perintah(string $tujuan, string $berkasKonfig): array
    {
        $k = config('database.connections.mysql');

        return [
            $this->biner,
            '--defaults-extra-file='.$berkasKonfig,
            '--host='.$k['host'],
            '--port='.$k['port'],
            '--user='.$k['username'],
            '--single-transaction',
            '--quick',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            '--result-file='.$tujuan,
            $k['database'],
        ];
    }

    public function keBerkas(string $tujuan): void
    {
        $konfig = tempnam(sys_get_temp_dir(), 'optima-dump-');
        chmod($konfig, 0600);
        file_put_contents($konfig, "[client]\npassword=\"".config('database.connections.mysql.password')."\"\n");

        try {
            $proses = new Process($this->perintah($tujuan, $konfig));
            $proses->setTimeout(600);
            $proses->run();

            if (! $proses->isSuccessful()) {
                // Yang dicatat pesan galat dan kode keluar — BUKAN barisnya,
                // supaya kredensial tidak pernah mendarat di log.
                throw new RuntimeException(
                    'mysqldump gagal (kode '.$proses->getExitCode().'): '.trim($proses->getErrorOutput())
                );
            }
        } finally {
            @unlink($konfig);
        }
    }
}
