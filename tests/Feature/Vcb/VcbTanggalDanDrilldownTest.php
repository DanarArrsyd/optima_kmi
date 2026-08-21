<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tiga utang lama di kedua halaman VCB, ditemukan saat review PR #97 dan sengaja
 * ditunda ke PR tersendiri.
 *
 * Dua di antaranya cuma bisa dijaga dari sisi sumber: perilakunya berjalan di
 * JavaScript sementara suite ini PHPUnit. Yang dijaga adalah bentuk kode yang
 * dipancarkan Blade, bukan hasil eksekusinya — jangan menulis asersi yang
 * seolah menguji perilaku JS.
 */
class VcbTanggalDanDrilldownTest extends TestCase
{
    use RefreshDatabase;

    public static function halamanVcb(): array
    {
        return [
            'produksi' => ['vcb.produksi'],
            'quality' => ['vcb.quality'],
        ];
    }

    private function html(string $route): string
    {
        $admin = User::factory()->create(['role' => 'admin']);

        return $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
    }

    #[DataProvider('halamanVcb')]
    public function test_drilldown_tidak_memancarkan_argumen_kosong(string $route): void
    {
        // `switchMode('one', )` — argumen kosong — adalah SyntaxError mentah, jadi
        // seluruh handler baris itu mati, bukan cuma drill-down-nya.
        $this->assertStringNotContainsString("?? ''})", $this->html($route));
    }

    #[DataProvider('halamanVcb')]
    public function test_pemilih_tanggal_memakai_tanggal_pabrik(string $route): void
    {
        // 17:30 UTC = 22 Agustus 00:30 WIB. Kalau pemilih tanggal masih memakai
        // UTC, halaman membuka periode yang sudah lewat sehari bagi operator
        // pabrik — dan salahnya cuma muncul 7 jam tiap hari.
        Carbon::setTestNow('2026-08-21 17:30:00');

        $html = $this->html($route);

        Carbon::setTestNow();

        $this->assertStringContainsString('value="2026-08-01"', $html);
        $this->assertStringContainsString('value="2026-08-22"', $html);
        $this->assertStringNotContainsString('value="2026-08-21"', $html);
    }

    #[DataProvider('halamanVcb')]
    public function test_saringan_bulan_tidak_memuat_saat_pilihan_dikosongkan(string $route): void
    {
        // Menyaring bisa mengosongkan pilihan (orangnya di luar rentang). Memanggil
        // pemuat sesudahnya memunculkan alert "Pilih operator terlebih dahulu" —
        // menyalahkan pengguna atas sesuatu yang dilakukan halaman sendiri.
        $this->assertStringContainsString(
            "if (document.getElementById('one_karyawan_id').value)",
            $this->html($route)
        );
    }
}
