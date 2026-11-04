<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

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
        if (str_contains($contentType, 'text/event-stream')
            || str_contains($contentType, 'ndjson')
            || str_contains($contentType, 'jsonl')) {
            return true;
        }

        // Gemini streams via the endpoint name; without `alt=sse` the body is a
        // streamed JSON array served as application/json.
        if ($endpoint !== null && str_ends_with($endpoint, ':streamGenerateContent')) {
            return true;
        }

        // A plain JSON document is never a stream, even when the request asked
        // for one (some APIs ignore or deprecated their `stream` flag).
        if (str_contains($contentType, 'application/json')) {
            return false;
        }

        // The request explicitly asked for streaming.
        if ($requestData['stream'] ?? false) {
            return true;
        }

        // Chunked without content-length and not JSON indicates streaming.
        $transferEncoding = $response->getHeaderLine('Transfer-Encoding');

        return str_contains($transferEncoding, 'chunked') && ! $response->hasHeader('Content-Length');
    }
}
