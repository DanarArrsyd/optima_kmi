<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CerebrasChatbotService;
use App\Services\DeepseekChatbotService;
use App\Services\GeminiChatbotService;
use App\Services\GroqChatbotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Saat rantai provider habis, pesan "Maaf, sistem AI lagi ga bisa diakses" tidak
 * membedakan DUA sebab yang sangat berbeda:
 *
 *   - nol provider dikonfigurasi (key belum diisi) — masalah setup, bisa diperbaiki
 *   - semua provider dicoba lalu gagal — gangguan, tunggu atau cek kuota
 *
 * Provider tanpa key di-skip diam-diam, jadi dari log pun tidak kelihatan bedanya.
 * Test ini mengunci pembedaannya.
 */
class ChatbotProviderDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** Semua provider tanpa key: ini salah konfigurasi, bukan gangguan sesaat. */
    private function tanpaKeySemuaProvider(): void
    {
        config([
            'services.deepseek.key' => null,
            'services.cerebras.key' => null,
            'services.groq.key' => null,
            'services.gemini.key' => null,
        ]);
    }

    public function test_nol_provider_terkonfigurasi_memberi_pesan_yang_berbeda(): void
    {
        $this->tanpaKeySemuaProvider();

        $response = $this->actingAs($this->admin())
            ->postJson(route('chatbot.send-message'), ['message' => 'Berapa PPM NG?'])
            ->assertOk();

        $reply = $response->json('reply');

        $this->assertStringNotContainsString('coba lagi nanti', $reply,
            'Nol key bukan gangguan sesaat — menyuruh "coba lagi nanti" menyesatkan.');
        $this->assertStringContainsString('belum dikonfigurasi', $reply);
    }

    /** Kegagalan total harus tercatat, kalau tidak tak ada yang bisa mendiagnosa. */
    public function test_kegagalan_seluruh_rantai_dicatat_ke_log(): void
    {
        $this->tanpaKeySemuaProvider();

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $pesan, array $konteks) {
                return str_contains($pesan, 'Chatbot')
                    && array_key_exists('provider', $konteks)
                    && $konteks['provider']['deepseek'] === 'tanpa_key';
            });
        Log::shouldReceive('warning')->zeroOrMoreTimes();
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        $this->actingAs($this->admin())
            ->postJson(route('chatbot.send-message'), ['message' => 'Berapa PPM NG?'])
            ->assertOk();
    }

    /** Status tiap provider harus bisa ditanya tanpa memanggil API-nya. */
    public function test_provider_melaporkan_apakah_dirinya_terkonfigurasi(): void
    {
        config(['services.deepseek.key' => null]);
        $this->assertFalse(app(DeepseekChatbotService::class)->isConfigured());

        config(['services.deepseek.key' => 'sk-dummy']);
        $this->assertTrue(app(DeepseekChatbotService::class)->isConfigured());
    }

    public function test_semua_provider_mengimplementasi_pengecekan_konfigurasi(): void
    {
        foreach ([DeepseekChatbotService::class, CerebrasChatbotService::class,
            GroqChatbotService::class, GeminiChatbotService::class] as $kelas) {
            $this->assertTrue(
                method_exists($kelas, 'isConfigured'),
                "{$kelas} tidak bisa melaporkan status konfigurasinya."
            );
        }
    }
}
