<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Support\ProviderRegistry;

/**
 * Decides whether a PSR-7 response should be treated as a streaming response.
 *
 * Used by GuzzleMiddleware, the single tracking entry point, so every route
 * (HTTP client, openai-php, custom Guzzle stacks) detects streams the same way.
 */
class StreamDetector
{
    /**
     * Determine if a provider response is a stream, from its endpoint, headers and request options.
     *
     * @param  array<string, mixed>  $requestData
     */
    public static function isStreamingResponse(
        ResponseInterface $response,
        string $provider,
        ?string $endpoint,
        array $requestData = []
    ): bool {
        // Binary endpoints (TTS audio, images) are chunked but not streams.
        if (filled($endpoint)) {
            $handler = app(ProviderRegistry::class)->provider($provider)?->resolveHandler($endpoint);
            if ($handler instanceof ReturnsBinaryResponse) {
                return false;
            }
        }

        $contentType = $response->getHeaderLine('Content-Type');

        // Server-sent events, or newline-delimited JSON (Ollama streams by default).
        if (Str::contains($contentType, ['text/event-stream', 'ndjson', 'jsonl'])) {
            return true;
        }

        // Gemini streams via the endpoint name; without `alt=sse` the body is a
        // streamed JSON array served as application/json.
        if ($endpoint !== null && Str::endsWith($endpoint, ':streamGenerateContent')) {
            return true;
        }

        // A plain JSON document is never a stream, even when the request asked
        // for one (some APIs ignore or deprecated their `stream` flag).
        if (Str::contains($contentType, 'application/json')) {
            return false;
        }

        // Past the JSON check, a body the request asked to stream is a stream.
        if ($requestData['stream'] ?? false) {
            return true;
        }

        // Chunked without content-length and not JSON indicates streaming.
        $transferEncoding = $response->getHeaderLine('Transfer-Encoding');

        return Str::contains($transferEncoding, 'chunked') && ! $response->hasHeader('Content-Length');
    }
}
