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

    /**
     * Bikin skrip stub yang berperan sebagai "mysqldump" palsu: ia menulis
     * beberapa byte ke --result-file (meniru dump yang separuh jalan), lalu
     * keluar dengan kode bukan nol (meniru koneksi putus / kredensial salah).
     * Tidak butuh MySQL sungguhan — cukup shell.
     */
    private function buatStubGagal(): string
    {
        $stub = tempnam(sys_get_temp_dir(), 'optima-stub-').'.sh';
        file_put_contents($stub, <<<'SH'
#!/bin/sh
for arg in "$@"; do
    case "$arg" in
        --result-file=*)
            tujuan="${arg#--result-file=}"
            printf 'dump separuh jadi' > "$tujuan"
            ;;
    esac
done
exit 1
SH
        );
        chmod($stub, 0755);

        return $stub;
    }

    public function test_berkas_separuh_jadi_dihapus_saat_mysqldump_gagal(): void
    {
        $stub = $this->buatStubGagal();
        $berkasSementara = tempnam(sys_get_temp_dir(), 'optima-dump-');
        unlink($berkasSementara); // tempnam bikin berkas kosong — kita cuma butuh namanya
        $tujuan = $berkasSementara.'.sql'; // belum ada — stub yang akan membuatnya

        try {
            $pembuat = new MysqldumpPembuatDump($stub);

            $this->expectException(\RuntimeException::class);

            try {
                $pembuat->keBerkas($tujuan);
            } finally {
                // Dicek di finally supaya tetap tertangkap walau assertion
                // exception di atas yang menutup tes: berkas separuh jadi
                // tidak boleh mendarat di disk.
                $this->assertFileDoesNotExist(
                    $tujuan,
                    'Berkas dump separuh jadi harus dihapus saat mysqldump gagal, bukan dibiarkan mendarat di disk.'
                );
            }
        } finally {
            @unlink($stub);
            @unlink($tujuan);
        }
    }
}
