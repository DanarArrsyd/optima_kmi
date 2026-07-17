<?php
namespace Tests\Feature;

use App\Services\Chatbot\CapabilityRegistry;
use App\Services\Chatbot\ToolSchemaCatalog;
use Tests\TestCase;

/**
 * Mengunci migrasi tool → capability: SETIAP tool di ToolSchemaCatalog lama harus
 * tetap ada di registry dengan schema (deskripsi + parameters) IDENTIK. Registry
 * boleh punya tool TAMBAHAN (capability baru pasca-migrasi, mis. getOeeMesin) —
 * itu superset yang sah, bukan regresi. Yang dijaga: tool lama tak hilang/berubah.
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

    public function test_registry_is_superset_of_legacy_catalog_with_identical_schema(): void
    {
        $legacy   = $this->byName((new ToolSchemaCatalog())->definitions());
        $registry = $this->byName(app(CapabilityRegistry::class)->schemaDefinitions());

        foreach ($legacy as $name => $def) {
            $this->assertArrayHasKey($name, $registry, "Tool lama {$name} hilang dari registry.");
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
