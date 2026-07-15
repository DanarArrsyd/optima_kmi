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
        return new SqlGuard();
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
