<?php

namespace Spectra\Providers\ElevenLabs\Handlers;

use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class TextToSpeechHandler implements ExtractsModelFromRequest, Handler, HasMedia, ReturnsBinaryResponse
{
    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    public function endpoints(): array
    {
        return [
            '/v1/text-to-speech/{voice_id}',
            '/v1/text-to-speech/{voice_id}/stream',
        ];
    }

    public function matchesEndpoint(string $endpoint): bool
    {
        return (bool) preg_match('#^/v1/text-to-speech/[^/]+(/stream)?$#', $endpoint);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(
                inputCharacters: isset($requestData['text']) ? mb_strlen($requestData['text']) : null,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }

    /** @param  array<string, mixed>  $requestData */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        return $requestData['model_id'] ?? null;
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
        $format = $responseData['_request_data']['output_format'] ?? 'mp3_44100_128';
        $extension = str_contains($format, 'pcm') ? 'pcm' : 'mp3';

        return [
            $persister->store($requestId, 0, $rawBody, $extension),
        ];
    }
}
