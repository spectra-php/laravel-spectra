<?php

declare(strict_types=1);

namespace Spectra\Providers\ElevenLabs\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;

/**
 * ElevenLabs speech to text (Scribe), `POST /v1/speech-to-text`, billed per
 * minute of audio.
 *
 * The length is `audio_duration_secs` when the response has it, otherwise
 * the end of the last transcribed word (a lower bound: trailing silence is
 * not counted). Requests with `webhook: true` only get a receipt and are
 * not recorded.
 */
class SpeechToTextHandler implements ExtractsModelFromRequest, Handler, SkipsResponse
{
    use MatchesEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Stt;
    }

    public function endpoints(): array
    {
        return ['/v1/speech-to-text'];
    }

    /** @param  array<string, mixed>  $responseData */
    public function shouldSkipResponse(array $responseData): bool
    {
        return ! isset($responseData['text']) && ! isset($responseData['transcripts']);
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            audio: new AudioMetrics(durationSeconds: $this->durationSeconds($responseData)),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        if (is_string($response['text'] ?? null)) {
            return $response['text'];
        }

        // Multichannel audio: one transcript per channel.
        $texts = array_filter(array_map(
            fn ($transcript) => is_array($transcript) && is_string($transcript['text'] ?? null) ? $transcript['text'] : null,
            is_array($response['transcripts'] ?? null) ? $response['transcripts'] : [],
        ));

        return $texts !== [] ? implode("\n", $texts) : null;
    }

    /** @param  array<string, mixed>  $requestData */
    public function extractModelFromRequest(array $requestData, string $endpoint): ?string
    {
        $model = $requestData['model_id'] ?? null;

        return filled($model) ? $model : null;
    }

    /** @param  array<string, mixed>  $response */
    protected function durationSeconds(array $response): ?float
    {
        if (is_numeric($response['audio_duration_secs'] ?? null)) {
            return (float) $response['audio_duration_secs'];
        }

        $transcripts = is_array($response['transcripts'] ?? null) ? $response['transcripts'] : [$response];
        $end = null;

        foreach ($transcripts as $transcript) {
            if (is_array($transcript) && is_numeric($transcript['audio_duration_secs'] ?? null)) {
                $end = max($end ?? 0.0, (float) $transcript['audio_duration_secs']);

                continue;
            }

            foreach (is_array($transcript['words'] ?? null) ? $transcript['words'] : [] as $word) {
                if (is_array($word) && is_numeric($word['end'] ?? null)) {
                    $end = max($end ?? 0.0, (float) $word['end']);
                }
            }
        }

        return $end;
    }
}
