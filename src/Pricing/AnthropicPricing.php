<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * Claude API prices, in US cents per 1M tokens.
 *
 * Source: https://platform.claude.com/docs/en/about-claude/pricing
 *
 * Cache writes bill 1.25x (5 minutes) or 2x (1 hour) the input price and
 * cache reads 0.1x (0.05x on Opus 5.5 / Sonnet 5.5, 0.025x on Fable 5.1 /
 * Mythos 5.1). The batch and fast-mode discounts or premiums apply to those
 * rates too, so each tier lists all five prices.
 *
 * Not modelled here: Priority Tier (contract prices, it bills like standard
 * below), the 1.1x US-only inference surcharge (applied per request from
 * `usage.inference_geo`, see Anthropic::extractCostMultiplier()) and code
 * execution container-hours (not visible in a response).
 */
class AnthropicPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'anthropic';
    }

    public function sources(): array
    {
        return ['https://platform.claude.com/docs/en/about-claude/pricing'];
    }

    /**
     * Web search is $10 per 1,000 searches. Web fetch is free, so it has no
     * entry. Code execution is billed per container-hour beyond a free
     * monthly allowance, which can't be derived from a single response.
     *
     * @return array<string, float>
     */
    public function toolCallPricing(): array
    {
        return [
            'web_search_requests' => 1.0,
        ];
    }

    protected function define(): void
    {
        $this->model('claude-fable-5-1', fn ($m) => $m
            ->displayName('Claude Fable 5.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 25, cacheWrite5mPrice: 1250, cacheWrite1hPrice: 2000)
            ->tier('batch', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 12.5, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000));

        $this->model('claude-mythos-5-1', fn ($m) => $m
            ->displayName('Claude Mythos 5.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 25, cacheWrite5mPrice: 1250, cacheWrite1hPrice: 2000)
            ->tier('batch', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 12.5, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000));

        $this->model('claude-fable-5', fn ($m) => $m
            ->displayName('Claude Fable 5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 100, cacheWrite5mPrice: 1250, cacheWrite1hPrice: 2000)
            ->tier('batch', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000));

        $this->model('claude-mythos-5', fn ($m) => $m
            ->displayName('Claude Mythos 5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 100, cacheWrite5mPrice: 1250, cacheWrite1hPrice: 2000)
            ->tier('batch', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000));

        // Fast mode (`speed: "fast"`) is a separate price list; caching
        // multipliers apply on top of it. It is not offered with batch.
        $this->model('claude-opus-5-5', fn ($m) => $m
            ->displayName('Claude Opus 5.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 400, outputPrice: 2000, cachedInputPrice: 20, cacheWrite5mPrice: 500, cacheWrite1hPrice: 800)
            ->tier('batch', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, cacheWrite5mPrice: 250, cacheWrite1hPrice: 400)
            ->tier('fast', inputPrice: 800, outputPrice: 4000, cachedInputPrice: 40, cacheWrite5mPrice: 1000, cacheWrite1hPrice: 1600));

        $this->model('claude-opus-5', fn ($m) => $m
            ->displayName('Claude Opus 5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500)
            ->tier('fast', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 100, cacheWrite5mPrice: 1250, cacheWrite1hPrice: 2000));

        $this->model('claude-opus-4-8', fn ($m) => $m
            ->displayName('Claude Opus 4.8')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500)
            ->tier('fast', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 100, cacheWrite5mPrice: 1250, cacheWrite1hPrice: 2000));

        $this->model('claude-opus-4-7', fn ($m) => $m
            ->displayName('Claude Opus 4.7')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500));

        $this->model('claude-opus-4-6', fn ($m) => $m
            ->displayName('Claude Opus 4.6')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500));

        // Models before the 4.6 generation have dated snapshot ids; the
        // undated id is the API's alias for the latest snapshot.
        $this->model('claude-opus-4-5', fn ($m) => $m
            ->displayName('Claude Opus 4.5')
            ->aliases('claude-opus-4-5-20251101')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625, cacheWrite1hPrice: 1000)
            ->tier('batch', inputPrice: 250, outputPrice: 1250, cachedInputPrice: 25, cacheWrite5mPrice: 312.5, cacheWrite1hPrice: 500));

        $this->model('claude-opus-4-1', fn ($m) => $m
            ->displayName('Claude Opus 4.1')
            ->aliases('claude-opus-4-1-20250805')
            ->retiredOn('2026-08-05')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 7500, cachedInputPrice: 150, cacheWrite5mPrice: 1875, cacheWrite1hPrice: 3000)
            ->tier('batch', inputPrice: 750, outputPrice: 3750, cachedInputPrice: 75, cacheWrite5mPrice: 937.5, cacheWrite1hPrice: 1500));

        $this->model('claude-opus-4', fn ($m) => $m
            ->displayName('Claude Opus 4')
            ->aliases('claude-opus-4-20250514', 'claude-opus-4-0')
            ->retiredOn('2026-06-15')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 7500, cachedInputPrice: 150, cacheWrite5mPrice: 1875, cacheWrite1hPrice: 3000)
            ->tier('batch', inputPrice: 750, outputPrice: 3750, cachedInputPrice: 75, cacheWrite5mPrice: 937.5, cacheWrite1hPrice: 1500));

        $this->model('claude-sonnet-5-5', fn ($m) => $m
            ->displayName('Claude Sonnet 5.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, cacheWrite5mPrice: 250, cacheWrite1hPrice: 400)
            ->tier('batch', inputPrice: 100, outputPrice: 500, cachedInputPrice: 5, cacheWrite5mPrice: 125, cacheWrite1hPrice: 200));

        // $2 / $10 was launch pricing and is now the standard price.
        $this->model('claude-sonnet-5', fn ($m) => $m
            ->displayName('Claude Sonnet 5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 20, cacheWrite5mPrice: 250, cacheWrite1hPrice: 400)
            ->tier('batch', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10, cacheWrite5mPrice: 125, cacheWrite1hPrice: 200));

        $this->model('claude-sonnet-4-6', fn ($m) => $m
            ->displayName('Claude Sonnet 4.6')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        // Deprecated; shuts down on 2026-11-30.
        $this->model('claude-sonnet-4-5', fn ($m) => $m
            ->displayName('Claude Sonnet 4.5')
            ->aliases('claude-sonnet-4-5-20250929')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        $this->model('claude-sonnet-4', fn ($m) => $m
            ->displayName('Claude Sonnet 4')
            ->aliases('claude-sonnet-4-20250514', 'claude-sonnet-4-0')
            ->retiredOn('2026-06-15')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        $this->model('claude-3-7-sonnet', fn ($m) => $m
            ->displayName('Claude 3.7 Sonnet')
            ->aliases('claude-3-7-sonnet-20250219', 'claude-3-7-sonnet-latest')
            ->retiredOn('2026-02-19')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1500, cachedInputPrice: 30, cacheWrite5mPrice: 375, cacheWrite1hPrice: 600)
            ->tier('batch', inputPrice: 150, outputPrice: 750, cachedInputPrice: 15, cacheWrite5mPrice: 187.5, cacheWrite1hPrice: 300));

        // Priced by prompt length: a prompt over 100,000 tokens (cache reads
        // and writes included) pays the higher row for the whole request.
        $this->model('claude-haiku-5-5', fn ($m) => $m
            ->displayName('Claude Haiku 5.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 50, cachedInputPrice: 1, cacheWrite5mPrice: 12.5, cacheWrite1hPrice: 20)
            ->tier('standard', inputPrice: 50, outputPrice: 250, cachedInputPrice: 5, cacheWrite5mPrice: 62.5, cacheWrite1hPrice: 100, aboveInputTokens: 100_000)
            ->tier('batch', inputPrice: 5, outputPrice: 25, cachedInputPrice: 0.5, cacheWrite5mPrice: 6.25, cacheWrite1hPrice: 10)
            ->tier('batch', inputPrice: 25, outputPrice: 125, cachedInputPrice: 2.5, cacheWrite5mPrice: 31.25, cacheWrite1hPrice: 50, aboveInputTokens: 100_000));

        $this->model('claude-haiku-4-5', fn ($m) => $m
            ->displayName('Claude Haiku 4.5')
            ->aliases('claude-haiku-4-5-20251001')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10, cacheWrite5mPrice: 125, cacheWrite1hPrice: 200)
            ->tier('batch', inputPrice: 50, outputPrice: 250, cachedInputPrice: 5, cacheWrite5mPrice: 62.5, cacheWrite1hPrice: 100));

        $this->model('claude-3-5-haiku', fn ($m) => $m
            ->displayName('Claude 3.5 Haiku')
            ->aliases('claude-3-5-haiku-20241022', 'claude-3-5-haiku-latest')
            ->retiredOn('2026-02-19')
            ->canGenerateText()
            ->tier('standard', inputPrice: 80, outputPrice: 400, cachedInputPrice: 8, cacheWrite5mPrice: 100, cacheWrite1hPrice: 160)
            ->tier('batch', inputPrice: 40, outputPrice: 200, cachedInputPrice: 4, cacheWrite5mPrice: 50, cacheWrite1hPrice: 80));

        $this->model('claude-3-haiku', fn ($m) => $m
            ->displayName('Claude 3 Haiku')
            ->aliases('claude-3-haiku-20240307')
            ->retiredOn('2026-04-20')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 125, cachedInputPrice: 3, cacheWrite5mPrice: 30, cacheWrite1hPrice: 50)
            ->tier('batch', inputPrice: 12.5, outputPrice: 62.5, cachedInputPrice: 1.5, cacheWrite5mPrice: 15, cacheWrite1hPrice: 25));
    }
}
