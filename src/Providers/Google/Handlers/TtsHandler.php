<?php

declare(strict_types=1);

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Providers\Google\GeminiUsage;
use Spectra\Providers\Google\ReadsGeminiParts;
use Spectra\Support\MediaPersister;

/**
 * Gemini text-to-speech: `generateContent` responses with inline audio.
 */
class TtsHandler implements ExtractsModelFromRequest, ExtractsModelFromResponse, Handler, HasMedia, MatchesResponseShape
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;
    use ReadsGeminiParts;

    /**
     * Get the model type this handler records.
     */
    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    /**
     * Get the generateContent endpoint paths used for speech.
     */
    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:generateContent',
            '/{version}/models/{model}:streamGenerateContent',
        ];
    }

    /**
     * Get the token usage and input characters of a speech response.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $inputCharacters = null;

        // Count input text characters from the request contents
        foreach ($requestData['contents'] ?? [] as $content) {
            foreach ($content['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $inputCharacters = ($inputCharacters ?? 0) + mb_strlen($part['text']);
                }
            }
        }

        return new Metrics(
            tokens: GeminiUsage::tokens(GeminiUsage::metadata($responseData)),
            audio: new AudioMetrics(
                inputCharacters: $inputCharacters,
            ),
        );
    }

    /**
     * Get the model from the response's `modelVersion`.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractModelFromResponse(array $response): ?string
    {
        return filled($response['modelVersion'] ?? null) ? $response['modelVersion'] : null;
    }

    /**
     * Get a placeholder text for a speech response.
     *
     * @param  array<string, mixed>  $response
     */
    public function extractResponse(array $response): ?string
    {
        return $this->matchesResponse($response) ? '[audio]' : null;
    }

    /**
     * Determine if the first candidate holds inline audio data.
     *
     * @param  array<string, mixed>  $data
     */
    public function matchesResponse(array $data): bool
    {
        foreach ($this->firstCandidateParts($data) as $part) {
            if ($this->isInlinePart($part, 'audio/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store the generated audio as a WAV file and return its path.
     *
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        foreach ($this->firstCandidateParts($responseData) as $part) {
            $pcm = $this->isInlinePart($part, 'audio/') ? $this->inlineBytes($part) : null;

            if ($pcm === null) {
                continue;
            }

            $sampleRate = $this->parseSampleRate($this->inlineMimeType($part) ?? '');

            // Wrap raw PCM in a WAV header so browsers can play it
            $wav = $this->pcmToWav($pcm, $sampleRate);

            return [app(MediaPersister::class)->store($requestId, 0, $wav, 'wav')];
        }

        return [];
    }

    /**
     * Get the sample rate from the audio mime type, defaulting to 24 kHz.
     */
    private function parseSampleRate(string $mimeType): int
    {
        if (preg_match('/rate=(\d+)/', $mimeType, $matches)) {
            return (int) $matches[1];
        }

        return 24000;
    }

    /**
     * Wrap raw PCM audio in a WAV header.
     */
    private function pcmToWav(string $pcm, int $sampleRate, int $channels = 1, int $bitsPerSample = 16): string
    {
        $dataSize = strlen($pcm);
        $byteRate = $sampleRate * $channels * ($bitsPerSample / 8);
        $blockAlign = $channels * ($bitsPerSample / 8);

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)   // ChunkSize
            .'WAVE'
            .'fmt '
            .pack('V', 16)               // Subchunk1Size (PCM)
            .pack('v', 1)                 // AudioFormat (PCM = 1)
            .pack('v', $channels)         // NumChannels
            .pack('V', $sampleRate)       // SampleRate
            .pack('V', $byteRate)         // ByteRate
            .pack('v', $blockAlign)       // BlockAlign
            .pack('v', $bitsPerSample)    // BitsPerSample
            .'data'
            .pack('V', $dataSize);        // Subchunk2Size

        return $header.$pcm;
    }
}
