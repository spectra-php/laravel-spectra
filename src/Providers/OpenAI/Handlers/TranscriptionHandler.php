<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenAI\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\StreamsResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\OpenAiUsage;
use Spectra\Providers\OpenAI\Streaming\TranscriptionStreaming;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Speech to text: transcriptions and translations.
 *
 * Token-priced models (gpt-4o-transcribe family) report `usage.type: tokens`
 * with audio input and text output tokens. Minute-priced models (whisper-1,
 * gpt-transcribe) report `usage.type: duration` with the billed seconds, or
 * a top-level `duration` in `verbose_json`. The `text`, `srt` and `vtt`
 * formats return plain text with no usage, so nothing can be priced for them.
 */
class TranscriptionHandler implements ExtractsModelFromResponse, Handler, StreamsResponse
{
    use ExtractsModelField;
    use MatchesEndpoints;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    /**
     * Get the transcription and translation endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/audio/transcriptions',
            '/v1/audio/translations',
        ];
    }

    /**
     * Get the token usage and billed audio seconds of a transcription.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        // The billed seconds win over the measured audio length.
        $duration = OpenAiUsage::transcriptionSeconds($usage)
            ?? (is_numeric($responseData['duration'] ?? null) ? (float) $responseData['duration'] : null);

        return new Metrics(
            tokens: OpenAiUsage::fromTranscription($usage),
            audio: new AudioMetrics(
                durationSeconds: $duration,
            ),
        );
    }

    /**
     * Get the transcribed text.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return $response['text'] ?? null;
    }

    /**
     * Get the stream handler for a streamed transcription.
     */
    public function streamingHandler(): StreamHandler
    {
        return new TranscriptionStreaming;
    }
}
