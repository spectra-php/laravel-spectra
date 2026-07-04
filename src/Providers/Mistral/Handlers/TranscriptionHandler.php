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
        return ['/v1/audio/transcriptions'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = $responseData['usage'] ?? [];

        // Voxtral transcription bills per minute; the API reports the audio
        // length as usage.prompt_audio_seconds.
        $duration = isset($usage['prompt_audio_seconds'])
            ? (float) $usage['prompt_audio_seconds']
            : (isset($responseData['duration']) ? (float) $responseData['duration'] : null);

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: (int) ($usage['prompt_tokens'] ?? 0),
                completionTokens: (int) ($usage['completion_tokens'] ?? 0),
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
