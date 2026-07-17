<?php
namespace App\Services\Chatbot;

use App\Contracts\ChatCapability;
use Illuminate\Support\Facades\Cache;

/**
 * Mengumpulkan semua ChatCapability yang ter-tag di container, lalu membangun
 * — otomatis, tanpa hardcode — schema function-calling, dispatch map, dan
 * bahan system-prompt yang dipapar ke LLM.
 *
 * Ini INTI arsitektur self-registering: nambah modul = tag satu capability baru,
 * registry langsung "tahu" tanpa edit file lain.
 *
 * Dibangun berdampingan dengan ChatbotTools/ToolSchemaCatalog lama (non-breaking).
 * schemaDefinitions() sengaja balikin bentuk yang IDENTIK dengan
 * ToolSchemaCatalog::definitions() supaya bisa jadi drop-in untuk provider.
 */
class CapabilityRegistry
{
    /** @var array<string, ChatCapability> nama => capability */
    private array $byName = [];

    /** @param iterable<ChatCapability> $capabilities biasanya hasil $app->tagged() */
    public function __construct(iterable $capabilities = [])
    {
        foreach ($capabilities as $cap) {
            $this->register($cap);
        }
    }

    public function register(ChatCapability $cap): void
    {
        $this->byName[$cap->name()] = $cap;
    }

    /** @return array<string, ChatCapability> */
    public function all(): array
    {
        return $this->byName;
    }

    public function find(string $name): ?ChatCapability
    {
        return $this->byName[$name] ?? null;
    }

    /**
     * Jalankan capability dengan cache read-only (tool + argumen sama = hasil
     * sama dalam TTL). Data pabrik berubah per jam, jadi cache pendek memangkas
     * hit DB pada pertanyaan beruntun/regenerate tanpa bikin jawaban basi.
     *
     * Cache global (bukan per-user): hasil murni data, belum ada isolasi baris
     * per departemen. Saat data-isolation masuk, key WAJIB disertai identitas role.
     */
    public function run(ChatCapability $cap, array $args): array
    {
        $ttl = (int) config('chatbot.tool_cache_ttl', 0);
        if ($ttl <= 0) {
            return $cap->handle($args);
        }

        ksort($args);
        $key = 'chatbot_tool:' . $cap->name() . ':' . md5(json_encode($args));

        return Cache::remember($key, $ttl, fn () => $cap->handle($args));
    }

    /**
     * Subset capability yang boleh dipakai role tertentu. Capability tanpa
     * roles() (kosong) berlaku untuk semua. $userRoles kosong = tanpa filter role.
     *
     * @param string[] $userRoles
     * @return array<string, ChatCapability>
     */
    public function forRoles(array $userRoles): array
    {
        if (empty($userRoles)) {
            return $this->byName;
        }

        return array_filter($this->byName, function (ChatCapability $cap) use ($userRoles) {
            $allowed = $cap->roles();
            return empty($allowed) || array_intersect($allowed, $userRoles) !== [];
        });
    }

    /**
     * Tool-subsetting hemat token: capability yang keyword-nya nyangkut di
     * pertanyaan. Kalau tak ada yang cocok, balikin SEMUA (aman: biar model tetap
     * bisa jawab, cuma tak dapat penghematan). Capability tanpa keyword selalu ikut.
     *
     * @param string[] $userRoles filter permission dulu sebelum keyword
     * @return array<string, ChatCapability>
     */
    public function relevant(string $query, array $userRoles = []): array
    {
        $pool = $this->forRoles($userRoles);
        $hay  = mb_strtolower($query);

        $matched = array_filter($pool, function (ChatCapability $cap) use ($hay) {
            $kw = $cap->keywords();
            if (empty($kw)) {
                return true; // tanpa keyword = general, selalu tersedia
            }
            foreach ($kw as $k) {
                if ($k !== '' && str_contains($hay, mb_strtolower($k))) {
                    return true;
                }
            }
            return false;
        });

        // Semua yang match cuma capability "general" tanpa keyword → tak ada sinyal
        // topik spesifik; balikin pool penuh supaya model tak kehilangan tool.
        $hasSpecific = array_filter($matched, fn (ChatCapability $c) => !empty($c->keywords()));

        return $hasSpecific === [] ? $pool : $matched;
    }

    /**
     * Schema function-calling siap kirim ke provider. Bentuk identik dengan
     * ToolSchemaCatalog::definitions() (list of {name, description, parameters}).
     *
     * @param array<string, ChatCapability>|null $subset default = semua
     * @return list<array{name:string, description:string, parameters:array}>
     */
    public function schemaDefinitions(?array $subset = null): array
    {
        $caps = $subset ?? $this->byName;

        return array_values(array_map(fn (ChatCapability $c) => [
            'name'        => $c->name(),
            'description' => $c->description(),
            'parameters'  => $c->parameters(),
        ], $caps));
    }

    /**
     * Schema untuk sebuah konteks pertanyaan. Kalau tool-subsetting nyala
     * (config chatbot.tool_subsetting), cuma kirim tool relevan (hemat token,
     * dengan fallback aman ke semua bila tak ada keyword cocok). Kalau mati,
     * kirim semua tool yang boleh untuk role tsb.
     *
     * @param string[] $roles
     */
    public function schemaForQuery(string $context, array $roles = []): array
    {
        $subset = config('chatbot.tool_subsetting', true)
            ? $this->relevant($context, $roles)
            : $this->forRoles($roles);

        return $this->schemaDefinitions($subset);
    }

    /**
     * Baris "kapabilitas tersedia" untuk disuntik ke system prompt — dibangun dari
     * metadata capability, BUKAN diketik manual. Nambah modul → baris ini nambah
     * sendiri, jadi prompt tak perlu diedit tiap fitur baru.
     */
    public function promptCapabilityIndex(?array $subset = null): string
    {
        $caps = $subset ?? $this->byName;
        if ($caps === []) {
            return '';
        }

        $lines = array_map(
            fn (ChatCapability $c) => "- {$c->name()}: {$c->description()}",
            $caps
        );

        return "Kapabilitas tersedia:\n" . implode("\n", $lines);
    }
}
