<?php
namespace App\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HasRetryingHttpCall
{
    protected function withRetry(callable $makeRequest, string $providerName): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $makeRequest();

                if ($response->successful()) {
                    return $response->json();
                }

                // Retry hanya untuk error transien server. JANGAN retry 429 (rate limit) —
                // mengulang malah menambah request dan mempercepat kena limit lagi.
                if ($attempt === 1 && in_array($response->status(), [500, 502, 503], true)) {
                    usleep(900_000);
                    continue;
                }

                Log::warning("{$providerName} API non-2xx response", [
                    'status' => $response->status(),
                    'body'   => Str::limit($response->body(), 500),
                ]);
                return null;
            } catch (\Throwable $e) {
                if ($attempt === 1) {
                    usleep(900_000);
                    continue;
                }
                Log::warning("{$providerName} API call failed", ['message' => $e->getMessage()]);
                return null;
            }
        }

        return null;
    }
}
