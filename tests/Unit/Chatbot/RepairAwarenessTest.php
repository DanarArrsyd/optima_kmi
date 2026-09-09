<?php

namespace Tests\Unit\Chatbot;

use App\Services\Chatbot\BusinessTableCatalog;
use App\Services\Chatbot\SqlGuard;
use Tests\TestCase;

/**
 * Modul Repair (LHR) menambah dua tabel bisnis baru: master_part_proses_repair
 * dan transaksi_repair. Konvensi repo: tabel baru WAJIB masuk BusinessTableCatalog,
 * kalau tidak chatbot membisu soal data ini walau tabelnya ada di DB.
 */
class RepairAwarenessTest extends TestCase
{
    public function test_katalog_memuat_kedua_tabel_repair(): void
    {
        $catalog = new BusinessTableCatalog;

        $this->assertTrue($catalog->has('master_part_proses_repair'), 'master_part_proses_repair harus terdaftar.');
        $this->assertTrue($catalog->has('transaksi_repair'), 'transaksi_repair harus terdaftar.');
    }

    public function test_deskripsi_tabel_repair_menyebut_kolom_kuncinya(): void
    {
        $catalog = new BusinessTableCatalog;

        $prosesDesc = $catalog->describe('master_part_proses_repair');
        $this->assertNotNull($prosesDesc);
        $this->assertStringContainsString('sph_standar_repair', $prosesDesc);

        $repairDesc = $catalog->describe('transaksi_repair');
        $this->assertNotNull($repairDesc);
        $this->assertStringContainsString('qty_aktual', $repairDesc);
        $this->assertStringContainsString('actual_cycle_time', $repairDesc);
    }

    public function test_sqlguard_mengizinkan_query_ke_tabel_repair(): void
    {
        $hasil = (new SqlGuard)->sanitize(
            'SELECT id_part_proses_repair, SUM(qty_aktual) AS total FROM transaksi_repair GROUP BY id_part_proses_repair'
        );

        $this->assertArrayNotHasKey('error', $hasil, $hasil['error'] ?? '');
        $this->assertArrayHasKey('sql', $hasil);
    }
}
