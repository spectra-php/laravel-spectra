<?php

namespace Spectra\Providers\XAi\Handlers;

use Spectra\Concerns\MatchesEndpoints;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\StoresOpenAiImageMedia;

class ImageHandler implements Handler, HasMedia
{
    use MatchesEndpoints;
    use StoresOpenAiImageMedia;

    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    public function endpoints(): array
    {
        return ['/v1/images/generations'];
    }

    /**
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        return new Metrics(
            image: new ImageMetrics(
                count: isset($responseData['data']) ? count($responseData['data']) : 0,
            ),
        );
    }

    /** @param  array<string, mixed>  $response */
    public function extractModel(array $response): ?string
    {
        return $response['model'] ?? null;
    }
}
