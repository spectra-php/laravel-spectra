<?php

namespace Spectra\Providers\Google;

use Illuminate\Support\Facades\Http;
use Spectra\Support\ApiKeyResolver;

class GoogleTokenCounter
{
    public function __construct(
        protected ApiKeyResolver $apiKeyResolver
    ) {}

    /**
     * Call Google's countTokens API to get the exact token count.
     *
     * @param  string  $model  Model name (e.g. "gemini-embedding-001")
     * @param  array<string, mixed>  $content  The content object from the embedding request (parts array)
     * @return int|null Token count, or null if the API call fails
     */
    public function count(string $model, array $content): ?int
    {
        $apiKey = $this->apiKeyResolver->resolve('google');

        if (! $apiKey) {
            return null;
        }

        $baseUrl = $this->apiKeyResolver->resolveBaseUrl('google')
            ?? 'https://generativelanguage.googleapis.com';

        $url = rtrim($baseUrl, '/')."/v1beta/models/{$model}:countTokens";

        try {
            $response = Http::withoutAITracking()
                ->timeout(5)
                ->withQueryParameters(['key' => $apiKey])
                ->post($url, [
                    'contents' => [
                        ['parts' => $content],
                    ],
                ]);

            if ($response->successful()) {
                return (int) ($response->json('totalTokens') ?? 0);
            }
        } catch (\Throwable) {
            // Silently fail — token counting is best-effort
        }

        return null;
    }

    /**
     * Count tokens for a batch of content arrays.
     *
     * @param  string  $model  Model name
     * @param  array<int, array<string, mixed>>  $contentParts  Array of parts arrays, one per batch request
     * @return int|null Total token count across all batch items, or null on failure
     */
    public function countBatch(string $model, array $contentParts): ?int
    {
        $total = 0;

        foreach ($contentParts as $parts) {
            $count = $this->count($model, $parts);

            if ($count === null) {
                return null;
            }

            $total += $count;
        }

        return $total;
    }
}
