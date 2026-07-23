<?php

namespace Tests\Feature;

use App\Contracts\ChatCapability;
use App\Services\Chatbot\CapabilityRegistry;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Perilaku runtime registry: cache hasil tool + tool-subsetting berbasis config.
 * Butuh container (config + Cache), jadi Feature test.
 */
class CapabilityRegistryRuntimeTest extends TestCase
{
    /** Capability mata-mata: hitung berapa kali handle() benar-benar jalan. */
    protected function makeCap(string $name, array &$calls, array $keywords = []): ChatCapability
    {
        return new class($name, $calls, $keywords) implements ChatCapability
        {
            public function __construct(private string $n, private array &$calls, private array $kw) {}

            public function name(): string
            {
                return $this->n;
            }

            public function description(): string
            {
                return "d {$this->n}";
            }

            public function parameters(): array
            {
                return ['type' => 'object', 'properties' => [], 'required' => []];
            }

            public function keywords(): array
            {
                return $this->kw;
            }

            public function roles(): array
            {
                return [];
            }

            public function examples(): array
            {
                return [];
            }

            public function handle(array $args): array
            {
                $this->calls[] = $this->n;

                return ['ran' => $this->n, 'args' => $args];
            }
        };
    }

    public function test_run_caches_result_within_ttl(): void
    {
        config(['chatbot.tool_cache_ttl' => 600]);
        Cache::flush();

        $calls = [];
        $reg = new CapabilityRegistry([$this->makeCap('alpha', $calls)]);
        $cap = $reg->find('alpha');

        $first = $reg->run($cap, ['x' => 1]);
        $second = $reg->run($cap, ['x' => 1]);

        $this->assertSame(['ran' => 'alpha', 'args' => ['x' => 1]], $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $calls, 'handle() harus jalan sekali; panggilan kedua dari cache.');
    }

    public function test_run_bypasses_cache_when_ttl_zero(): void
    {
        config(['chatbot.tool_cache_ttl' => 0]);

        $calls = [];
        $reg = new CapabilityRegistry([$this->makeCap('alpha', $calls)]);
        $cap = $reg->find('alpha');

        $reg->run($cap, ['x' => 1]);
        $reg->run($cap, ['x' => 1]);

        $this->assertCount(2, $calls, 'TTL 0 = cache mati, handle() tiap kali.');
    }

    public function test_different_args_cache_separately(): void
    {
        config(['chatbot.tool_cache_ttl' => 600]);
        Cache::flush();

        $calls = [];
        $reg = new CapabilityRegistry([$this->makeCap('alpha', $calls)]);
        $cap = $reg->find('alpha');

        $reg->run($cap, ['bulan' => '2026-06']);
        $reg->run($cap, ['bulan' => '2026-07']);

        $this->assertCount(2, $calls, 'Argumen beda = key beda = tak saling nyuri cache.');
    }

    public function test_schema_for_query_subsets_when_enabled(): void
    {
        config(['chatbot.tool_subsetting' => true]);

        $calls = [];
        $reg = new CapabilityRegistry([
            $this->makeCap('produksi', $calls, ['produksi', 'qty']),
            $this->makeCap('mesin', $calls, ['mesin', 'breakdown']),
        ]);

        $names = array_column($reg->schemaForQuery('total qty produksi bulan ini'), 'name');

        $this->assertContains('produksi', $names);
        $this->assertNotContains('mesin', $names);
    }

    public function test_schema_for_query_sends_all_when_disabled(): void
    {
        config(['chatbot.tool_subsetting' => false]);

        $calls = [];
        $reg = new CapabilityRegistry([
            $this->makeCap('produksi', $calls, ['produksi']),
            $this->makeCap('mesin', $calls, ['mesin']),
        ]);

        $names = array_column($reg->schemaForQuery('total qty produksi'), 'name');

        $this->assertContains('produksi', $names);
        $this->assertContains('mesin', $names, 'Subsetting mati = semua tool dikirim.');
    }
}
