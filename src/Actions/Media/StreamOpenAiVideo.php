<?php

declare(strict_types=1);

namespace Spectra\Actions\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spectra\Support\ApiKeyResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a Sora video's content from the OpenAI API to the browser. OpenAI
 * keeps the file, so Spectra fetches it on demand with the app's key, from
 * the same base URL the app's OpenAI client uses (a proxy or gateway when
 * one is configured).
 */
class StreamOpenAiVideo
{
    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    /**
     * Create a new stream OpenAI video action instance.
     */
    public function __construct(
        private readonly ApiKeyResolver $apiKeyResolver,
    ) {}

    /**
     * Get a download stream of the Sora video's content from the OpenAI API.
     */
    public function __invoke(string $videoId): StreamedResponse
    {
        $apiKey = $this->apiKeyResolver->resolve('openai');

        abort_unless((bool) $apiKey, 500, 'OpenAI API key not configured.');

        $response = Http::withoutAITracking()
            ->withToken($apiKey)
            ->throw()
            ->withOptions(['stream' => true])
            ->get("{$this->resolveBaseUrl()}/videos/{$videoId}/content");

        $body = $response->toPsrResponse()->getBody();

        return response()->stream(function () use ($body) {
            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, 200, [
            'Content-Type' => 'video/mp4',
            'Content-Disposition' => "attachment; filename=\"{$videoId}.mp4\"",
        ]);
    }

    /**
     * Resolve the configured OpenAI base URL, without a trailing slash.
     *
     * "api.openai.com/v1" in openai-php's config has no scheme, so one is added.
     */
    private function resolveBaseUrl(): string
    {
        $baseUrl = $this->apiKeyResolver->resolveBaseUrl('openai') ?? self::DEFAULT_BASE_URL;

        if (! Str::startsWith($baseUrl, ['http://', 'https://'])) {
            $baseUrl = "https://{$baseUrl}";
        }

        return Str::rtrim($baseUrl, '/');
    }
}
