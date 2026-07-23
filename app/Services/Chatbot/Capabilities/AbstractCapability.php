<?php

namespace App\Services\Chatbot\Capabilities;

use App\Contracts\ChatCapability;
use App\Services\ChatbotTools;

/**
 * Basis capability: nyimpen ChatbotTools (tempat logika domain teruji tinggal)
 * + kasih default kosong untuk metadata opsional. Concrete cukup override yang
 * relevan (name/description/parameters/handle, plus keywords/examples kalau ada).
 *
 * SENGAJA mendelegasi ke ChatbotTools, bukan salin ulang logika — capability
 * memindah METADATA (schema/keyword/role/contoh) ke satu tempat, bukan domain code.
 */
abstract class AbstractCapability implements ChatCapability
{
    public function __construct(protected ChatbotTools $tools = new ChatbotTools) {}

    /** @return string[] */
    public function keywords(): array
    {
        return [];
    }

    /** @return string[] */
    public function roles(): array
    {
        return [];
    }

    /** @return string[] */
    public function examples(): array
    {
        return [];
    }
}
