<?php

namespace Tests\Unit\Chatbot;

use App\Services\Chatbot\BusinessTableCatalog;
use App\Services\Chatbot\SqlGuard;
use Tests\TestCase;

/**
 * Chatbot harus MELIHAT tabel detail NG.
 *
 * Sebelum ini `transaksi_produksi_ng` / `transaksi_qc_ng` tidak terdaftar di
 * BusinessTableCatalog, dan itu membutakan chatbot dua lapis sekaligus:
 * describeSchema tidak pernah menyebutnya, DAN SqlGuard menolak SQL apa pun yang
 * menyentuhnya. Seluruh model data NG multi-jenis jadi tak terjangkau.
 */
class NgAwarenessTest extends TestCase
{
    public function test_katalog_memuat_kedua_tabel_detail_ng(): void
    {
        $catalog = new BusinessTableCatalog;

        $this->assertTrue($catalog->has('transaksi_produksi_ng'), 'transaksi_produksi_ng harus terdaftar.');
        $this->assertTrue($catalog->has('transaksi_qc_ng'), 'transaksi_qc_ng harus terdaftar.');
    }

    /** Deskripsi tabel adalah satu-satunya petunjuk LLM soal isi kolomnya. */
    public function test_deskripsi_detail_ng_menyebut_kolom_kuncinya(): void
    {
        $catalog = new BusinessTableCatalog;

        foreach (['transaksi_produksi_ng', 'transaksi_qc_ng'] as $tabel) {
            $desc = $catalog->describe($tabel);
            $this->assertNotNull($desc, "{$tabel} tanpa deskripsi.");
            $this->assertStringContainsString('id_jenis_ng', $desc, "{$tabel}: kolom jenis NG tidak disebut.");
            $this->assertStringContainsString('qty', $desc, "{$tabel}: kolom qty tidak disebut.");
        }
    }

    public function test_sqlguard_mengizinkan_query_ke_tabel_detail_ng(): void
    {
        $hasil = (new SqlGuard)->sanitize(
            'SELECT id_jenis_ng, SUM(qty) AS total FROM transaksi_produksi_ng GROUP BY id_jenis_ng'
        );

        $this->assertArrayNotHasKey('error', $hasil, 'Query ke tabel detail NG masih ditolak.');
        $this->assertStringContainsString('transaksi_produksi_ng', $hasil['sql']);
    }

    public function test_sqlguard_mengizinkan_join_detail_ke_master_jenis_ng(): void
    {
        $hasil = (new SqlGuard)->sanitize(
            'SELECT j.nama_ng, SUM(d.qty) AS total FROM transaksi_qc_ng d '
            .'JOIN master_jenis_ng j ON j.id = d.id_jenis_ng GROUP BY j.nama_ng'
        );

        $this->assertArrayNotHasKey('error', $hasil, 'Join detail NG ke master masih ditolak.');
        $this->assertStringContainsString('transaksi_qc_ng', $hasil['sql']);
    }
}
