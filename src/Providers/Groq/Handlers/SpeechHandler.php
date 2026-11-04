<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

/**
 * Groq text to speech (Canopy Labs Orpheus), billed per input character.
 *
 * The response body is the audio file itself, so the model and the text
 * length come from the request.
 */
class SpeechHandler implements Handler, HasMedia, ReturnsBinaryResponse
{
    use MatchesEndpoints;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    /**
     * Get the speech endpoint paths.
     */
    public function endpoints(): array
    {
        return ['/openai/v1/audio/speech'];
    }

    /**
     * Get the input characters of the speech request.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(
                inputCharacters: is_string($requestData['input'] ?? null) ? mb_strlen($requestData['input']) : null,
            ),
        );
    }

    /**
     * Get a placeholder text for the audio response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }

    /**
     * Store the audio body in the requested format and return its path.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        if (blank($rawBody)) {
            return [];
        }

        $format = $responseData['_request_data']['response_format'] ?? 'wav';

        return [app(MediaPersister::class)->store($requestId, 0, $rawBody, is_string($format) ? $format : 'wav')];
    }
}
