<?php

namespace Tests\Feature\Layout;

use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * confirm() bawaan peramban diganti dialog bersama (data-konfirmasi).
 * Dulu onsubmit="return confirm('Hapus shift {{ nama }}?')" tanpa escape —
 * nama berisi tanda kutip membuat JS error dan form TERKIRIM TANPA
 * konfirmasi. Atribut data-* di-escape Blade, jadi aman.
 */
class DialogKonfirmasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tidak_ada_confirm_bawaan_di_view(): void
    {
        $pakai = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $f) {
            if (! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            foreach (file($f->getPathname()) as $i => $baris) {
                // Abaikan komentar & fallback window.confirm di konfirmasi() sendiri.
                if (preg_match('/(?<![\w.])confirm\(/', $baris) && ! str_contains($baris, 'window.confirm') && ! preg_match('#^\s*(//|/\*|\*|\{\{--|Dulu)#', trim($baris))) {
                    $pakai[] = str_replace(resource_path('views').'/', '', $f->getPathname()).':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $pakai);
    }

    public function test_nama_berkutip_aman_di_atribut_konfirmasi(): void
    {
        MasterShift::create(['nama_shift' => "Shift 'Lembur'", 'jam_start' => '07:00', 'jam_finish' => '15:00']);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->get(route('master.shift.index'))->assertOk()
            ->assertSee('data-konfirmasi="Hapus shift Shift &#039;Lembur&#039;?"', false)
            ->assertSee('<dialog id="dlgKonfirmasi"', false);
    }
}
