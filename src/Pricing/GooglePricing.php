<?php

declare(strict_types=1);

namespace Spectra\Pricing;

use Spectra\Support\Pricing\ModelDefinition;

/**
 * Gemini Developer API prices, in US cents per 1M tokens (Veo: cents per
 * second of video).
 *
 * Source: https://ai.google.dev/gemini-api/docs/pricing
 * Shutdown dates: https://ai.google.dev/gemini-api/docs/deprecations
 *
 * Output prices include thinking tokens. `audioInputPrice` is set where audio
 * input costs more than text; cached audio (also dearer on some models) is
 * billed at the text cache rate because usage doesn't split cache reads by
 * modality. Image models list the text output rate as `outputPrice` and the
 * image output rate as `imageOutputPrice`. Context-cache storage (per token
 * per hour) and search grounding (free allowance, then per query) are not
 * per-request costs and are not modelled.
 */
class GooglePricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'google';
    }

    protected function define(): void
    {
        // Prices double on 2026-12-01.
        $this->model('gemini-4-argon', fn ($m) => $m
            ->displayName('Gemini 4 Argon')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10)
            ->tier('standard', inputPrice: 400, outputPrice: 2000, cachedInputPrice: 20, since: '2026-12-01')
            ->tier('batch', inputPrice: 100, outputPrice: 500, cachedInputPrice: 5)
            ->tier('batch', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, since: '2026-12-01')
            ->tier('flex', inputPrice: 100, outputPrice: 500, cachedInputPrice: 5)
            ->tier('flex', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, since: '2026-12-01')
            ->tier('priority', inputPrice: 360, outputPrice: 1800, cachedInputPrice: 18)
            ->tier('priority', inputPrice: 720, outputPrice: 3600, cachedInputPrice: 36, since: '2026-12-01'));

        // `gemini-3.7-flash` is not shut down: the API routes it to 3.8 Flash.
        $this->model('gemini-3.8-flash', fn ($m) => $this->flash36Tiers($m
            ->displayName('Gemini 3.8 Flash')
            ->aliases('gemini-3.7-flash')
            ->canGenerateText()));

        $this->model('gemini-3.6-flash', fn ($m) => $this->flash36Tiers($m
            ->displayName('Gemini 3.6 Flash')
            ->canGenerateText()));

        // No longer listed: the API now routes `gemini-3.5-flash` to 3.6 Flash,
        // whose reported `modelVersion` is priced first. Kept for history.
        $this->model('gemini-3.5-flash', fn ($m) => $m
            ->displayName('Gemini 3.5 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 150, outputPrice: 900, cachedInputPrice: 15)
            ->tier('batch', inputPrice: 75, outputPrice: 450, cachedInputPrice: 7.5)
            ->tier('flex', inputPrice: 75, outputPrice: 450, cachedInputPrice: 8)
            ->tier('priority', inputPrice: 270, outputPrice: 1620, cachedInputPrice: 27));

        $this->model('gemini-3.5-flash-lite', fn ($m) => $m
            ->displayName('Gemini 3.5 Flash-Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250, cachedInputPrice: 3)
            ->tier('batch', inputPrice: 15, outputPrice: 125, cachedInputPrice: 2)
            ->tier('flex', inputPrice: 15, outputPrice: 125, cachedInputPrice: 2)
            ->tier('priority', inputPrice: 54, outputPrice: 450, cachedInputPrice: 5));

        $this->model('gemini-3.1-pro-preview', fn ($m) => $this->pro31Tiers($m
            ->displayName('Gemini 3.1 Pro Preview')
            ->canGenerateText()));

        // The custom-tools endpoint bills exactly like 3.1 Pro Preview.
        $this->model('gemini-3.1-pro-preview-customtools', fn ($m) => $this->pro31Tiers($m
            ->displayName('Gemini 3.1 Pro Preview Custom Tools')
            ->canGenerateText()));

        $this->model('gemini-3.1-flash-lite', fn ($m) => $m
            ->displayName('Gemini 3.1 Flash-Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 150, cachedInputPrice: 2.5, audioInputPrice: 50)
            ->tier('batch', inputPrice: 12.5, outputPrice: 75, cachedInputPrice: 1.25, audioInputPrice: 25)
            ->tier('flex', inputPrice: 12.5, outputPrice: 75, cachedInputPrice: 1.25, audioInputPrice: 25)
            ->tier('priority', inputPrice: 45, outputPrice: 270, cachedInputPrice: 4.5, audioInputPrice: 90));

        $this->model('gemini-3-pro-preview', fn ($m) => $m
            ->displayName('Gemini 3 Pro Preview')
            ->retiredOn('2026-03-09')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1200, cachedInputPrice: 20)
            ->tier('batch', inputPrice: 100, outputPrice: 600, cachedInputPrice: 20));

        // Batch and flex keep the standard cache price.
        $this->model('gemini-3-flash-preview', fn ($m) => $m
            ->displayName('Gemini 3 Flash Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 300, cachedInputPrice: 5, audioInputPrice: 100)
            ->tier('batch', inputPrice: 25, outputPrice: 150, cachedInputPrice: 5, audioInputPrice: 50)
            ->tier('flex', inputPrice: 25, outputPrice: 150, cachedInputPrice: 5, audioInputPrice: 50)
            ->tier('priority', inputPrice: 90, outputPrice: 540, cachedInputPrice: 9, audioInputPrice: 180));

        // Prompts over 200K tokens pay the higher row.
        $this->model('gemini-2.5-pro', fn ($m) => $m
            ->displayName('Gemini 2.5 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('standard', inputPrice: 250, outputPrice: 1500, cachedInputPrice: 25, aboveInputTokens: 200_000)
            ->tier('batch', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 12.5)
            ->tier('batch', inputPrice: 125, outputPrice: 750, cachedInputPrice: 25, aboveInputTokens: 200_000)
            ->tier('flex', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 12.5)
            ->tier('flex', inputPrice: 125, outputPrice: 750, cachedInputPrice: 25, aboveInputTokens: 200_000)
            ->tier('priority', inputPrice: 225, outputPrice: 1800, cachedInputPrice: 22.5)
            ->tier('priority', inputPrice: 450, outputPrice: 2700, cachedInputPrice: 45, aboveInputTokens: 200_000));

        $this->model('gemini-2.5-flash', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250, cachedInputPrice: 3, audioInputPrice: 100)
            ->tier('batch', inputPrice: 15, outputPrice: 125, cachedInputPrice: 3, audioInputPrice: 50)
            ->tier('flex', inputPrice: 15, outputPrice: 125, cachedInputPrice: 3, audioInputPrice: 50)
            ->tier('priority', inputPrice: 54, outputPrice: 450, cachedInputPrice: 5.4, audioInputPrice: 180));

        $this->model('gemini-2.5-flash-lite', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash-Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 1, audioInputPrice: 30)
            ->tier('batch', inputPrice: 5, outputPrice: 20, cachedInputPrice: 1, audioInputPrice: 15)
            ->tier('flex', inputPrice: 5, outputPrice: 20, cachedInputPrice: 1, audioInputPrice: 15)
            ->tier('priority', inputPrice: 18, outputPrice: 72, cachedInputPrice: 1.8, audioInputPrice: 54));

        $this->model('gemini-2.0-flash', fn ($m) => $m
            ->displayName('Gemini 2.0 Flash')
            ->aliases('gemini-2.0-flash-001')
            ->retiredOn('2026-06-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 2.5)
            ->tier('batch', inputPrice: 5, outputPrice: 20, cachedInputPrice: 2.5));

        $this->model('gemini-2.0-flash-lite', fn ($m) => $m
            ->displayName('Gemini 2.0 Flash-Lite')
            ->aliases('gemini-2.0-flash-lite-001')
            ->retiredOn('2026-06-01')
            ->canGenerateText()
            ->tier('standard', inputPrice: 7.5, outputPrice: 30)
            ->tier('batch', inputPrice: 3.75, outputPrice: 15));

        // Audio / TTS (output is audio, priced as the output rate). The 3.8
        // TTS prices double on 2027-01-01.
        $this->model('gemini-3.8-flash-tts', fn ($m) => $m
            ->displayName('Gemini 3.8 Flash TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 50, outputPrice: 900)
            ->tier('standard', inputPrice: 100, outputPrice: 1800, since: '2027-01-01')
            ->tier('batch', inputPrice: 25, outputPrice: 450)
            ->tier('batch', inputPrice: 50, outputPrice: 900, since: '2027-01-01')
            ->tier('flex', inputPrice: 25, outputPrice: 450)
            ->tier('flex', inputPrice: 50, outputPrice: 900, since: '2027-01-01')
            ->tier('priority', inputPrice: 90, outputPrice: 1620)
            ->tier('priority', inputPrice: 180, outputPrice: 3240, since: '2027-01-01'));

        $this->model('gemini-3.8-flash-lite-tts', fn ($m) => $m
            ->displayName('Gemini 3.8 Flash-Lite TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 50, outputPrice: 600)
            ->tier('standard', inputPrice: 100, outputPrice: 1200, since: '2027-01-01')
            ->tier('batch', inputPrice: 25, outputPrice: 300)
            ->tier('batch', inputPrice: 50, outputPrice: 600, since: '2027-01-01')
            ->tier('flex', inputPrice: 25, outputPrice: 300)
            ->tier('flex', inputPrice: 50, outputPrice: 600, since: '2027-01-01')
            ->tier('priority', inputPrice: 90, outputPrice: 1080)
            ->tier('priority', inputPrice: 180, outputPrice: 2160, since: '2027-01-01'));

        // Live (image and video input, $1.00, bill at the text rate because
        // usage doesn't split them). The three models share one price.
        $this->model('gemini-3.8-live', fn ($m) => $m
            ->displayName('Gemini 3.8 Live')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 75, outputPrice: 450, audioInputPrice: 300, audioOutputPrice: 1200));

        $this->model('gemini-3.8-live-extended-thinking', fn ($m) => $m
            ->displayName('Gemini 3.8 Live Extended Thinking')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 75, outputPrice: 450, audioInputPrice: 300, audioOutputPrice: 1200));

        $this->model('gemini-3.1-flash-live-preview', fn ($m) => $m
            ->displayName('Gemini 3.1 Flash Live Preview')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 75, outputPrice: 450, audioInputPrice: 300, audioOutputPrice: 1200));

        // Audio in, audio out only.
        $this->model('gemini-3.5-live-translate-preview', fn ($m) => $m
            ->displayName('Gemini 3.5 Live Translate Preview')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 350, outputPrice: 2100));

        // Omni: every input modality is $1.50. Video output ($17.50, at 5,792
        // tokens per second of 720p video) has no price field and bills at the
        // text output rate.
        $this->model('gemini-omni-1.1-flash', fn ($m) => $m
            ->displayName('Gemini Omni Flash')
            ->aliases('gemini-omni-flash-preview')
            ->canGenerateText()
            ->canGenerateVideo()
            ->tier('standard', inputPrice: 150, outputPrice: 900));

        $this->model('gemini-3.1-flash-tts-preview', fn ($m) => $m
            ->displayName('Gemini 3.1 Flash TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 100, outputPrice: 2000)
            ->tier('batch', inputPrice: 50, outputPrice: 1000));

        $this->model('gemini-2.5-flash-preview-tts', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 50, outputPrice: 1000)
            ->tier('batch', inputPrice: 25, outputPrice: 500));

        $this->model('gemini-2.5-pro-preview-tts', fn ($m) => $m
            ->displayName('Gemini 2.5 Pro TTS')
            ->type('audio')
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 100, outputPrice: 2000)
            ->tier('batch', inputPrice: 50, outputPrice: 1000));

        // Image (outputPrice = text and thinking, imageOutputPrice = image tokens)
        $this->model('gemini-nano-banana-2.1', fn ($m) => $m
            ->displayName('Gemini Nano Banana 2.1')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 150, outputPrice: 750, imageOutputPrice: 3000)
            ->tier('batch', inputPrice: 75, outputPrice: 375, imageOutputPrice: 1500));

        $this->model('gemini-3.1-flash-image', fn ($m) => $m
            ->displayName('Gemini 3.1 Flash Image')
            ->aliases('gemini-3.1-flash-image-preview')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 50, outputPrice: 300, imageOutputPrice: 6000)
            ->tier('batch', inputPrice: 25, outputPrice: 150, imageOutputPrice: 3000));

        $this->model('gemini-3.1-flash-lite-image', fn ($m) => $m
            ->displayName('Gemini 3.1 Flash-Lite Image')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 25, outputPrice: 150, imageOutputPrice: 3000)
            ->tier('batch', inputPrice: 12.5, outputPrice: 75, imageOutputPrice: 1500));

        // Text is priced like Gemini 3.1 Pro; image output is $120 / 1M tokens.
        $this->model('gemini-3-pro-image', fn ($m) => $m
            ->displayName('Gemini 3 Pro Image')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 200, outputPrice: 1200, imageOutputPrice: 12000)
            ->tier('batch', inputPrice: 100, outputPrice: 600, imageOutputPrice: 6000)
            ->tier('flex', inputPrice: 100, outputPrice: 600, imageOutputPrice: 6000)
            ->tier('priority', inputPrice: 360, outputPrice: 2160, imageOutputPrice: 21600));

        $this->model('gemini-3-pro-image-preview', fn ($m) => $m
            ->displayName('Gemini 3 Pro Image Preview')
            ->retiredOn('2026-06-25')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 200, outputPrice: 1200, imageOutputPrice: 12000)
            ->tier('batch', inputPrice: 100, outputPrice: 600, imageOutputPrice: 6000));

        // Text is priced like Gemini 2.5 Flash; images are $30 / 1M tokens.
        // Priority is listed as $0.0702 per 1,290-token image, 1.8x like
        // every other priority rate, so 1.8 x $30 per 1M image tokens.
        $this->model('gemini-2.5-flash-image', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 30, outputPrice: 250, imageOutputPrice: 3000)
            ->tier('batch', inputPrice: 15, outputPrice: 125, imageOutputPrice: 1500)
            ->tier('flex', inputPrice: 15, outputPrice: 125, imageOutputPrice: 1500)
            ->tier('priority', inputPrice: 54, outputPrice: 450, imageOutputPrice: 5400));

        // Robotics (Robotics-ER 2 prices double on 2027-01-01)
        $this->model('gemini-robotics-er-2-preview', fn ($m) => $m
            ->displayName('Gemini Robotics-ER 2 Preview')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10)
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 20, since: '2027-01-01')
            ->tier('batch', inputPrice: 50, outputPrice: 250, cachedInputPrice: 5)
            ->tier('batch', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10, since: '2027-01-01'));

        $this->model('gemini-robotics-er-1.6-preview', fn ($m) => $m
            ->displayName('Gemini Robotics-ER 1.6 Preview')
            ->retiredOn('2026-08-31')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 500)
            ->tier('batch', inputPrice: 50, outputPrice: 250));

        $this->model('gemini-robotics-er-1.5-preview', fn ($m) => $m
            ->displayName('Gemini Robotics-ER 1.5 Preview')
            ->retiredOn('2026-04-30')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250));

        // Computer Use
        $this->model('gemini-2.5-computer-use-preview-10-2025', fn ($m) => $m
            ->displayName('Gemini 2.5 Computer Use Preview')
            ->retiredOn('2026-07-28')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000)
            ->tier('standard', inputPrice: 250, outputPrice: 1500, aboveInputTokens: 200_000));

        // Embedding. Embedding 2 also takes video ($12.00 per 1M tokens), which
        // has no price field and bills at the text rate.
        $this->model('gemini-embedding-2', fn ($m) => $m
            ->displayName('Gemini Embedding 2')
            ->type('embedding')
            ->tier('standard', inputPrice: 20, outputPrice: 0, audioInputPrice: 650, imageInputPrice: 45)
            ->tier('batch', inputPrice: 10, outputPrice: 0, audioInputPrice: 325, imageInputPrice: 22.5));

        $this->model('gemini-embedding-001', fn ($m) => $m
            ->displayName('Gemini Embedding')
            ->type('embedding')
            ->tier('standard', inputPrice: 15, outputPrice: 0)
            ->tier('batch', inputPrice: 7.5, outputPrice: 0));

        // Veo — video with audio, cents per second. The `hd` tier is 1080p
        // where it costs more than 720p; 4K is not modelled yet.
        $this->model('veo-2.0-generate-001', fn ($m) => $m
            ->displayName('Veo 2')
            ->retiredOn('2026-06-30')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 35));

        $this->model('veo-3.0-generate-001', fn ($m) => $m
            ->displayName('Veo 3')
            ->retiredOn('2026-06-30')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 40));

        $this->model('veo-3.0-fast-generate-001', fn ($m) => $m
            ->displayName('Veo 3 Fast')
            ->retiredOn('2026-06-30')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 15));

        $this->model('veo-3.1-generate-preview', fn ($m) => $m
            ->displayName('Veo 3.1')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 40));

        $this->model('veo-3.1-fast-generate-preview', fn ($m) => $m
            ->displayName('Veo 3.1 Fast')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 10)
            ->tier('hd', pricePerUnit: 12));

        $this->model('veo-3.1-lite-generate-preview', fn ($m) => $m
            ->displayName('Veo 3.1 Lite')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 5)
            ->tier('hd', pricePerUnit: 8));

        $this->model('veo-3.1-generate-001', fn ($m) => $m
            ->displayName('Veo 3.1')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 40));

        $this->model('veo-3.1-fast-generate-001', fn ($m) => $m
            ->displayName('Veo 3.1 Fast')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 15));
    }

    /**
     * Gemini 3.6 / 3.8 Flash. Prices double on 2027-01-01.
     */
    protected function flash36Tiers(ModelDefinition $m): ModelDefinition
    {
        return $m
            ->tier('standard', inputPrice: 75, outputPrice: 375, cachedInputPrice: 7.5)
            ->tier('standard', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, since: '2027-01-01')
            ->tier('batch', inputPrice: 37.5, outputPrice: 187.5, cachedInputPrice: 3.75)
            ->tier('batch', inputPrice: 75, outputPrice: 375, cachedInputPrice: 7.5, since: '2027-01-01')
            ->tier('flex', inputPrice: 37.5, outputPrice: 187.5, cachedInputPrice: 3.75)
            ->tier('flex', inputPrice: 75, outputPrice: 375, cachedInputPrice: 7.5, since: '2027-01-01')
            ->tier('priority', inputPrice: 135, outputPrice: 675, cachedInputPrice: 13.5)
            ->tier('priority', inputPrice: 270, outputPrice: 1350, cachedInputPrice: 27, since: '2027-01-01');
    }

    /**
     * Gemini 3.1 Pro Preview. Prompts over 200K tokens pay the higher row;
     * batch and flex keep the standard cache price.
     */
    protected function pro31Tiers(ModelDefinition $m): ModelDefinition
    {
        return $m
            ->tier('standard', inputPrice: 200, outputPrice: 1200, cachedInputPrice: 20)
            ->tier('standard', inputPrice: 400, outputPrice: 1800, cachedInputPrice: 40, aboveInputTokens: 200_000)
            ->tier('batch', inputPrice: 100, outputPrice: 600, cachedInputPrice: 20)
            ->tier('batch', inputPrice: 200, outputPrice: 900, cachedInputPrice: 40, aboveInputTokens: 200_000)
            ->tier('flex', inputPrice: 100, outputPrice: 600, cachedInputPrice: 20)
            ->tier('flex', inputPrice: 200, outputPrice: 900, cachedInputPrice: 40, aboveInputTokens: 200_000)
            ->tier('priority', inputPrice: 360, outputPrice: 2160, cachedInputPrice: 36)
            ->tier('priority', inputPrice: 720, outputPrice: 3240, cachedInputPrice: 72, aboveInputTokens: 200_000);
    }
}
