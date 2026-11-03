<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

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

    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    public function endpoints(): array
    {
        return [
            '/v1/audio/speech',
        ];
    }

    /**
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

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }

    /**
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
