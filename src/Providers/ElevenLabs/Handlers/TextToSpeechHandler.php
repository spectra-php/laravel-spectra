<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

/**
 * ElevenLabs text to speech (`/v1/text-to-speech/{voice_id}` and its
 * `/stream` variant), which returns raw audio and is billed per character
 * of the request text.
 */
class TextToSpeechHandler implements ExtractsModelFromRequest, Handler, HasMedia, ReturnsBinaryResponse
{
    /** The model the API uses when the request names none. */
    public const DEFAULT_MODEL = 'eleven_multilingual_v2';

    /**
     * Get the model type ElevenLabs speech requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    /**
     * Get the ElevenLabs text to speech endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/text-to-speech/{voice_id}',
            '/v1/text-to-speech/{voice_id}/stream',
        ];
    }

    /**
     * Determine if the path is a text to speech call for a voice, streamed or not.
     */
    public function matchesEndpoint(string $endpoint): bool
    {
        return (bool) preg_match('#^/v1/text-to-speech/[^/]+(/stream)?$#', $endpoint);
    }

    /**
     * Build the audio usage from the characters of the request text.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(
                inputCharacters: is_string($requestData['text'] ?? null) ? mb_strlen($requestData['text']) : null,
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
     * Get the request's `model_id`, or ElevenLabs' default speech model.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        $model = $requestData['model_id'] ?? null;

        return filled($model) ? $model : self::DEFAULT_MODEL;
    }

    /**
     * Store the speech body as a pcm or mp3 file, following the requested output format.
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
        $format = $responseData['_request_data']['output_format'] ?? 'mp3_44100_128';
        $extension = str_contains($format, 'pcm') ? 'pcm' : 'mp3';

        return [
            $persister->store($requestId, 0, $rawBody, $extension),
        ];
    }
}
