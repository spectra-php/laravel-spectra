<?php

declare(strict_types=1);

namespace Spectra\Providers\XAi\Handlers;

use Spectra\Concerns\ExtractsModelField;
use Spectra\Concerns\MatchesParametricEndpoints;
use Spectra\Contracts\ExtractsModelFromResponse;
use Spectra\Contracts\Handler;
use Spectra\Contracts\HasMedia;
use Spectra\Contracts\SkipsResponse;
use Spectra\Data\ImageMetrics;
use Spectra\Data\Metrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\OpenAI\StoresOpenAiImageMedia;
use Spectra\Providers\XAi\XAiUsage;

/**
 * xAI image generation and editing (Grok Imagine Image).
 *
 * Prices depend on the model, resolution and quality, and edits also bill the
 * input images, so the exact charge from `usage.cost_in_usd_ticks` is used.
 * A `deferred: true` request returns only a `request_id`; the images are then
 * fetched from `GET /v1/images/{request_id}` (pending until `status` is `done`).
 */
class ImageHandler implements ExtractsModelFromResponse, Handler, HasMedia, SkipsResponse
{
    use ExtractsModelField;
    use MatchesParametricEndpoints;
    use StoresOpenAiImageMedia;

    /**
     * Get the model type xAI image requests are recorded as.
     */
    public function modelType(): ModelType
    {
        return ModelType::Image;
    }

    /**
     * Get the xAI image generation, edit and deferred result endpoint paths.
     */
    public function endpoints(): array
    {
        return [
            '/v1/images/generations',
            '/v1/images/edits',
            '/v1/images/{request_id}',
        ];
    }

    /**
     * Skip deferred acknowledgements, pending polls and failed jobs.
     *
     * @param  array<string, mixed>  $responseData
     */
    public function shouldSkipResponse(array $responseData): bool
    {
        if (isset($responseData['status'])) {
            return $responseData['status'] !== 'done';
        }

        return isset($responseData['request_id']) && ! isset($responseData['data']);
    }

    /**
     * Build the image count, prompt-rewriting tokens and reported cost of an xAI image job.
     *
     * @param  array<string, mixed>  $requestData
     * @param  array<string, mixed>  $responseData
     */
    public function extractMetrics(array $requestData, array $responseData): Metrics
    {
        $usage = is_array($responseData['usage'] ?? null) ? $responseData['usage'] : [];

        return new Metrics(
            tokens: XAiUsage::mediaTokens($usage),
            image: new ImageMetrics(
                count: is_array($responseData['data'] ?? null) ? count($responseData['data']) : 0,
            ),
            reportedCostInCents: XAiUsage::costInCents($usage),
        );
    }
}
