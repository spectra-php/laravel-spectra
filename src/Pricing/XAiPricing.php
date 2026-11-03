<?php

declare(strict_types=1);

namespace Spectra\Pricing;

use Spectra\Support\Pricing\ModelDefinition;

/**
 * xAI (Grok) prices, from docs.x.ai/developers/pricing and the model pages.
 *
 * Every xAI response reports its exact charge (`cost_in_usd_ticks`), which
 * replaces these estimates; the catalog is the fallback and the source of
 * display names. Rules from the pricing page:
 * - Long context: once the prompt reaches 200K tokens, every token of the
 *   request bills at the long-context rate (`aboveInputTokens: 199_999`).
 * - Priority: 2x standard for all token types. Batch: 20% off for grok-4.3
 *   and the grok-4.20 family only. The long-context priority and batch rows
 *   apply those multipliers to the long-context rates.
 * - Image and video generation bill at standard rates, also in batch.
 *
 * The models xAI retired on 2026-05-15 keep their old prices for history;
 * requests to those ids after that date are redirected and billed at the
 * replacement's price, hence the dated rows.
 */
class XAiPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'xai';
    }

    public function sources(): array
    {
        return ['https://docs.x.ai/developers/pricing'];
    }

    /**
     * Server-side tool fees in cents (web search, code execution and file
     * attachments: $5 / 1K calls; collections search: $2.50 / 1K calls;
     * X Search: $5 / 1K posts and $10 / 1K profiles fetched). MCP, image
     * understanding and image generation are billed by tokens or Imagine rates.
     *
     * @return array<string, float>
     */
    public function toolCallPricing(): array
    {
        return [
            'web_search_call' => 0.5,
            'code_interpreter_call' => 0.5,
            'attachment_search_call' => 0.5,
            'file_search_call' => 0.25,
            'x_search_post' => 0.5,
            'x_search_profile' => 1.0,
        ];
    }

    protected function define(): void
    {
        $this->model('grok-4.7', fn ($m) => $m
            ->displayName('Grok 4.7')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600, cachedInputPrice: 50)
            ->tier('standard', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 100, aboveInputTokens: 199_999)
            ->tier('priority', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 100)
            ->tier('priority', inputPrice: 800, outputPrice: 2400, cachedInputPrice: 200, aboveInputTokens: 199_999));

        $this->model('grok-4.6', fn ($m) => $m
            ->displayName('Grok 4.6')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600, cachedInputPrice: 50)
            ->tier('standard', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 100, aboveInputTokens: 199_999)
            ->tier('priority', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 100)
            ->tier('priority', inputPrice: 800, outputPrice: 2400, cachedInputPrice: 200, aboveInputTokens: 199_999));

        $this->model('grok-4.5', fn ($m) => $m
            ->displayName('Grok 4.5')
            ->aliases('grok-4.5-latest', 'grok-build-latest')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600, cachedInputPrice: 30)
            ->tier('standard', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 60, aboveInputTokens: 199_999)
            ->tier('priority', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 60)
            ->tier('priority', inputPrice: 800, outputPrice: 2400, cachedInputPrice: 120, aboveInputTokens: 199_999));

        $this->model('grok-4.3', fn ($m) => $this->grok43Rates($m
            ->displayName('Grok 4.3')
            ->aliases('grok-4.3-latest')
            ->canGenerateText()));

        $this->model('grok-4.20-multi-agent-0309', fn ($m) => $this->grok43Rates($m
            ->displayName('Grok 4.20 Multi-Agent')
            ->aliases(
                'grok-4.20-multi-agent',
                'grok-4.20-multi-agent-latest',
                'grok-4.20-multi-agent-beta-latest',
                'grok-4.20-multi-agent-beta-0309',
                'grok-4.20-multi-agent-experimental-beta-0304',
                'grok-4.20-multi-agent-experimental-beta-latest',
            )
            ->canGenerateText()));

        $this->model('grok-4.20-0309-reasoning', fn ($m) => $this->grok43Rates($m
            ->displayName('Grok 4.20 Reasoning')
            ->aliases(
                'grok-4.20',
                'grok-4.20-0309',
                'grok-4.20-reasoning',
                'grok-4.20-reasoning-latest',
                'grok-4.20-reasoning-gv2',
                'grok-4.20-beta',
                'grok-4.20-beta-0309',
                'grok-4.20-beta-0309-reasoning',
                'grok-4.20-beta-latest',
                'grok-4.20-beta-latest-reasoning',
                'grok-4.20-beta-reasoning',
                'grok-4.20-experimental-beta-0304',
                'grok-4.20-experimental-beta-0304-reasoning',
                'grok-4.20-experimental-beta-latest',
                'grok-4.20-experimental-beta-reasoning-latest',
            )
            ->canGenerateText()));

        $this->model('grok-4.20-0309-non-reasoning', fn ($m) => $this->grok43Rates($m
            ->displayName('Grok 4.20 Non-Reasoning')
            ->aliases(
                'grok-4.20-non-reasoning',
                'grok-4.20-non-reasoning-latest',
                'grok-4.20-non-reasoning-gv2',
                'grok-4.20-beta-non-reasoning',
                'grok-4.20-beta-0309-non-reasoning',
                'grok-4.20-beta-latest-non-reasoning',
                'grok-4.20-experimental-beta-0304-non-reasoning',
                'grok-4.20-experimental-beta-non-reasoning-latest',
            )
            ->canGenerateText()));

        $this->model('grok-build-0.1', fn ($m) => $m
            ->displayName('Grok Build 0.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 200, cachedInputPrice: 20)
            ->tier('standard', inputPrice: 200, outputPrice: 400, cachedInputPrice: 40, aboveInputTokens: 199_999));

        // Retired 2026-05-15 and redirected to grok-build-0.1. Kept as its own
        // entry (with its snapshot ids) so its dashboard name stays the same.
        $this->model('grok-code-fast-1', fn ($m) => $m
            ->displayName('Grok Code Fast 1')
            ->aliases('grok-code-fast', 'grok-code-fast-1-0825')
            ->retiredOn('2026-05-15')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 150)
            ->tier('standard', inputPrice: 100, outputPrice: 200, cachedInputPrice: 20, since: '2026-05-15'));

        // Retired 2026-05-15; requests to these ids now run on grok-4.3 and
        // are billed at grok-4.3 prices.
        $retiredToGrok43 = [
            'grok-4-1-fast-reasoning' => ['Grok 4.1 Fast Reasoning', 20, 50],
            'grok-4-1-fast-non-reasoning' => ['Grok 4.1 Fast Non-Reasoning', 20, 50],
            'grok-4-fast-reasoning' => ['Grok 4 Fast Reasoning', 20, 50],
            'grok-4-fast-non-reasoning' => ['Grok 4 Fast Non-Reasoning', 20, 50],
            'grok-4-0709' => ['Grok 4 0709', 300, 1500],
            'grok-3' => ['Grok 3', 300, 1500],
        ];

        foreach ($retiredToGrok43 as $id => [$name, $input, $output]) {
            $this->model($id, fn ($m) => $m
                ->displayName($name)
                ->retiredOn('2026-05-15')
                ->canGenerateText()
                ->tier('standard', inputPrice: $input, outputPrice: $output)
                ->tier('standard', inputPrice: 125, outputPrice: 250, cachedInputPrice: 20, since: '2026-05-15'));
        }

        // No longer listed by xAI; no retirement date was published.
        $this->model('grok-3-mini', fn ($m) => $m
            ->displayName('Grok 3 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 50));

        $this->model('grok-2-vision-1212', fn ($m) => $m
            ->displayName('Grok 2 Vision 1212')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000));

        // Images: per image. `hd` is the 2K price (for grok-imagine-image-2.0,
        // 2K at medium quality). The response cost covers 1.5K and low quality.
        $this->model('grok-imagine-image-2.0', fn ($m) => $m
            ->displayName('Grok Imagine Image 2.0')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4)
            ->tier('hd', pricePerUnit: 8));

        // xAI retires grok-imagine-image-quality on 2026-11-02.
        $this->model('grok-imagine-image-quality', fn ($m) => $m
            ->displayName('Grok Imagine Image Quality')
            ->aliases('grok-imagine-image-quality-latest')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 5)
            ->tier('hd', pricePerUnit: 7));

        // Retired 2026-05-15 and redirected to grok-imagine-image-quality.
        $this->model('grok-imagine-image-pro', fn ($m) => $m
            ->displayName('Grok Imagine Image Pro')
            ->retiredOn('2026-05-15')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 7)
            ->tier('standard', pricePerUnit: 5, since: '2026-05-15')
            ->tier('hd', pricePerUnit: 7, since: '2026-05-15'));

        $this->model('grok-imagine-image', fn ($m) => $m
            ->displayName('Grok Imagine Image')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 2));

        // No longer listed by xAI; no retirement date was published.
        $this->model('grok-2-image-1212', fn ($m) => $m
            ->displayName('Grok 2 Image 1212')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 7));

        // Video: per second of output. Standard is 480p, `hd` is 720p; the
        // 1080p rate (grok-imagine-video-1.5: $0.25/s, -lite: $0.14/s) and
        // input images / videos are covered by the reported response cost.
        $this->model('grok-imagine-video-1.5', fn ($m) => $m
            ->displayName('Grok Imagine Video 1.5')
            ->aliases('grok-imagine-video-1.5-preview')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 8)
            ->tier('hd', pricePerUnit: 14));

        $this->model('grok-imagine-video-1.5-lite', fn ($m) => $m
            ->displayName('Grok Imagine Video 1.5 Lite')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 2)
            ->tier('hd', pricePerUnit: 3));

        $this->model('grok-imagine-video', fn ($m) => $m
            ->displayName('Grok Imagine Video')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 5)
            ->tier('hd', pricePerUnit: 7));

        // Voice: TTS $15 / 1M characters; speech to text $0.10 / hour (REST);
        // speech to speech $0.08 / minute.
        $this->model('grok-tts', fn ($m) => $m
            ->displayName('Grok TTS')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1500));

        $this->model('grok-voice-transcribe-2.0', fn ($m) => $m
            ->displayName('Grok Voice Transcribe 2.0')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 10 / 60));

        $this->model('grok-voice-3.0', fn ($m) => $m
            ->displayName('Grok Voice 3.0')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 8));

        // Deprecated by xAI in favour of grok-voice-3.0 (same price).
        $this->model('grok-voice-2.0', fn ($m) => $m
            ->displayName('Grok Voice 2.0')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 8));
    }

    /**
     * Rates shared by grok-4.3 and the grok-4.20 family (the only models with
     * a batch discount).
     */
    protected function grok43Rates(ModelDefinition $model): ModelDefinition
    {
        return $model
            ->tier('standard', inputPrice: 125, outputPrice: 250, cachedInputPrice: 20)
            ->tier('standard', inputPrice: 250, outputPrice: 500, cachedInputPrice: 40, aboveInputTokens: 199_999)
            ->tier('batch', inputPrice: 100, outputPrice: 200, cachedInputPrice: 16)
            ->tier('batch', inputPrice: 200, outputPrice: 400, cachedInputPrice: 32, aboveInputTokens: 199_999)
            ->tier('priority', inputPrice: 250, outputPrice: 500, cachedInputPrice: 40)
            ->tier('priority', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 80, aboveInputTokens: 199_999);
    }
}
