<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SafeExcelValueBinder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\TestCase;

/**
 * Penjaga hasil audit keamanan 2026-07-27.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_headers_are_sent(): void
    {
        $response = $this->get(route('login'))->assertOk();

        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("form-action 'self'", $csp);
    }

    public function test_chatbot_health_endpoint_is_gone(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get('/chatbot/health')->assertNotFound();
    }

    public function test_password_reset_request_is_rate_limited(): void
    {
        // Batas 5/menit: percobaan keenam harus ditolak 429.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('password.email'), ['email' => 'tidakada@example.com']);
        }

        $this->post(route('password.email'), ['email' => 'tidakada@example.com'])
            ->assertStatus(429);
    }

    /**
     * Formula injection: string yang diawali karakter berbahaya harus ditulis
     * sebagai teks, bukan formula, saat diekspor ke Excel.
     */
    public function test_excel_binder_neutralises_formula_prefixes(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $binder = new SafeExcelValueBinder;

        foreach (['=HYPERLINK("http://jahat/","klik")', '+1+1', '@SUM(A1)', '-cmd|calc'] as $payload) {
            $cell = $sheet->getCell('A1');
            $binder->bindValue($cell, $payload);

            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(),
                "Nilai berbahaya tidak dinetralkan: {$payload}");
            $this->assertSame($payload, $cell->getValue());
        }
    }

    /** Angka biasa tidak boleh ikut jadi teks — format numerik harus tetap utuh. */
    public function test_excel_binder_leaves_normal_values_alone(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $binder = new SafeExcelValueBinder;

        foreach ([1234, -57, '-12.5', 'AMADA 80T'] as $value) {
            $cell = $sheet->getCell('A1');
            $binder->bindValue($cell, $value);

            $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
        }

        $cell = $sheet->getCell('B1');
        $binder->bindValue($cell, -57);
        $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
    }
}
