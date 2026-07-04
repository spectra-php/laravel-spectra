<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Psr\Http\Message\ResponseInterface;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Support\ProviderRegistry;

/**
 * Decides whether a PSR-7 response should be treated as a streaming response.
 *
 * Shared by every tracking entry point (Guzzle middleware, Http macro) so the
 * detection heuristics cannot drift between them.
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
        if ($endpoint !== null && $endpoint !== '') {
            $handler = app(ProviderRegistry::class)->provider($provider)?->resolveHandler($endpoint);
            if ($handler instanceof ReturnsBinaryResponse) {
                return false;
            }
        }

        // The request explicitly asked for streaming.
        if (! empty($requestData['stream'])) {
            return true;
        }

        $contentType = $response->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'text/event-stream')) {
            return true;
        }

        // Chunked without content-length and not JSON indicates streaming.
        $transferEncoding = $response->getHeaderLine('Transfer-Encoding');
        if (str_contains($transferEncoding, 'chunked') && ! $response->hasHeader('Content-Length')) {
            if (! str_contains($contentType, 'application/json')) {
                return true;
            }
        }

        return false;
    }
}
