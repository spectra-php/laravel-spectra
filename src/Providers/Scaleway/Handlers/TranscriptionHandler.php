<?php

declare(strict_types=1);

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\Payload;

/**
 * Scaleway audio transcription (Whisper), billed per audio minute. The
 * length is `duration` (verbose JSON) or a `usage` of type `duration`.
 */
class TranscriptionHandler implements ExtractsModelFromResponse, Handler
{
    use ExtractsModelField;
    use MatchesParametricEndpoints;

    /**
     * Get the model type Scaleway transcriptions are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    /**
     * Get the Scaleway audio transcription paths, with or without a project id.
     */
    public function endpoints(): array
    {
        return [
            '/v1/audio/transcriptions',
            '/{project_id}/v1/audio/transcriptions',
        ];
    }

    /**
     * Build the tokens and audio length of a Scaleway transcription.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        $promptTokens = Payload::int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null);
        $completionTokens = Payload::int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null);

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            ),
            audio: new AudioMetrics(
                durationSeconds: $this->audioSeconds($responseData),
            ),
        );
    }

    /**
     * Get the audio length: `duration` (verbose JSON) or a duration-type `usage.seconds`.
     *
     * @param  array<string, mixed>  $responseData
     */
    private function audioSeconds(array $responseData): ?float
    {
        if (isset($responseData['duration'])) {
            return Payload::float($responseData['duration']);
        }

        $usage = $responseData['usage'] ?? [];

        if (isset($usage['type'], $usage['seconds']) && $usage['type'] === 'duration') {
            return Payload::float($usage['seconds']);
        }

        return null;
    }

    /**
     * Get the transcript text of a Scaleway transcription.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }
}
