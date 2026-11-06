<?php

declare(strict_types=1);

namespace Spectra\Providers\Google;

use Illuminate\Support\Facades\Http;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\Payload;

/**
 * Asks the Gemini countTokens API how many tokens a request had, for
 * responses that don't report usage (embeddings). Never tracked itself.
 */
class GoogleTokenCounter
{
    /**
     * Create a new Gemini token counter instance.
     */
    public function __construct(
        protected ApiKeyResolver $apiKeyResolver
    ) {}

    /**
     * Call Google's countTokens API to get the exact token count, or return null when the call fails.
     *
     * @param  array<string, mixed>  $content  The content object from the embedding request (parts array)
     */
    public function count(string $model, array $content): ?int
    {
        $apiKey = $this->apiKeyResolver->resolve('google');

        if (blank($apiKey)) {
            return null;
        }

        $baseUrl = $this->apiKeyResolver->resolveBaseUrl('google')
            ?? 'https://generativelanguage.googleapis.com';

        $baseUrl = rtrim($baseUrl, '/');
        $url = "{$baseUrl}/v1beta/models/{$model}:countTokens";

        // Token counting is best-effort: a failed call leaves the count unknown.
        return rescue(function () use ($url, $apiKey, $content): ?int {
            $response = Http::withoutAITracking()
                ->timeout(5)
                ->withQueryParameters(['key' => $apiKey])
                ->post($url, [
                    'contents' => [
                        ['parts' => $content],
                    ],
                ]);

            return $response->successful() ? Payload::int($response->json('totalTokens')) : null;
        }, null, false);
    }

    /**
     * Count the tokens of a batch of content arrays, or return null when any count fails.
     *
     * @param  array<int, array<string, mixed>>  $contentParts  Array of parts arrays, one per batch request
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
