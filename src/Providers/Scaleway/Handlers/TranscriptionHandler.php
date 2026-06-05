<?php

namespace Spectra\Providers\Scaleway\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class TranscriptionHandler implements ExtractsModelFromResponse, Handler
{
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    public function endpoints(): array
    {
        return ['/{project_id}/v1/audio/transcriptions'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);

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
    public function extractModelFromResponse(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }
}
