<?php

namespace Spectra\Providers\Replicate\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\Metrics;
use Spectra\Data\VideoMetrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class VideoHandler implements Handler, HasFinishReason, HasMedia, MatchesResponseShape
{
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Video;
    }

    public function endpoints(): array
    {
        return ['/v1/models/{owner}/{model}/predictions'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            video: new VideoMetrics(
                count: 1,
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
        $output = $response['output'] ?? null;

        if (is_string($output) && filter_var($output, FILTER_VALIDATE_URL)) {
            return $output;
        }

        return null;
    }

    /** @param  array<string, mixed>  $response */
    public function extractFinishReason(array $response): ?string
    {
        return $response['status'] ?? null;
    }

    /** @param  array<string, mixed>  $data */
    public function matchesResponse(array $data): bool
    {
        if (! isset($data['urls'], $data['status'])) {
            return false;
        }

        $output = $data['output'] ?? null;

        // Video models output a single URL string
        if (is_string($output) && filter_var($output, FILTER_VALIDATE_URL)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $output = $responseData['output'] ?? null;

        if (! is_string($output) || ! filter_var($output, FILTER_VALIDATE_URL)) {
            return [];
        }

        $response = Http::withoutAITracking()->get($output);

        if (! $response->successful()) {
            return [];
        }

        $extension = $this->resolveExtensionFromContentType($response->header('Content-Type'));

        return [app(MediaPersister::class)->store($requestId, 0, $response->body(), $extension)];
    }

    private function resolveExtensionFromContentType(?string $contentType): string
    {
        if (is_string($contentType) && $contentType !== '') {
            $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

            return match ($mime) {
                'video/mp4' => 'mp4',
                'video/webm' => 'webm',
                'video/quicktime' => 'mov',
                'video/x-msvideo' => 'avi',
                default => 'mp4',
            };
        }

        return 'mp4';
    }
}
