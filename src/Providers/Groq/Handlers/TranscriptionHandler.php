<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;

class TranscriptionHandler implements ExtractsModelFromResponse, Handler
{
    use ExtractsModelField;
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    public function endpoints(): array
    {
        return [
            '/openai/v1/audio/transcriptions',
            '/openai/v1/audio/translations',
        ];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        // Duration drives per-minute Whisper billing; the verbose_json format
        // reports it top-level, duration-based usage reports usage.seconds.
        $duration = isset($responseData['duration'])
            ? (float) $responseData['duration']
            : (isset($usage['type'], $usage['seconds']) && $usage['type'] === 'duration'
                ? (float) $usage['seconds']
                : null);

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0),
                completionTokens: (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0),
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
