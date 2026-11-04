<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers that produce files (images, audio, video), so
 * Spectra can keep a copy on the configured media disk when media storage is
 * enabled. Provider URLs expire and base64 bodies are stripped before the
 * response is stored, so this is the only lasting copy.
 */
interface HasMedia
{
    /**
     * Store the response's generated files on the media disk and return their paths.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array;
}
