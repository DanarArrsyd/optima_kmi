<?php

namespace Tests\Unit\Chatbot;

use App\Services\Chatbot\SqlGuard;
use PHPUnit\Framework\TestCase;

/**
 * Boundary keamanan queryDatabase. sanitize() murni string-processing (tanpa DB),
 * jadi diuji sebagai unit murni. Sebelum refactor Sprint 1 logika ini tak punya
 * test langsung — ini menutup celah itu.
 */
class SqlGuardTest extends TestCase
{
    private function guard(): SqlGuard
    {
        return new SqlGuard;
    }

    public function test_allows_plain_select_on_business_table(): void
    {
        $out = $this->guard()->sanitize('SELECT nama_karyawan FROM master_karyawan WHERE is_aktif = 1');
        $this->assertArrayNotHasKey('error', $out);
        $this->assertArrayHasKey('sql', $out);
        $this->assertStringContainsString('LIMIT 25', $out['sql']);
    }

    public function test_allows_with_cte(): void
    {
        $out = $this->guard()->sanitize('WITH x AS (SELECT id FROM master_mesin) SELECT * FROM master_mesin');
        $this->assertArrayNotHasKey('error', $out);
    }

    public function test_rejects_non_select(): void
    {
        $this->assertArrayHasKey('error', $this->guard()->sanitize('UPDATE master_mesin SET line = 1'));
        $this->assertArrayHasKey('error', $this->guard()->sanitize('DELETE FROM master_mesin'));
    }

    public function test_rejects_multi_statement(): void
    {
        $out = $this->guard()->sanitize('SELECT * FROM master_mesin; DROP TABLE users');
        $this->assertArrayHasKey('error', $out);
    }

    public function test_rejects_write_or_ddl_keywords(): void
    {
        $this->assertArrayHasKey('error', $this->guard()->sanitize('SELECT * FROM master_mesin INTO OUTFILE "x"'));
        $this->assertArrayHasKey('error', $this->guard()->sanitize('SELECT * FROM master_mesin WHERE 1=1 DROP'));
    }

    public function test_rejects_file_and_metadata_functions(): void
    {
        $this->assertArrayHasKey('error', $this->guard()->sanitize("SELECT load_file('/etc/passwd')"));
        $this->assertArrayHasKey('error', $this->guard()->sanitize('SELECT version() FROM master_mesin'));
        $this->assertArrayHasKey('error', $this->guard()->sanitize('SELECT @@version FROM master_mesin'));
    }

    public function test_rejects_system_tables_even_if_whitelisted_join_present(): void
    {
        $out = $this->guard()->sanitize('SELECT * FROM master_mesin JOIN users ON users.id = 1');
        $this->assertArrayHasKey('error', $out);
    }

    /**
     * Backtick adalah pembungkus identifier MySQL. Regex whitelist dulu cuma cocok dengan
     * nama telanjang, jadi `JOIN \`x\`` tak pernah terbaca sebagai tabel — asalkan ada satu
     * FROM telanjang yang sah, tabel di dalam backtick lolos tanpa pernah dicek.
     *
     * Efek hari ini terbatas (yang tembus cuma dies/mesin_preventive_log — data yang sudah
     * dilihat admin/manager lewat UI, dan chatbot memang cuma untuk dua role itu). Yang
     * dibetulkan di sini adalah strukturnya: whitelist itu lapis kedua, jaring pengaman
     * kalau blacklist kelupaan. Dengan celah ini keamanan bergantung penuh pada kelengkapan
     * blacklist — tambah satu tabel sensitif tanpa mendaftarkannya, dan backtick menembus
     * tanpa error.
     */
    public function test_rejects_backticked_non_whitelisted_table(): void
    {
        $out = $this->guard()->sanitize(
            'SELECT * FROM master_mesin JOIN `dies_preventive_log` ON 1=1'
        );
        $this->assertArrayHasKey('error', $out);
        $this->assertStringContainsString('dies_preventive_log', $out['error']);
    }

    public function test_allows_backticked_business_table(): void
    {
        $out = $this->guard()->sanitize('SELECT nama_mesin FROM `master_mesin` LIMIT 5');
        $this->assertArrayNotHasKey('error', $out);
    }

    /**
     * MySQL menerima CURRENT_USER/SESSION_USER/SYSTEM_USER tanpa tanda kurung, sementara
     * penjaga fungsi hanya cocok dengan bentuk `fn(`. Bocornya kecil (username DB), tapi
     * ini sisa lubang yang sama dengan version()/database() yang sudah ditutup.
     */
    public function test_rejects_bare_user_system_variables(): void
    {
        foreach (['CURRENT_USER', 'SESSION_USER', 'SYSTEM_USER'] as $fn) {
            $this->assertArrayHasKey(
                'error',
                $this->guard()->sanitize("SELECT {$fn} FROM master_mesin"),
                "{$fn} tanpa tanda kurung masih lolos.",
            );
        }
    }

    public function test_rejects_non_whitelisted_table(): void
    {
        $out = $this->guard()->sanitize('SELECT * FROM secret_table');
        $this->assertArrayHasKey('error', $out);
    }

    public function test_requires_a_referenced_table(): void
    {
        $out = $this->guard()->sanitize('SELECT 1');
        $this->assertArrayHasKey('error', $out);
    }

    public function test_clamps_oversized_limit_to_row_cap(): void
    {
        $out = $this->guard()->sanitize('SELECT * FROM master_mesin LIMIT 9999');
        $this->assertArrayNotHasKey('error', $out);
        $this->assertStringContainsString('LIMIT 25', $out['sql']);
        $this->assertStringNotContainsString('9999', $out['sql']);
    }

    public function test_preserves_smaller_limit(): void
    {
        $out = $this->guard()->sanitize('SELECT * FROM master_mesin LIMIT 5');
        $this->assertStringContainsString('LIMIT 5', $out['sql']);
    }

    public function test_appends_limit_when_absent(): void
    {
        $out = $this->guard()->sanitize('SELECT nama_mesin FROM master_mesin');
        $this->assertStringContainsString('LIMIT 25', $out['sql']);
    }

    public function test_comment_cannot_smuggle_blacklisted_table(): void
    {
        $out = $this->guard()->sanitize('SELECT * FROM master_mesin /* users */ WHERE 1=1');
        // Komentar dibuang dulu; "users" di dalam komentar hilang → query jadi valid (bukan bocor).
        $this->assertArrayNotHasKey('error', $out);
    }

    public function test_row_cap_constant_is_25(): void
    {
        $this->assertSame(25, SqlGuard::ROW_CAP);
    }
}
