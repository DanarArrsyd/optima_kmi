<?php

namespace Tests\Unit;

use App\Services\Backup\MysqldumpPembuatDump;
use Tests\TestCase;

/**
 * Yang diuji penyusunan argumennya, bukan eksekusinya — CI tidak punya MySQL
 * dan mysqldump tidak akan pernah jalan di sana.
 */
class MysqldumpPembuatDumpTest extends TestCase
{
    private function perintah(): array
    {
        config([
            'database.connections.mysql.host' => 'db.contoh',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'optima_db',
            'database.connections.mysql.username' => 'pengguna',
            'database.connections.mysql.password' => 'sandi-rahasia-sekali',
        ]);

        return (new MysqldumpPembuatDump)->perintah('/tmp/dump.sql', '/tmp/konfig.cnf');
    }

    public function test_sandi_tidak_pernah_masuk_argumen(): void
    {
        // Daftar proses terlihat seluruh penyewa di shared hosting.
        foreach ($this->perintah() as $arg) {
            $this->assertStringNotContainsString('sandi-rahasia-sekali', $arg);
        }
    }

    public function test_sandi_diberikan_lewat_defaults_extra_file(): void
    {
        $this->assertContains('--defaults-extra-file=/tmp/konfig.cnf', $this->perintah());
    }

    public function test_memakai_transaksi_tunggal_supaya_dump_konsisten(): void
    {
        // Tanpa ini dump bisa memotret dua tabel pada keadaan yang berbeda.
        $this->assertContains('--single-transaction', $this->perintah());
    }

    public function test_menulis_ke_berkas_tujuan_dan_database_yang_benar(): void
    {
        $perintah = $this->perintah();

        $this->assertContains('--result-file=/tmp/dump.sql', $perintah);
        $this->assertSame('optima_db', end($perintah));
    }
}
