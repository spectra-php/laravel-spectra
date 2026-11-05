<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Illuminate\Support\Str;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

/**
 * Text to speech (`/v1/audio/speech`). The response is the audio file itself.
 *
 * tts-1 and tts-1-hd are billed per input character, which is read from the
 * request. gpt-4o-mini-tts is billed per token, but the audio response
 * carries no usage, so the catalog prices it per minute of generated audio
 * (an estimate; needs getID3 to read the duration).
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
        return [
            '/v1/audio/speech',
        ];
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
                inputCharacters: is_string($requestData['input'] ?? null) ? Str::length($requestData['input']) : null,
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

        $persister = app(MediaPersister::class);
        $format = $responseData['_request_data']['response_format'] ?? 'mp3';

        return [
            $persister->store($requestId, 0, $rawBody, $format),
        ];
    }
}
