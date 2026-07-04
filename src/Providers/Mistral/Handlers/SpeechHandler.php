<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class SpeechHandler implements ExtractsModelFromResponse, Handler, HasMedia
{
    use ExtractsModelField;
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    public function endpoints(): array
    {
        return ['/v1/audio/speech'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(
                inputCharacters: isset($requestData['input']) ? mb_strlen((string) $requestData['input']) : null,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }

    /**
     * Unlike OpenAI's binary TTS endpoint, Mistral returns JSON with the audio
     * as a base64 audio_data field.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $encoded = $responseData['audio_data'] ?? null;

        if (! is_string($encoded) || $encoded === '') {
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
