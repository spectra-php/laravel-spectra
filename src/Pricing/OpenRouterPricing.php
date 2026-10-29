<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * OpenRouter list prices, in cents per 1M tokens (or per unit).
 *
 * OpenRouter reports the exact cost of every request in `usage.cost`, and
 * Spectra records that cost. These prices are only a fallback for responses
 * without it, so the catalog covers popular models rather than all of them.
 * Aliases are OpenRouter's dated `canonical_slug`s.
 *
 * Source: https://openrouter.ai/api/v1/models and
 * https://openrouter.ai/api/v1/images/models/{author}/{slug}/endpoints
 * (last reviewed 2026-10-29). Long-context rows come from the per-model
 * `pricing.overrides` (`min_prompt_tokens`). Gemini cache writes are priced
 * at OpenRouter's `input_cache_write`, which is the context-cache storage
 * rate for the default 5-minute TTL rather than the input price.
 */
class OpenRouterPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'openrouter';
    }

    protected function define(): void
    {
        // Anthropic
        $this->model('anthropic/claude-opus-5.5', fn ($m) => $m
            ->displayName('Claude Opus 5.5')
            ->aliases('anthropic/claude-opus-5.5-20260921')
            ->canGenerateText()
            ->tier('standard', inputPrice: 400, outputPrice: 2000, cachedInputPrice: 20, cacheWrite5mPrice: 500, cacheWrite1hPrice: 800));

        $this->model('anthropic/claude-sonnet-5.5', fn ($m) => $m
            ->displayName('Claude Sonnet 5.5')
            ->aliases('anthropic/claude-sonnet-5.5-20260928')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, cacheWrite5mPrice: 250, cacheWrite1hPrice: 400));

        $this->model('anthropic/claude-haiku-5.5', fn ($m) => $m
            ->displayName('Claude Haiku 5.5')
            ->aliases('anthropic/claude-haiku-5.5-20261007')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 50, cachedInputPrice: 1, cacheWrite5mPrice: 12.5, cacheWrite1hPrice: 20)
            ->tier('standard', inputPrice: 50, outputPrice: 250, cachedInputPrice: 5, cacheWrite5mPrice: 62.5, cacheWrite1hPrice: 100, aboveInputTokens: 100_000));

        $this->model('anthropic/claude-sonnet-4.6', fn ($m) => $m
            ->displayName('Claude Sonnet 4.6')
            ->aliases('anthropic/claude-4.6-sonnet-20260217')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600));

        $this->model('anthropic/claude-sonnet-4.5', fn ($m) => $m
            ->displayName('Claude Sonnet 4.5')
            ->aliases('anthropic/claude-4.5-sonnet-20250929')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('standard', inputPrice: 600, outputPrice: 2250, cachedInputPrice: 60, cacheWrite5mPrice: 750, cacheWrite1hPrice: 1200, aboveInputTokens: 200_000));

        // OpenAI
        $this->model('openai/gpt-6.1-sol', fn ($m) => $m
            ->displayName('GPT-6.1 Sol')
            ->aliases('openai/gpt-6.1-sol-20260929')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, cacheWrite5mPrice: 250)
            ->tier('standard', inputPrice: 400, outputPrice: 1500, cachedInputPrice: 20, cacheWrite5mPrice: 500, aboveInputTokens: 272_000));

        $this->model('openai/gpt-6-luna', fn ($m) => $m
            ->displayName('GPT-6 Luna')
            ->aliases('openai/gpt-6-luna-20260922')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 50, cachedInputPrice: 1, cacheWrite5mPrice: 12.5)
            ->tier('standard', inputPrice: 20, outputPrice: 75, cachedInputPrice: 2, cacheWrite5mPrice: 25, aboveInputTokens: 272_000));

        $this->model('openai/gpt-5.5', fn ($m) => $m
            ->displayName('GPT-5.5')
            ->aliases('openai/gpt-5.5-20260423')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 3000, cachedInputPrice: 50)
            ->tier('standard', inputPrice: 1000, outputPrice: 4500, cachedInputPrice: 100, aboveInputTokens: 272_000));

        $this->model('openai/gpt-5', fn ($m) => $m
            ->displayName('GPT-5')
            ->aliases('openai/gpt-5-2025-08-07')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5));

        $this->model('openai/gpt-5-mini', fn ($m) => $m
            ->displayName('GPT-5 Mini')
            ->aliases('openai/gpt-5-mini-2025-08-07')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 200, cachedInputPrice: 2.5));

        // No longer listed by OpenRouter (no retirement date published).
        $this->model('openai/gpt-5-chat', fn ($m) => $m
            ->displayName('GPT-5 Chat')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000));

        // Google. Gemini cache writes are left out: OpenRouter derives them
        // from hourly cache storage, and `usage.cost` already covers them.
        $this->model('google/gemini-3.1-pro-preview', fn ($m) => $m
            ->displayName('Gemini 3.1 Pro Preview')
            ->aliases('google/gemini-3.1-pro-preview-20260219')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1200, cachedInputPrice: 20, cacheWrite5mPrice: 37.5, audioInputPrice: 200)
            ->tier('standard', inputPrice: 400, outputPrice: 1800, cachedInputPrice: 40, cacheWrite5mPrice: 37.5, audioInputPrice: 400, aboveInputTokens: 200_000));

        $this->model('google/gemini-3.8-flash', fn ($m) => $m
            ->displayName('Gemini 3.8 Flash')
            ->aliases('google/gemini-3.8-flash-20260902')
            ->canGenerateText()
            ->tier('standard', inputPrice: 75, outputPrice: 375, cachedInputPrice: 7.5, cacheWrite5mPrice: 4.1667, audioInputPrice: 75));

        // Gemini 2.5 Flash and Flash Lite expire on OpenRouter on 2026-10-20.
        $this->model('google/gemini-2.5-flash', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 250, cachedInputPrice: 3, cacheWrite5mPrice: 8.3333, audioInputPrice: 100));

        $this->model('google/gemini-2.5-flash-lite', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Lite')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 1, cacheWrite5mPrice: 8.3333, audioInputPrice: 30));

        // Others
        $this->model('x-ai/grok-4.7', fn ($m) => $m
            ->displayName('Grok 4.7')
            ->aliases('x-ai/grok-4.7-20260916')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 600, cachedInputPrice: 50)
            ->tier('standard', inputPrice: 400, outputPrice: 1200, cachedInputPrice: 100, aboveInputTokens: 200_000));

        $this->model('deepseek/deepseek-v4-flash', fn ($m) => $m
            ->displayName('DeepSeek V4 Flash')
            ->aliases('deepseek/deepseek-v4-flash-20260423')
            ->canGenerateText()
            ->tier('standard', inputPrice: 9.1, outputPrice: 18.2, cachedInputPrice: 1.82));

        $this->model('deepseek/deepseek-v3.2-exp', fn ($m) => $m
            ->displayName('DeepSeek V3.2 Exp')
            ->canGenerateText()
            ->tier('standard', inputPrice: 27, outputPrice: 41));

        $this->model('moonshotai/kimi-k2.5', fn ($m) => $m
            ->displayName('Kimi K2.5')
            ->aliases('moonshotai/kimi-k2.5-0127')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 250, cachedInputPrice: 15));

        $this->model('qwen/qwen3-coder', fn ($m) => $m
            ->displayName('Qwen 3 Coder')
            ->aliases('qwen/qwen3-coder-480b-a35b-07-25')
            ->canGenerateText()
            ->tier('standard', inputPrice: 30, outputPrice: 100, cachedInputPrice: 10));

        // No longer listed by OpenRouter (no retirement date published).
        $this->model('mistralai/devstral-small-2507', fn ($m) => $m
            ->displayName('Devstral Small 2507')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 30));

        // Images. Token-priced image models bill image output tokens
        // (`completion_tokens_details.image_tokens`) at the image rate.
        $this->model('google/gemini-2.5-flash-image', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image')
            ->type('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', inputPrice: 30, outputPrice: 250, cachedInputPrice: 3, cacheWrite5mPrice: 8.3333, audioInputPrice: 100, imageOutputPrice: 3000));

        $this->model('openai/gpt-image-1', fn ($m) => $m
            ->displayName('GPT Image 1')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 500, outputPrice: 4000, imageInputPrice: 1000, imageOutputPrice: 4000));

        // The preview ids and FLUX Kontext Max are no longer listed by
        // OpenRouter (no retirement date published); the preview was replaced
        // by google/gemini-2.5-flash-image.
        $this->model('google/gemini-2.5-flash-image-preview', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image Preview')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 3));

        $this->model('google/gemini-2.5-flash-image-preview:free', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image Preview Free')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 0));

        $this->model('google/gemini-2.5-flash-image-preview:thinking', fn ($m) => $m
            ->displayName('Gemini 2.5 Flash Image Preview Thinking')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateText()
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        $this->model('black-forest-labs/flux-kontext-max', fn ($m) => $m
            ->displayName('Flux Kontext Max')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4));

        // Video and audio: no longer listed by OpenRouter (no retirement date
        // published). Video jobs now report their own cost in `usage.cost`.
        $this->model('google/veo-3', fn ($m) => $m
            ->displayName('Veo 3')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 75));

        $this->model('minimax/video-01', fn ($m) => $m
            ->displayName('MiniMax Video 01')
            ->type('video')
            ->pricingUnit('video')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 50));

        $this->model('bytedance/seedance-1-lite', fn ($m) => $m
            ->displayName('Seedance 1 Lite')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 10));

        $this->model('minimax/speech-02-hd', fn ($m) => $m
            ->displayName('MiniMax Speech 02 HD')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 800));

        $this->model('minimax/speech-02-turbo', fn ($m) => $m
            ->displayName('MiniMax Speech 02 Turbo')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 400));
    }
}
