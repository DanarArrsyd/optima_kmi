<?php
namespace Tests\Feature;

use App\Services\Chatbot\CapabilityRegistry;
use App\Services\Chatbot\ToolSchemaCatalog;
use Tests\TestCase;

/**
 * Mengunci migrasi tool → capability: schema yang dipapar registry HARUS identik
 * dengan ToolSchemaCatalog lama (nama, deskripsi, parameters). Kalau meleset,
 * LLM lihat tool yang beda dari sebelum refactor — regresi senyap. Test ini
 * jaring pengaman selama layer lama & baru hidup berdampingan.
 */
class ChatbotCapabilityParityTest extends TestCase
{
    private function byName(array $defs): array
    {
        $out = [];
        foreach ($defs as $d) {
            $out[$d['name']] = $d;
        }
        ksort($out);
        return $out;
    }

    public function test_registry_schema_matches_legacy_catalog(): void
    {
        $legacy   = $this->byName((new ToolSchemaCatalog())->definitions());
        $registry = $this->byName(app(CapabilityRegistry::class)->schemaDefinitions());

        $this->assertSame(
            array_keys($legacy),
            array_keys($registry),
            'Set nama tool registry harus sama persis dengan katalog lama.',
        );

        foreach ($legacy as $name => $def) {
            $this->assertSame($def['description'], $registry[$name]['description'], "Deskripsi {$name} berubah.");
            $this->assertEquals($def['parameters'], $registry[$name]['parameters'], "Parameters {$name} berubah.");
        }
    }

    public function test_every_exposed_tool_dispatchable_via_registry(): void
    {
        $registry = app(CapabilityRegistry::class);

        foreach ((new ToolSchemaCatalog())->definitions() as $def) {
            $this->assertNotNull(
                $registry->find($def['name']),
                "Tool {$def['name']} tak punya capability — dispatch bakal bolong.",
            );
        }
    }
}
