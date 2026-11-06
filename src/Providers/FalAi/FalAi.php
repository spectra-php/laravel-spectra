<?php

declare(strict_types=1);

namespace Spectra\Providers\FalAi;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spectra\Contracts\ExtractsCostMultiplierForModel;
use Spectra\Providers\FalAi\Handlers\ImageHandler;
use Spectra\Providers\FalAi\Handlers\QueueStatusHandler;
use Spectra\Providers\FalAi\Handlers\VideoHandler;
use Spectra\Providers\Provider;
use Spectra\Support\Pricing\PricingLookup;

/**
 * fal.ai model APIs on `fal.run` (direct) and `queue.fal.run` (queued).
 */
class FalAi extends Provider implements ExtractsCostMultiplierForModel
{
    /**
     * Get the slug fal.ai requests are recorded under.
     */
    public function getProvider(): string
    {
        return 'falai';
    }

    /**
     * Get the fal.ai hosts for direct and queued calls.
     */
    public function getHosts(): array
    {
        return ['fal.run', 'queue.fal.run'];
    }

    /**
     * Get the handlers for fal.ai image and video results and queue calls.
     *
     * Image and video results share every path; the response shape decides,
     * and ImageHandler takes anything else.
     */
    public function handlers(): array
    {
        return [
            app(ImageHandler::class),
            app(VideoHandler::class),
            app(QueueStatusHandler::class),
        ];
    }

    /**
     * Get the request id from the result path, or from the body's `request_id`.
     *
     * A queued result is fetched from `.../requests/{id}`, so the request id
     * in the path identifies the job; repeated fetches update one row.
     */
    public function extractResponseId(mixed $response, string $endpoint = ''): ?string
    {
        $pathRequestId = Str::match('#/requests/([^/]+)(/response)?$#', $endpoint);

        if (filled($pathRequestId)) {
            return $pathRequestId;
        }

        $id = $this->toArray($response)['request_id'] ?? null;

        return filled($id) ? $id : null;
    }

    /**
     * Get the cost multiplier a fal.ai request's options put on the model's catalog price.
     *
     * Video models cost more or less than their catalog price with another
     * resolution or without audio: the catalog lists each resolution's price
     * per second with and without audio as the model's `variants`.
     *
     * Recraft charges twice the raster price for vector styles
     * (`style: vector_illustration...`): $0.08 instead of $0.04 per image.
     *
     * Both are known from the request body, which a queued result fetch
     * takes from its submit request.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $requestData
     */
    public function extractCostMultiplierForModel(string $model, array $responseData, array $requestData): ?float
    {
        $videoMultiplier = $this->videoCostMultiplier(app(PricingLookup::class)->getVariants('falai', $model), $requestData);

        if ($videoMultiplier !== null) {
            return $videoMultiplier;
        }

        $style = $requestData['style'] ?? null;

        return is_string($style) && Str::startsWith($style, 'vector_illustration') ? 2.0 : null;
    }

    /**
     * Get the cost of a video request relative to the model's catalog price.
     *
     * The catalog price is the first resolution with audio. The multiplier
     * comes from the request's `resolution` and `generate_audio`; it is null
     * for a model without variants or a default request.
     *
     * @param  array<string, mixed>  $variants  Resolution => ['audio' => cents, 'silent' => cents]
     * @param  array<string, mixed>  $requestData
     */
    protected function videoCostMultiplier(array $variants, array $requestData): ?float
    {
        $default = Arr::first($variants);

        if (! is_array($default) || ! is_numeric($default['audio'] ?? null) || (float) $default['audio'] <= 0) {
            return null;
        }

        $resolution = $requestData['resolution'] ?? null;
        $requested = is_string($resolution) ? ($variants[$resolution] ?? null) : null;
        $row = is_array($requested) ? $requested : $default;
        $price = ($requestData['generate_audio'] ?? true) === false ? ($row['silent'] ?? $row['audio'] ?? null) : ($row['audio'] ?? null);

        if (! is_numeric($price)) {
            return null;
        }

        $multiplier = (float) $price / (float) $default['audio'];

        return $multiplier !== 1.0 ? $multiplier : null;
    }
}
