<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

use Illuminate\Support\Str;
use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

/**
 * Mistral text-to-speech (`/v1/audio/speech`).
 *
 * Unlike OpenAI's binary speech endpoint it answers with JSON carrying the
 * audio as base64 `audio_data`, and it reports no usage, so it is billed per
 * character of the request's `input`.
 */
class SpeechHandler implements ExtractsModelFromResponse, Handler, HasMedia
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Mistral speech requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    /**
     * Get the Mistral speech endpoint path.
     */
    public function endpoints(): array
    {
        return ['/v1/audio/speech'];
    }

    /**
     * Build the audio usage from the characters of the request's `input`.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(
                inputCharacters: isset($requestData['input']) ? Str::length((string) $requestData['input']) : null,
            ),
        );
    }

    /**
     * Get the placeholder recorded in place of the generated speech.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }

    /**
     * Decode the response's base64 `audio_data` and store it in the requested format.
     *
     * Unlike OpenAI's binary TTS endpoint, Mistral returns JSON with the audio
     * as a base64 audio_data field.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $encoded = $responseData['audio_data'] ?? null;

        if (blank($encoded)) {
            return [];
        }

        $content = base64_decode($encoded, true);

        if ($content === false) {
            return [];
        }

        $format = $responseData['_request_data']['response_format'] ?? 'mp3';

        return [
            app(MediaPersister::class)->store($requestId, 0, $content, $format),
        ];
    }
}
