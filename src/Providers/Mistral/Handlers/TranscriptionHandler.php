<?php

declare(strict_types=1);

namespace Spectra\Providers\Mistral\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Mistral (Voxtral) transcription, billed per minute of audio from
 * `usage.prompt_audio_seconds`, or the response's `duration` without it.
 */
class TranscriptionHandler implements ExtractsModelFromResponse, Handler
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type Mistral transcriptions are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    /**
     * Get the Mistral audio transcription endpoint path.
     */
    public function endpoints(): array
    {
        return ['/v1/audio/transcriptions'];
    }

    /**
     * Build the tokens and audio length of a Mistral transcription.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($usage['prompt_tokens'] ?? null),
                completionTokens: Payload::int($usage['completion_tokens'] ?? null),
            ),
            audio: new AudioMetrics(
                durationSeconds: $this->audioSeconds($responseData),
            ),
        );
    }

    /**
     * Get the billed audio length: `usage.prompt_audio_seconds`, or the
     * response's `duration` without it.
     *
     * Voxtral transcription bills per minute, and the API reports the audio
     * length as `usage.prompt_audio_seconds`.
     *
     * @param  array<string, mixed>  $responseData
     */
    private function audioSeconds(array $responseData): ?float
    {
        if (isset($responseData['usage']['prompt_audio_seconds'])) {
            return Payload::float($responseData['usage']['prompt_audio_seconds']);
        }

        if (isset($responseData['duration'])) {
            return Payload::float($responseData['duration']);
        }

        return null;
    }

    /**
     * Get the transcript text of a Mistral transcription.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }
}
