<?php

namespace Spectra\Providers\Google\Handlers;

use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromRequest;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\AudioMetrics;
use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\Google\ExtractsModelFromGoogleEndpoint;
use Spectra\Support\MediaPersister;

class TtsHandler implements ExtractsModelFromRequest, Handler, HasMedia, MatchesResponseShape
{
    use ExtractsModelFromGoogleEndpoint;
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Tts;
    }

    public function endpoints(): array
    {
        return [
            '/{version}/models/{model}:generateContent',
            '/{version}/models/{model}:streamGenerateContent',
        ];
    }

    /**
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
            tokens: new TokenMetrics(
                promptTokens: (int) ($responseData['usageMetadata']['promptTokenCount'] ?? 0),
                completionTokens: (int) ($responseData['usageMetadata']['candidatesTokenCount'] ?? 0),
                cachedTokens: (int) ($responseData['usageMetadata']['cachedContentTokenCount'] ?? 0),
            ),
            audio: new AudioMetrics(
                inputCharacters: $inputCharacters,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['modelVersion'] ?? null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractResponse(array $response): ?string
    {
        foreach ($response['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'audio/')) {
                return '[audio]';
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['inlineData']['mimeType']) && str_starts_with($part['inlineData']['mimeType'], 'audio/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        foreach ($responseData['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (! isset($part['inlineData']['mimeType']) || ! str_starts_with($part['inlineData']['mimeType'], 'audio/')) {
                continue;
            }

            $b64 = $part['inlineData']['data'] ?? null;

            if (! is_string($b64) || $b64 === '') {
                continue;
            }

            $pcm = base64_decode($b64, true);

            if (! is_string($pcm) || $pcm === '') {
                continue;
            }

            $mimeType = $part['inlineData']['mimeType'];
            $sampleRate = $this->parseSampleRate($mimeType);

            // Wrap raw PCM in a WAV header so browsers can play it
            $wav = $this->pcmToWav($pcm, $sampleRate);

            return [app(MediaPersister::class)->store($requestId, 0, $wav, 'wav')];
        }

        return [];
    }

    private function parseSampleRate(string $mimeType): int
    {
        if (preg_match('/rate=(\d+)/', $mimeType, $matches)) {
            return (int) $matches[1];
        }

        return 24000;
    }

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
