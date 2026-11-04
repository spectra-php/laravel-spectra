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
use Spectra\Support\Payload;

/**
 * Groq Whisper transcriptions and translations, billed per minute of audio.
 *
 * Groq bills at least 10 seconds per request, so the recorded duration is the
 * billed duration: `max(10, audio length)`. The audio length is only in the
 * `verbose_json` response (`duration`); the default `json` and `text` formats
 * return just the text, so those requests are recorded at the 10-second
 * minimum, which is the least they can have cost.
 */
class TranscriptionHandler implements ExtractsModelFromResponse, Handler
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /** Groq's minimum billed length per request, in seconds. */
    public const MINIMUM_BILLED_SECONDS = 10.0;

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
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return new Metrics(
            tokens: new TokenMetrics(
                promptTokens: Payload::int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null),
                completionTokens: Payload::int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null),
            ),
            audio: new AudioMetrics(
                durationSeconds: max(self::MINIMUM_BILLED_SECONDS, $this->audioSeconds($responseData, $usage) ?? 0.0),
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }

    /**
     * The audio length the response reports: `duration` (verbose_json) or a
     * duration-type `usage.seconds`.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $usage
     */
    protected function audioSeconds(array $responseData, array $usage): ?float
    {
        if (is_numeric($responseData['duration'] ?? null)) {
            return (float) $responseData['duration'];
        }

        if (($usage['type'] ?? null) === 'duration' && is_numeric($usage['seconds'] ?? null)) {
            return (float) $usage['seconds'];
        }

        return null;
    }
}
