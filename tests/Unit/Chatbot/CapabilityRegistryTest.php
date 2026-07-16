<?php
namespace Tests\Unit\Chatbot;

use App\Contracts\ChatCapability;
use App\Services\Chatbot\CapabilityRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Registry murni logika koleksi/subsetting (tanpa DB/container), jadi diuji
 * sebagai unit murni pakai capability palsu. Membuktikan pola self-registering:
 * schema + filter role + subsetting keyword terbangun otomatis dari metadata.
 */
class CapabilityRegistryTest extends TestCase
{
    private function cap(
        string $name,
        array $keywords = [],
        array $roles = [],
    ): ChatCapability {
        return new class ($name, $keywords, $roles) implements ChatCapability {
            public function __construct(
                private string $n,
                private array $kw,
                private array $r,
            ) {}
            public function name(): string { return $this->n; }
            public function description(): string { return "desc {$this->n}"; }
            public function parameters(): array { return ['type' => 'object', 'properties' => [], 'required' => []]; }
            public function keywords(): array { return $this->kw; }
            public function roles(): array { return $this->r; }
            public function examples(): array { return ["contoh {$this->n}"]; }
            public function handle(array $args): array { return ['ok' => $this->n]; }
        };
    }

    public function test_collects_and_finds_by_name(): void
    {
        $reg = new CapabilityRegistry([$this->cap('alpha'), $this->cap('beta')]);

        $this->assertCount(2, $reg->all());
        $this->assertSame('alpha', $reg->find('alpha')?->name());
        $this->assertNull($reg->find('ghost'));
    }

    public function test_schema_definitions_match_provider_shape(): void
    {
        $reg = new CapabilityRegistry([$this->cap('alpha')]);
        $schema = $reg->schemaDefinitions();

        $this->assertCount(1, $schema);
        $this->assertSame(['name', 'description', 'parameters'], array_keys($schema[0]));
        $this->assertSame('alpha', $schema[0]['name']);
        $this->assertArrayHasKey('type', $schema[0]['parameters']);
    }

    public function test_for_roles_filters_by_permission(): void
    {
        $reg = new CapabilityRegistry([
            $this->cap('general'),                       // no roles = semua
            $this->cap('finance', roles: ['manager']),   // manager only
        ]);

        $staff = $reg->forRoles(['staff_produksi']);
        $this->assertArrayHasKey('general', $staff);
        $this->assertArrayNotHasKey('finance', $staff);

        $manager = $reg->forRoles(['manager']);
        $this->assertArrayHasKey('finance', $manager);
    }

    public function test_relevant_subsets_by_keyword(): void
    {
        $reg = new CapabilityRegistry([
            $this->cap('produksi', keywords: ['produksi', 'qty']),
            $this->cap('mesin', keywords: ['mesin', 'breakdown']),
        ]);

        $hit = $reg->relevant('total qty produksi bulan ini');
        $this->assertArrayHasKey('produksi', $hit);
        $this->assertArrayNotHasKey('mesin', $hit);
    }

    public function test_relevant_falls_back_to_all_when_no_keyword_matches(): void
    {
        $reg = new CapabilityRegistry([
            $this->cap('produksi', keywords: ['produksi']),
            $this->cap('mesin', keywords: ['mesin']),
        ]);

        // Pertanyaan tanpa sinyal topik → jangan buang tool, balikin semua.
        $this->assertCount(2, $reg->relevant('halo apa kabar'));
    }

    public function test_keywordless_capability_always_included(): void
    {
        $reg = new CapabilityRegistry([
            $this->cap('general'),                       // tanpa keyword = general
            $this->cap('mesin', keywords: ['mesin']),
        ]);

        $hit = $reg->relevant('total qty produksi'); // cuma "general" yang match
        $this->assertArrayHasKey('general', $hit);
        $this->assertCount(2, $hit); // tak ada topik spesifik → pool penuh
    }

    public function test_prompt_index_built_from_metadata(): void
    {
        $reg = new CapabilityRegistry([$this->cap('alpha')]);
        $idx = $reg->promptCapabilityIndex();

        $this->assertStringContainsString('- alpha: desc alpha', $idx);
    }
}
