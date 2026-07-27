<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga sementara: memastikan pembungkus @if guest di halaman LHP tidak
 * meninggalkan <div> menggantung. Jumlah <div> dan </div> harus sama persis,
 * baik untuk guest maupun staf.
 */
class GuestMarkupBalanceTest extends TestCase
{
    use RefreshDatabase;

    public static function lhpPages(): array
    {
        return [
            ['input.produksi.index', 'staff_produksi'],
            ['input.qc.index', 'staff_qc'],
            ['input.dandori.index', 'staff_produksi'],
        ];
    }

    #[DataProvider('lhpPages')]
    public function test_div_tags_are_balanced_for_both_roles(string $routeName, string $staffRole): void
    {
        foreach (['guest', $staffRole] as $role) {
            $html = $this->actingAs(User::factory()->create(['role' => $role]))
                ->get(route($routeName))->assertOk()->getContent();

            $open = preg_match_all('/<div\b/i', $html);
            $close = preg_match_all('/<\/div>/i', $html);

            $this->assertSame($close, $open, "Div tidak seimbang di {$routeName} untuk role {$role}");
        }
    }
}
