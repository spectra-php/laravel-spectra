<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

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
                inputCharacters: isset($requestData['input']) ? mb_strlen($requestData['input']) : null,
            ),
        );
    }

    /**
     * TTS response is binary audio, not JSON — model is not available.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractModel(array $response): ?string
    {
        return null;
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
        if ($rawBody === null || $rawBody === '') {
            return [];
        }

        $persister = app(MediaPersister::class);
        $format = $responseData['_request_data']['response_format'] ?? 'mp3';

        return [
            $persister->store($requestId, 0, $rawBody, $format),
        ];
    }
}
