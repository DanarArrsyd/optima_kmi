<?php

namespace Tests\Unit\Chatbot;

use App\Services\Chatbot\BusinessTableCatalog;
use App\Services\Chatbot\SqlGuard;
use Tests\TestCase;

/**
 * Modul Repair Produksi (LHR Produksi) menambah dua tabel bisnis baru:
 * master_part_proses_repair_produksi dan transaksi_repair_produksi. Konvensi repo:
 * tabel baru WAJIB masuk BusinessTableCatalog, kalau tidak chatbot membisu soal
 * data ini walau tabelnya ada di DB.
 */
class RepairProduksiAwarenessTest extends TestCase
{
    public function test_katalog_memuat_kedua_tabel_repair_produksi(): void
    {
        $catalog = new BusinessTableCatalog;

        $this->assertTrue($catalog->has('master_part_proses_repair_produksi'), 'master_part_proses_repair_produksi harus terdaftar.');
        $this->assertTrue($catalog->has('transaksi_repair_produksi'), 'transaksi_repair_produksi harus terdaftar.');
    }

    public function test_deskripsi_tabel_repair_produksi_menyebut_kolom_kuncinya(): void
    {
        $catalog = new BusinessTableCatalog;

        $prosesDesc = $catalog->describe('master_part_proses_repair_produksi');
        $this->assertNotNull($prosesDesc);
        $this->assertStringContainsString('sph_standar_repair_produksi', $prosesDesc);

        $repairDesc = $catalog->describe('transaksi_repair_produksi');
        $this->assertNotNull($repairDesc);
        $this->assertStringContainsString('qty_aktual', $repairDesc);
        $this->assertStringContainsString('actual_cycle_time', $repairDesc);
    }

    public function test_sqlguard_mengizinkan_query_ke_tabel_repair_produksi(): void
    {
        $hasil = (new SqlGuard)->sanitize(
            'SELECT id_karyawan, COUNT(*) FROM transaksi_repair_produksi GROUP BY id_karyawan'
        );

        $this->assertArrayNotHasKey('error', $hasil, $hasil['error'] ?? '');
        $this->assertArrayHasKey('sql', $hasil);
    }
}
