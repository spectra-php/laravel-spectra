<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\ReturnsBinaryResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

/**
 * Base for ElevenLabs endpoints that generate audio from a prompt (sound
 * effects, music) and are billed per minute of output.
 *
 * The length comes from the request when it sets one; otherwise it is read
 * from the audio itself (needs the optional getID3 package).
 */
abstract class GeneratedAudioHandler implements ExtractsModelFromRequest, Handler, HasMedia, ReturnsBinaryResponse
{
    use MatchesEndpoints;

    /** The model the API uses when the request names none. */
    abstract protected function defaultModel(): string;

    /**
     * Requested length in seconds, or null to read it from the audio.
     *
     * @param  array<string, mixed>  $requestData
     */
    abstract protected function requestedSeconds(array $requestData): ?float;

    /**
     * Tts, so the duration is read from the returned audio like for speech.
     */
    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    /**
     * Build the audio usage from the length the request asked for.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(durationSeconds: $this->requestedSeconds($requestData)),
        );
    }

    /**
     * Get the placeholder recorded in place of the generated audio.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return '[audio]';
    }

    /**
     * Get the request's `model_id`, or the endpoint's default model.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        $model = $requestData['model_id'] ?? null;

        return filled($model) ? $model : $this->defaultModel();
    }

    /**
     * Store the generated audio body as an mp3 file.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        if (blank($rawBody)) {
            return [];
        }

        return [app(MediaPersister::class)->store($requestId, 0, $rawBody, 'mp3')];
    }
}
