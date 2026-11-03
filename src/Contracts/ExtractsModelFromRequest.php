<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by handlers whose response doesn't name the model, so it has
 * to come from the request instead: Gemini puts it in the URL, binary TTS
 * and Whisper responses carry no model at all.
 */
interface ExtractsModelFromRequest
{
    /**
     * Extract the model name from the request data and endpoint.
     *
     * @param  array<string, mixed>  $requestData  Parsed request body
     * @param  string  $endpoint  Request endpoint path
     * @return string|null The model name, or null to fall back to default
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string;
}
