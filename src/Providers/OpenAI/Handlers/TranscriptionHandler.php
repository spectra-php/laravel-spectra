<?php

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class TranscriptionHandler implements Handler
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    public function endpoints(): array
    {
        return [
            '/v1/audio/transcriptions',
            '/v1/audio/translations',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        // Token-based usage: newer API responses may include input_tokens/output_tokens
        $promptTokens = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completionTokens = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);

        // Duration: top-level "duration" (verbose format) or usage.seconds (duration-based billing)
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
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }
}
