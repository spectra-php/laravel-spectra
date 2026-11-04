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

    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    public function endpoints(): array
    {
        return [
            '/v1/audio/transcriptions',
            '/{project_id}/v1/audio/transcriptions',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        $promptTokens = Payload::int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null);
        $completionTokens = Payload::int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null);

        $duration = isset($responseData['duration'])
            ? (float) $responseData['duration']
            : (isset($usage['type'], $usage['seconds']) && $usage['type'] === 'duration'
                ? (float) $usage['seconds']
                : null);

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
            ),
            audio: new AudioMetrics(
                durationSeconds: $duration,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }
}
