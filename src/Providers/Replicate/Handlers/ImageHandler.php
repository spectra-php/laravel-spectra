<?php

namespace Spectra\Providers\Replicate\Handlers;

use Illuminate\Support\Facades\Http;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasFinishReason;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\MatchesResponseShape;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Support\MediaPersister;

class ImageHandler implements Handler, HasFinishReason, HasMedia, MatchesResponseShape
{
    use MatchesParametricEndpoints;

    public function modelType(): ModelType
    {
        return ModelType::Image;
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
        $output = $responseData['output'] ?? [];

        return new Metrics(
            image: new ImageMetrics(
                count: is_array($output) && ! empty($output) ? count($output) : 0,
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
        $output = $response['output'] ?? [];

        if (! is_array($output) || empty($output)) {
            return null;
        }

        return implode("\n", $output);
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

        // Image models return an array of URL strings
        if (is_array($output) && ! empty($output) && is_string($output[0] ?? null)) {
            return (bool) filter_var($output[0], FILTER_VALIDATE_URL);
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $responseData
     * @return array<string>
     */
    public function storeMedia(string $requestId, array $responseData, ?string $rawBody = null): array
    {
        $persister = app(MediaPersister::class);
        $output = $responseData['output'] ?? [];
        $stored = [];

        if (! is_array($output)) {
            return [];
        }

        foreach ($output as $i => $url) {
            if (is_string($url)) {
                $response = Http::withoutAITracking()->get($url);
                if (! $response->successful()) {
                    continue;
                }
                $extension = $this->resolveExtensionFromContentType($response->header('Content-Type'));
                $stored[] = $persister->store($requestId, $i, $response->body(), $extension);
            }
        }

        return $stored;
    }

    private function resolveExtensionFromContentType(?string $contentType): string
    {
        if (is_string($contentType) && $contentType !== '') {
            $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

            return match ($mime) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                default => 'png',
            };
        }

        return 'png';
    }
}
