<?php
namespace App\Services\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait HasRetryingHttpCall
{
    /** Batas tunggu retry 429 (detik). Lebih dari ini = nyerah (request web kelamaan). */
    private const MAX_RATE_LIMIT_WAIT = 9.0;

    protected function withRetry(callable $makeRequest, string $providerName): ?array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $response = $makeRequest();

                if ($response->successful()) {
                    return $response->json();
                }

                // 429 (rate limit): TUNGGU selama yang disarankan provider lalu ulang sekali.
                // Free tier per-menit — nunggu ~beberapa detik bikin pertanyaan beruntun
                // "lambat tapi jawab", bukan gagal "Maaf,". Kalau nunggunya kelamaan, nyerah.
                if ($attempt === 1 && $response->status() === 429) {
                    $wait = (float) ($response->header('retry-after') ?: 0);
                    if ($wait <= 0 && preg_match('/try again in ([\d.]+)\s*s/i', $response->body(), $m)) {
                        $wait = (float) $m[1];
                    }
                    if ($wait > 0 && $wait <= self::MAX_RATE_LIMIT_WAIT) {
                        usleep((int) (($wait + 0.3) * 1_000_000));
                        continue;
                    }
                }

                // Retry error transien server.
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
