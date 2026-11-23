<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing\Feeds\Concerns;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Downloads a price feed's JSON for the feed classes.
 */
trait DownloadsFeed
{
    /**
     * Download and decode the feed at the URL.
     *
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException When the feed can't be reached or isn't JSON.
     */
    protected static function download(string $url, string $name): array
    {
        try {
            $data = Http::withoutAITracking()->timeout(60)->retry(2, 1000, throw: false)->get($url)->throw()->json();
        } catch (Throwable $e) {
            throw new RuntimeException("Could not load {$name} ({$url}): {$e->getMessage()}", $e->getCode(), previous: $e);
        }

        throw_unless(is_array($data), RuntimeException::class, "{$name} ({$url}) did not return JSON.");

        return $data;
    }
}
