<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * fal.ai prices, in cents per image, per megapixel or per video second.
 *
 * Megapixel models round every image up to whole megapixels of 1024 x 1024
 * pixels (see the fal.ai ImageHandler). Edit endpoints that bill like their
 * base model are aliases. Video prices depend on resolution and audio; see
 * {@see self::VIDEO_PRICES}.
 *
 * Source: https://fal.ai/pricing and each model page's billing details
 * (image models last reviewed 2026-10-15, video models 2026-10-27).
 */
class FalAiPricing extends ProviderPricing
{
    /**
     * Cents per video second by resolution, as [with audio, without audio].
     * The first resolution is the model's default, and audio is on by
     * default, so the catalog price is the first entry; other requests are
     * scaled from it by {@see self::videoCostMultiplier()}. `*` is a model
     * without a resolution choice.
     *
     * @var array<string, array<string, array{0: int|float, 1: int|float}>>
     */
    public const VIDEO_PRICES = [
        'fal-ai/kling-video/v3/pro/text-to-video' => ['*' => [16.8, 11.2]],
        'fal-ai/veo3.1' => ['720p' => [40, 20], '1080p' => [40, 20], '4k' => [60, 40]],
        'fal-ai/veo3.1/fast' => ['720p' => [15, 10], '1080p' => [15, 10], '4k' => [35, 30]],
        'fal-ai/veo3.1/lite' => ['720p' => [5, 3], '1080p' => [8, 5]],
        // No audio. Seconds are counted at 16 frames per second.
        'fal-ai/wan/v2.2-a14b/text-to-video' => ['720p' => [8, 8], '580p' => [6, 6], '480p' => [4, 4]],
    ];

    /**
     * How much a video request costs relative to the model's catalog price,
     * from its `resolution` and `generate_audio`; null for other models or
     * a default request.
     *
     * @param  array<string, mixed>  $requestData
     */
    public static function videoCostMultiplier(string $model, array $requestData): ?float
    {
        $prices = self::VIDEO_PRICES[$model] ?? null;

        if ($prices === null) {
            return null;
        }

        $resolution = $requestData['resolution'] ?? null;
        $row = is_string($resolution) && isset($prices[$resolution]) ? $prices[$resolution] : $prices[array_key_first($prices)];
        $price = ($requestData['generate_audio'] ?? true) === false ? $row[1] : $row[0];
        $multiplier = $price / self::defaultVideoPrice($model);

        return $multiplier !== 1.0 ? $multiplier : null;
    }

    protected static function defaultVideoPrice(string $model): float
    {
        $prices = self::VIDEO_PRICES[$model];

        return (float) $prices[array_key_first($prices)][0];
    }

    public function provider(): string
    {
        return 'falai';
    }

    protected function define(): void
    {
        $this->model('fal-ai/flux/dev', fn ($m) => $m
            ->displayName('FLUX.1 Dev')
            ->type('image')
            ->pricingUnit('megapixel')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 2.5));

        $this->model('fal-ai/flux/schnell', fn ($m) => $m
            ->displayName('FLUX.1 Schnell')
            ->type('image')
            ->pricingUnit('megapixel')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0.3));

        $this->model('fal-ai/flux-pro/v1.1', fn ($m) => $m
            ->displayName('FLUX 1.1 Pro')
            ->type('image')
            ->pricingUnit('megapixel')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Billed per image (`billing_unit: images`), whatever its size.
        $this->model('fal-ai/flux-pro/v1.1-ultra', fn ($m) => $m
            ->displayName('FLUX Pro v1.1 Ultra')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 6));

        $this->model('fal-ai/flux-pro/kontext', fn ($m) => $m
            ->displayName('FLUX Pro Kontext')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('fal-ai/flux-pro/kontext/max', fn ($m) => $m
            ->displayName('FLUX Pro Kontext Max')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 8));

        // The model page no longer shows a price; left unchanged.
        $this->model('fal-ai/imagen4/preview', fn ($m) => $m
            ->displayName('Imagen 4 Preview')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Vector styles cost $0.08; FalAi::extractCostMultiplier() doubles
        // the price when the request asks for one.
        $this->model('fal-ai/recraft/v3/text-to-image', fn ($m) => $m
            ->displayName('Recraft V3 Text-to-Image')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('fal-ai/nano-banana', fn ($m) => $m
            ->displayName('Nano Banana')
            ->aliases('fal-ai/nano-banana/edit')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 3.98));

        $this->model('fal-ai/nano-banana-2', fn ($m) => $m
            ->displayName('Nano Banana 2')
            ->aliases('fal-ai/nano-banana-2/edit')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 8));

        $this->model('fal-ai/nano-banana-pro', fn ($m) => $m
            ->displayName('Nano Banana Pro')
            ->aliases('fal-ai/nano-banana-pro/edit')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 15));

        $this->model('fal-ai/kling-video/v3/pro/text-to-video', fn ($m) => $m
            ->displayName('Kling 3.0 Pro Text-to-Video')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: self::defaultVideoPrice('fal-ai/kling-video/v3/pro/text-to-video')));

        $this->model('fal-ai/veo3.1', fn ($m) => $m
            ->displayName('Veo 3.1')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: self::defaultVideoPrice('fal-ai/veo3.1')));

        $this->model('fal-ai/veo3.1/fast', fn ($m) => $m
            ->displayName('Veo 3.1 Fast')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: self::defaultVideoPrice('fal-ai/veo3.1/fast')));

        $this->model('fal-ai/veo3.1/lite', fn ($m) => $m
            ->displayName('Veo 3.1 Lite')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: self::defaultVideoPrice('fal-ai/veo3.1/lite')));

        $this->model('fal-ai/wan/v2.2-a14b/text-to-video', fn ($m) => $m
            ->displayName('Wan 2.2 A14B Text-to-Video')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: self::defaultVideoPrice('fal-ai/wan/v2.2-a14b/text-to-video')));
    }
}
