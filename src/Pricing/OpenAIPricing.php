<?php

declare(strict_types=1);

namespace Spectra\Pricing;

/**
 * OpenAI price catalog, in US cents per 1M tokens (or per unit).
 *
 * Prices checked against https://developers.openai.com/api/docs/pricing and
 * the model pages on 2026-10-15.
 *
 * - `priority` holds Fast mode prices (OpenAI renamed Priority processing to
 *   Fast mode on 2026-07-30); `cacheWrite5mPrice` holds OpenAI's single
 *   cache-write rate (GPT-5.6 and later, 1.25x input).
 * - Long context: prompts over 272K input tokens are billed at 2x input and
 *   1.5x output for the whole request (`aboveInputTokens: 272_000`). For the
 *   GPT-6 and GPT-5.6 families the cache rates double too ("2x input and
 *   cache rates"). For GPT-5.5 and GPT-5.4 OpenAI only states "2x input and
 *   1.5x output", so their cached rate stays the short-context rate.
 * - Regional (data residency) endpoints add 10% for models released on or
 *   after 2026-03-05. That uplift is not applied here.
 * - Retired models stay in the catalog so stored requests keep their prices.
 */
class OpenAIPricing extends ProviderPricing
{
    public function provider(): string
    {
        return 'openai';
    }

    protected function define(): void
    {
        $this->defineFlagshipModels();
        $this->defineGpt5Models();
        $this->defineSpecializedModels();
        $this->defineLegacyTextModels();
        $this->defineAudioModels();
        $this->defineImageModels();
        $this->defineOtherModels();
    }

    /** GPT-6 and GPT-5.6. */
    protected function defineFlagshipModels(): void
    {
        $this->model('gpt-6-astra', fn ($m) => $m
            ->displayName('GPT-6 Astra')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 5000, cachedInputPrice: 100, cacheWrite5mPrice: 1250)
            ->tier('standard', inputPrice: 2000, outputPrice: 7500, cachedInputPrice: 200, cacheWrite5mPrice: 2500, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625)
            ->tier('batch', inputPrice: 1000, outputPrice: 3750, cachedInputPrice: 100, cacheWrite5mPrice: 1250, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, cacheWrite5mPrice: 625)
            ->tier('flex', inputPrice: 1000, outputPrice: 3750, cachedInputPrice: 100, cacheWrite5mPrice: 1250, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 2000, outputPrice: 10000, cachedInputPrice: 200, cacheWrite5mPrice: 2500)
            ->tier('priority', inputPrice: 4000, outputPrice: 15000, cachedInputPrice: 400, cacheWrite5mPrice: 5000, aboveInputTokens: 272_000)
            ->tier('ultrafast', inputPrice: 6000, outputPrice: 30000, cachedInputPrice: 600, cacheWrite5mPrice: 7500)
            ->tier('ultrafast', inputPrice: 12000, outputPrice: 45000, cachedInputPrice: 1200, cacheWrite5mPrice: 15000, aboveInputTokens: 272_000));

        $this->model('gpt-6.1-sol', fn ($m) => $m
            ->displayName('GPT-6.1 Sol')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, cacheWrite5mPrice: 250)
            ->tier('standard', inputPrice: 400, outputPrice: 1500, cachedInputPrice: 20, cacheWrite5mPrice: 500, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 100, outputPrice: 500, cachedInputPrice: 5, cacheWrite5mPrice: 125)
            ->tier('batch', inputPrice: 200, outputPrice: 750, cachedInputPrice: 10, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 100, outputPrice: 500, cachedInputPrice: 5, cacheWrite5mPrice: 125)
            ->tier('flex', inputPrice: 200, outputPrice: 750, cachedInputPrice: 10, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 400, outputPrice: 2000, cachedInputPrice: 20, cacheWrite5mPrice: 500)
            ->tier('priority', inputPrice: 800, outputPrice: 3000, cachedInputPrice: 40, cacheWrite5mPrice: 1000, aboveInputTokens: 272_000)
            ->tier('ultrafast', inputPrice: 1200, outputPrice: 6000, cachedInputPrice: 60, cacheWrite5mPrice: 1500)
            ->tier('ultrafast', inputPrice: 2400, outputPrice: 9000, cachedInputPrice: 120, cacheWrite5mPrice: 3000, aboveInputTokens: 272_000));

        $this->model('gpt-6.1-terra', fn ($m) => $m
            ->displayName('GPT-6.1 Terra')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 500, cachedInputPrice: 5, cacheWrite5mPrice: 125)
            ->tier('standard', inputPrice: 200, outputPrice: 750, cachedInputPrice: 10, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 50, outputPrice: 250, cachedInputPrice: 2.5, cacheWrite5mPrice: 62.5)
            ->tier('batch', inputPrice: 100, outputPrice: 375, cachedInputPrice: 5, cacheWrite5mPrice: 125, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 50, outputPrice: 250, cachedInputPrice: 2.5, cacheWrite5mPrice: 62.5)
            ->tier('flex', inputPrice: 100, outputPrice: 375, cachedInputPrice: 5, cacheWrite5mPrice: 125, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 10, cacheWrite5mPrice: 250)
            ->tier('priority', inputPrice: 400, outputPrice: 1500, cachedInputPrice: 20, cacheWrite5mPrice: 500, aboveInputTokens: 272_000));

        $this->model('gpt-6-sol', fn ($m) => $m
            ->displayName('GPT-6 Sol')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 20, cacheWrite5mPrice: 250)
            ->tier('standard', inputPrice: 400, outputPrice: 1500, cachedInputPrice: 40, cacheWrite5mPrice: 500, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10, cacheWrite5mPrice: 125)
            ->tier('batch', inputPrice: 200, outputPrice: 750, cachedInputPrice: 20, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 100, outputPrice: 500, cachedInputPrice: 10, cacheWrite5mPrice: 125)
            ->tier('flex', inputPrice: 200, outputPrice: 750, cachedInputPrice: 20, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 400, outputPrice: 2000, cachedInputPrice: 40, cacheWrite5mPrice: 500)
            ->tier('priority', inputPrice: 800, outputPrice: 3000, cachedInputPrice: 80, cacheWrite5mPrice: 1000, aboveInputTokens: 272_000));

        $this->model('gpt-6-luna', fn ($m) => $m
            ->displayName('GPT-6 Luna')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 50, cachedInputPrice: 1, cacheWrite5mPrice: 12.5)
            ->tier('standard', inputPrice: 20, outputPrice: 75, cachedInputPrice: 2, cacheWrite5mPrice: 25, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 5, outputPrice: 25, cachedInputPrice: 0.5, cacheWrite5mPrice: 6.25)
            ->tier('batch', inputPrice: 10, outputPrice: 37.5, cachedInputPrice: 1, cacheWrite5mPrice: 12.5, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 5, outputPrice: 25, cachedInputPrice: 0.5, cacheWrite5mPrice: 6.25)
            ->tier('flex', inputPrice: 10, outputPrice: 37.5, cachedInputPrice: 1, cacheWrite5mPrice: 12.5, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 20, outputPrice: 100, cachedInputPrice: 2, cacheWrite5mPrice: 25)
            ->tier('priority', inputPrice: 40, outputPrice: 150, cachedInputPrice: 4, cacheWrite5mPrice: 50, aboveInputTokens: 272_000));

        // Promotional price, "available at least through November 21, 2026".
        // The price after the promotion has not been published yet.
        $this->model('gpt-5.6-sol', fn ($m) => $m
            ->displayName('GPT-5.6 Sol')
            ->canGenerateText()
            ->tier('standard', inputPrice: 400, outputPrice: 2000, cachedInputPrice: 40, cacheWrite5mPrice: 500)
            ->tier('standard', inputPrice: 800, outputPrice: 3000, cachedInputPrice: 80, cacheWrite5mPrice: 1000, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 20, cacheWrite5mPrice: 250)
            ->tier('batch', inputPrice: 400, outputPrice: 1500, cachedInputPrice: 40, cacheWrite5mPrice: 500, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 200, outputPrice: 1000, cachedInputPrice: 20, cacheWrite5mPrice: 250)
            ->tier('flex', inputPrice: 400, outputPrice: 1500, cachedInputPrice: 40, cacheWrite5mPrice: 500, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 800, outputPrice: 4000, cachedInputPrice: 80, cacheWrite5mPrice: 1000)
            ->tier('priority', inputPrice: 1600, outputPrice: 6000, cachedInputPrice: 160, cacheWrite5mPrice: 2000, aboveInputTokens: 272_000));

        $this->model('gpt-5.6-terra', fn ($m) => $m
            ->displayName('GPT-5.6 Terra')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 1200, cachedInputPrice: 20, cacheWrite5mPrice: 250)
            ->tier('standard', inputPrice: 400, outputPrice: 1800, cachedInputPrice: 40, cacheWrite5mPrice: 500, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 100, outputPrice: 600, cachedInputPrice: 10, cacheWrite5mPrice: 125)
            ->tier('batch', inputPrice: 200, outputPrice: 900, cachedInputPrice: 20, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 100, outputPrice: 600, cachedInputPrice: 10, cacheWrite5mPrice: 125)
            ->tier('flex', inputPrice: 200, outputPrice: 900, cachedInputPrice: 20, cacheWrite5mPrice: 250, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 400, outputPrice: 2400, cachedInputPrice: 40, cacheWrite5mPrice: 500)
            ->tier('priority', inputPrice: 800, outputPrice: 3600, cachedInputPrice: 80, cacheWrite5mPrice: 1000, aboveInputTokens: 272_000));

        $this->model('gpt-5.6-luna', fn ($m) => $m
            ->displayName('GPT-5.6 Luna')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 120, cachedInputPrice: 2, cacheWrite5mPrice: 25)
            ->tier('standard', inputPrice: 40, outputPrice: 180, cachedInputPrice: 4, cacheWrite5mPrice: 50, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 10, outputPrice: 60, cachedInputPrice: 1, cacheWrite5mPrice: 12.5)
            ->tier('batch', inputPrice: 20, outputPrice: 90, cachedInputPrice: 2, cacheWrite5mPrice: 25, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 10, outputPrice: 60, cachedInputPrice: 1, cacheWrite5mPrice: 12.5)
            ->tier('flex', inputPrice: 20, outputPrice: 90, cachedInputPrice: 2, cacheWrite5mPrice: 25, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 40, outputPrice: 240, cachedInputPrice: 4, cacheWrite5mPrice: 50)
            ->tier('priority', inputPrice: 80, outputPrice: 360, cachedInputPrice: 8, cacheWrite5mPrice: 100, aboveInputTokens: 272_000));
    }

    /** GPT-5 to GPT-5.5. */
    protected function defineGpt5Models(): void
    {
        // Long context applies to standard, batch and flex, not to Fast mode.
        // OpenAI states 2x input and 1.5x output; the cached rate is not restated.
        $this->model('gpt-5.5', fn ($m) => $m
            ->displayName('GPT-5.5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 3000, cachedInputPrice: 50)
            ->tier('standard', inputPrice: 1000, outputPrice: 4500, cachedInputPrice: 100, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 250, outputPrice: 1500, cachedInputPrice: 25)
            ->tier('batch', inputPrice: 500, outputPrice: 2250, cachedInputPrice: 50, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 250, outputPrice: 1500, cachedInputPrice: 25)
            ->tier('flex', inputPrice: 500, outputPrice: 2250, cachedInputPrice: 50, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 1250, outputPrice: 7500, cachedInputPrice: 125));

        $this->model('gpt-5.5-pro', fn ($m) => $m
            ->displayName('GPT-5.5 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 3000, outputPrice: 18000)
            ->tier('standard', inputPrice: 6000, outputPrice: 27000, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 1500, outputPrice: 9000)
            ->tier('flex', inputPrice: 1500, outputPrice: 9000));

        $this->model('gpt-5.4', fn ($m) => $m
            ->displayName('GPT-5.4')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1500, cachedInputPrice: 25)
            ->tier('standard', inputPrice: 500, outputPrice: 2250, cachedInputPrice: 50, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 125, outputPrice: 750, cachedInputPrice: 13)
            ->tier('batch', inputPrice: 250, outputPrice: 1125, cachedInputPrice: 25, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 125, outputPrice: 750, cachedInputPrice: 13)
            ->tier('flex', inputPrice: 250, outputPrice: 1125, cachedInputPrice: 25, aboveInputTokens: 272_000)
            ->tier('priority', inputPrice: 500, outputPrice: 3000, cachedInputPrice: 50));

        $this->model('gpt-5.4-mini', fn ($m) => $m
            ->displayName('GPT-5.4 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 75, outputPrice: 450, cachedInputPrice: 7.5)
            ->tier('batch', inputPrice: 37.5, outputPrice: 225, cachedInputPrice: 3.75)
            ->tier('flex', inputPrice: 37.5, outputPrice: 225, cachedInputPrice: 3.75)
            ->tier('priority', inputPrice: 150, outputPrice: 900, cachedInputPrice: 15));

        $this->model('gpt-5.4-nano', fn ($m) => $m
            ->displayName('GPT-5.4 Nano')
            ->canGenerateText()
            ->tier('standard', inputPrice: 20, outputPrice: 125, cachedInputPrice: 2)
            ->tier('batch', inputPrice: 10, outputPrice: 62.5, cachedInputPrice: 1)
            ->tier('flex', inputPrice: 10, outputPrice: 62.5, cachedInputPrice: 1));

        $this->model('gpt-5.4-pro', fn ($m) => $m
            ->displayName('GPT-5.4 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 3000, outputPrice: 18000)
            ->tier('standard', inputPrice: 6000, outputPrice: 27000, aboveInputTokens: 272_000)
            ->tier('batch', inputPrice: 1500, outputPrice: 9000)
            ->tier('batch', inputPrice: 3000, outputPrice: 13500, aboveInputTokens: 272_000)
            ->tier('flex', inputPrice: 1500, outputPrice: 9000)
            ->tier('flex', inputPrice: 3000, outputPrice: 13500, aboveInputTokens: 272_000));

        $this->model('gpt-5.2', fn ($m) => $m
            ->displayName('GPT-5.2')
            ->canGenerateText()
            ->tier('standard', inputPrice: 175, outputPrice: 1400, cachedInputPrice: 17.5)
            ->tier('batch', inputPrice: 87.5, outputPrice: 700, cachedInputPrice: 8.75)
            ->tier('flex', inputPrice: 87.5, outputPrice: 700, cachedInputPrice: 8.75)
            ->tier('priority', inputPrice: 350, outputPrice: 2800, cachedInputPrice: 35));

        $this->model('gpt-5.1', fn ($m) => $m
            ->displayName('GPT-5.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('batch', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('flex', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5', fn ($m) => $m
            ->displayName('GPT-5')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('batch', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('flex', inputPrice: 62.5, outputPrice: 500, cachedInputPrice: 6.25)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5-mini', fn ($m) => $m
            ->displayName('GPT-5 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 200, cachedInputPrice: 2.5)
            ->tier('batch', inputPrice: 12.5, outputPrice: 100, cachedInputPrice: 1.25)
            ->tier('flex', inputPrice: 12.5, outputPrice: 100, cachedInputPrice: 1.25)
            ->tier('priority', inputPrice: 45, outputPrice: 360, cachedInputPrice: 4.5));

        $this->model('gpt-5-nano', fn ($m) => $m
            ->displayName('GPT-5 Nano')
            ->canGenerateText()
            ->tier('standard', inputPrice: 5, outputPrice: 40, cachedInputPrice: 0.5)
            ->tier('batch', inputPrice: 2.5, outputPrice: 20, cachedInputPrice: 0.25)
            ->tier('flex', inputPrice: 2.5, outputPrice: 20, cachedInputPrice: 0.25));

        $this->model('gpt-5.2-pro', fn ($m) => $m
            ->displayName('GPT-5.2 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 2100, outputPrice: 16800)
            ->tier('batch', inputPrice: 1050, outputPrice: 8400));

        $this->model('gpt-5-pro', fn ($m) => $m
            ->displayName('GPT-5 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 12000)
            ->tier('batch', inputPrice: 750, outputPrice: 6000));

        $this->model('gpt-5-search-api', fn ($m) => $m
            ->displayName('GPT-5 Search API')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5));
    }

    /** Codex, ChatGPT, cyber and life-sciences models. */
    protected function defineSpecializedModels(): void
    {
        $this->model('chat-latest', fn ($m) => $m
            ->displayName('ChatGPT Latest')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 3000, cachedInputPrice: 50));

        $this->model('gpt-5.3-codex', fn ($m) => $m
            ->displayName('GPT-5.3 Codex')
            ->canGenerateText()
            ->tier('standard', inputPrice: 175, outputPrice: 1400, cachedInputPrice: 17.5)
            ->tier('priority', inputPrice: 350, outputPrice: 2800, cachedInputPrice: 35));

        $this->model('gpt-5.2-codex', fn ($m) => $m
            ->displayName('GPT-5.2 Codex')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 175, outputPrice: 1400, cachedInputPrice: 17.5)
            ->tier('priority', inputPrice: 350, outputPrice: 2800, cachedInputPrice: 35));

        $this->model('gpt-5.1-codex-max', fn ($m) => $m
            ->displayName('GPT-5.1 Codex Max')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5.1-codex', fn ($m) => $m
            ->displayName('GPT-5.1 Codex')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5-codex', fn ($m) => $m
            ->displayName('GPT-5 Codex')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 1000, cachedInputPrice: 12.5)
            ->tier('priority', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 25));

        $this->model('gpt-5.1-codex-mini', fn ($m) => $m
            ->displayName('GPT-5.1 Codex Mini')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 25, outputPrice: 200, cachedInputPrice: 2.5));

        // OpenAI lists no long-context price for the cyber models.
        $this->model('gpt-5.6-cyber', fn ($m) => $m
            ->displayName('GPT-5.6 Cyber')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1250, outputPrice: 7500, cachedInputPrice: 125, cacheWrite5mPrice: 1562.5));

        $this->model('gpt-5.5-cyber', fn ($m) => $m
            ->displayName('GPT-5.5 Cyber')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1250, outputPrice: 7500, cachedInputPrice: 125));

        // Billing for gpt-rosalind-research began on 2026-10-05; it was free before.
        $this->model('gpt-rosalind-research', fn ($m) => $m
            ->displayName('GPT-Rosalind Research')
            ->canGenerateText()
            ->tier('standard', inputPrice: 0, outputPrice: 0)
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50, since: '2026-10-05'));

        $this->model('gpt-rosalind-discovery', fn ($m) => $m
            ->displayName('GPT-Rosalind Discovery')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 2500, cachedInputPrice: 50));

        $this->model('o3-deep-research', fn ($m) => $m
            ->displayName('o3 Deep Research')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 4000, cachedInputPrice: 250)
            ->tier('batch', inputPrice: 500, outputPrice: 2000));

        $this->model('o4-mini-deep-research', fn ($m) => $m
            ->displayName('o4 Mini Deep Research')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50)
            ->tier('batch', inputPrice: 100, outputPrice: 400));

        $this->model('computer-use-preview', fn ($m) => $m
            ->displayName('Computer Use Preview')
            ->retiredOn('2026-07-23')
            ->canGenerateText()
            ->tier('standard', inputPrice: 300, outputPrice: 1200)
            ->tier('batch', inputPrice: 150, outputPrice: 600));
    }

    /** GPT-4.x, GPT-4o, o-series and older models. */
    protected function defineLegacyTextModels(): void
    {
        $this->model('gpt-4.1', fn ($m) => $m
            ->displayName('GPT-4.1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50)
            ->tier('batch', inputPrice: 100, outputPrice: 400)
            ->tier('priority', inputPrice: 350, outputPrice: 1400, cachedInputPrice: 87.5));

        $this->model('gpt-4.1-mini', fn ($m) => $m
            ->displayName('GPT-4.1 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 160, cachedInputPrice: 10)
            ->tier('batch', inputPrice: 20, outputPrice: 80)
            ->tier('priority', inputPrice: 70, outputPrice: 280, cachedInputPrice: 17.5));

        $this->model('gpt-4.1-nano', fn ($m) => $m
            ->displayName('GPT-4.1 Nano')
            ->canGenerateText()
            ->tier('standard', inputPrice: 10, outputPrice: 40, cachedInputPrice: 2.5)
            ->tier('batch', inputPrice: 5, outputPrice: 20)
            ->tier('priority', inputPrice: 20, outputPrice: 80, cachedInputPrice: 5));

        $this->model('gpt-4o', fn ($m) => $m
            ->displayName('GPT-4o')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000, cachedInputPrice: 125)
            ->tier('batch', inputPrice: 125, outputPrice: 500)
            ->tier('priority', inputPrice: 425, outputPrice: 1700, cachedInputPrice: 212.5));

        // Its own entry: the dated-snapshot fallback would otherwise price it as gpt-4o.
        $this->model('gpt-4o-2024-05-13', fn ($m) => $m
            ->displayName('GPT-4o (2024-05-13)')
            ->canGenerateText()
            ->tier('standard', inputPrice: 500, outputPrice: 1500)
            ->tier('batch', inputPrice: 250, outputPrice: 750)
            ->tier('priority', inputPrice: 875, outputPrice: 2625));

        $this->model('gpt-4o-mini', fn ($m) => $m
            ->displayName('GPT-4o Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15, outputPrice: 60, cachedInputPrice: 7.5)
            ->tier('batch', inputPrice: 7.5, outputPrice: 30)
            ->tier('priority', inputPrice: 25, outputPrice: 100, cachedInputPrice: 12.5));

        $this->model('o1', fn ($m) => $m
            ->displayName('o1')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1500, outputPrice: 6000, cachedInputPrice: 750)
            ->tier('batch', inputPrice: 750, outputPrice: 3000));

        $this->model('o1-pro', fn ($m) => $m
            ->displayName('o1 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 15000, outputPrice: 60000)
            ->tier('batch', inputPrice: 7500, outputPrice: 30000));

        $this->model('o3-pro', fn ($m) => $m
            ->displayName('o3 Pro')
            ->canGenerateText()
            ->tier('standard', inputPrice: 2000, outputPrice: 8000)
            ->tier('batch', inputPrice: 1000, outputPrice: 4000));

        $this->model('o3', fn ($m) => $m
            ->displayName('o3')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50)
            ->tier('batch', inputPrice: 100, outputPrice: 400)
            ->tier('flex', inputPrice: 100, outputPrice: 400, cachedInputPrice: 25)
            ->tier('priority', inputPrice: 350, outputPrice: 1400, cachedInputPrice: 87.5));

        $this->model('o4-mini', fn ($m) => $m
            ->displayName('o4 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 110, outputPrice: 440, cachedInputPrice: 27.5)
            ->tier('batch', inputPrice: 55, outputPrice: 220)
            ->tier('flex', inputPrice: 55, outputPrice: 220, cachedInputPrice: 13.8)
            ->tier('priority', inputPrice: 200, outputPrice: 800, cachedInputPrice: 50));

        $this->model('o3-mini', fn ($m) => $m
            ->displayName('o3 Mini')
            ->canGenerateText()
            ->tier('standard', inputPrice: 110, outputPrice: 440, cachedInputPrice: 55)
            ->tier('batch', inputPrice: 55, outputPrice: 220));

        $this->model('gpt-4-turbo', fn ($m) => $m
            ->displayName('GPT-4 Turbo')
            ->aliases('gpt-4-turbo-2024-04-09')
            ->canGenerateText()
            ->tier('standard', inputPrice: 1000, outputPrice: 3000)
            ->tier('batch', inputPrice: 500, outputPrice: 1500));

        $this->model('gpt-4', fn ($m) => $m
            ->displayName('GPT-4')
            ->aliases('gpt-4-0613')
            ->canGenerateText()
            ->tier('standard', inputPrice: 3000, outputPrice: 6000)
            ->tier('batch', inputPrice: 1500, outputPrice: 3000));

        $this->model('gpt-3.5-turbo', fn ($m) => $m
            ->displayName('GPT-3.5 Turbo')
            ->aliases('gpt-3.5-turbo-0125')
            ->canGenerateText()
            ->tier('standard', inputPrice: 50, outputPrice: 150)
            ->tier('batch', inputPrice: 25, outputPrice: 75));

        $this->model('gpt-3.5-turbo-1106', fn ($m) => $m
            ->displayName('GPT-3.5 Turbo 1106')
            ->retiredOn('2026-09-28')
            ->canGenerateText()
            ->tier('standard', inputPrice: 100, outputPrice: 200)
            ->tier('batch', inputPrice: 100, outputPrice: 200));

        $this->model('gpt-3.5-turbo-instruct', fn ($m) => $m
            ->displayName('GPT-3.5 Turbo Instruct')
            ->retiredOn('2026-09-28')
            ->canGenerateText()
            ->tier('standard', inputPrice: 150, outputPrice: 200));

        $this->model('davinci-002', fn ($m) => $m
            ->displayName('Davinci 002')
            ->retiredOn('2026-09-28')
            ->canGenerateText()
            ->tier('standard', inputPrice: 200, outputPrice: 200)
            ->tier('batch', inputPrice: 100, outputPrice: 100));

        $this->model('babbage-002', fn ($m) => $m
            ->displayName('Babbage 002')
            ->retiredOn('2026-09-28')
            ->canGenerateText()
            ->tier('standard', inputPrice: 40, outputPrice: 40)
            ->tier('batch', inputPrice: 20, outputPrice: 20));
    }

    /**
     * Realtime, audio, speech and transcription models.
     *
     * Audio tokens have their own rates. The catalog has one cached-input
     * rate per tier, so the mini realtime models bill cached audio ($0.30)
     * at their cached text rate ($0.06).
     */
    protected function defineAudioModels(): void
    {
        $this->model('gpt-realtime-2.1', fn ($m) => $m
            ->displayName('GPT Realtime 2.1')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 400, outputPrice: 2400, cachedInputPrice: 40, audioInputPrice: 3200, audioOutputPrice: 6400, imageInputPrice: 500));

        $this->model('gpt-realtime-2.1-mini', fn ($m) => $m
            ->displayName('GPT Realtime 2.1 Mini')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 60, outputPrice: 240, cachedInputPrice: 6, audioInputPrice: 1000, audioOutputPrice: 2000, imageInputPrice: 80));

        $this->model('gpt-realtime-2', fn ($m) => $m
            ->displayName('GPT Realtime 2')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 400, outputPrice: 2400, cachedInputPrice: 40, audioInputPrice: 3200, audioOutputPrice: 6400, imageInputPrice: 500));

        $this->model('gpt-realtime-1.5', fn ($m) => $m
            ->displayName('GPT Realtime 1.5')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 400, outputPrice: 1600, cachedInputPrice: 40, audioInputPrice: 3200, audioOutputPrice: 6400, imageInputPrice: 500));

        $this->model('gpt-realtime', fn ($m) => $m
            ->displayName('GPT Realtime')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 400, outputPrice: 1600, cachedInputPrice: 40, audioInputPrice: 3200, audioOutputPrice: 6400, imageInputPrice: 500));

        $this->model('gpt-realtime-mini', fn ($m) => $m
            ->displayName('GPT Realtime Mini')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 60, outputPrice: 240, cachedInputPrice: 6, audioInputPrice: 1000, audioOutputPrice: 2000, imageInputPrice: 80));

        $this->model('gpt-audio-1.5', fn ($m) => $m
            ->displayName('GPT Audio 1.5')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 250, outputPrice: 1000, audioInputPrice: 3200, audioOutputPrice: 6400));

        $this->model('gpt-audio', fn ($m) => $m
            ->displayName('GPT Audio')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 250, outputPrice: 1000, audioInputPrice: 3200, audioOutputPrice: 6400));

        $this->model('gpt-audio-mini', fn ($m) => $m
            ->displayName('GPT Audio Mini')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', inputPrice: 60, outputPrice: 240, audioInputPrice: 1000, audioOutputPrice: 2000));

        // Voice sessions, billed per second at a per-minute price.
        $this->model('gpt-live-1', fn ($m) => $m
            ->displayName('GPT-Live 1')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 5));

        // Text to speech. tts-1 / tts-1-hd are billed per input character.
        $this->model('tts-1', fn ($m) => $m
            ->displayName('TTS')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1500));

        $this->model('tts-1-hd', fn ($m) => $m
            ->displayName('TTS HD')
            ->type('audio')
            ->pricingUnit('characters')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 3000));

        // Text input and audio output tokens.
        // Billed per token ($0.60/MTok text in, $12/MTok audio out), but the
        // speech endpoint returns bare audio with no usage. It is priced per
        // minute of generated audio at OpenAI's own estimate (~$0.015/min at
        // $12/MTok); the duration is read from the audio when getID3 is installed.
        $this->model('gpt-4o-mini-tts', fn ($m) => $m
            ->displayName('GPT-4o Mini TTS')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateAudio()
            ->tier('standard', pricePerUnit: 1.5));

        // Transcription.
        $this->model('whisper-1', fn ($m) => $m
            ->displayName('Whisper')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.6));

        $this->model('gpt-transcribe', fn ($m) => $m
            ->displayName('GPT Transcribe')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 0.45));

        $this->model('gpt-4o-transcribe', fn ($m) => $m
            ->displayName('GPT-4o Transcribe')
            ->type('audio')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('gpt-4o-transcribe-diarize', fn ($m) => $m
            ->displayName('GPT-4o Transcribe Diarize')
            ->type('audio')
            ->canGenerateText()
            ->tier('standard', inputPrice: 250, outputPrice: 1000));

        $this->model('gpt-4o-mini-transcribe', fn ($m) => $m
            ->displayName('GPT-4o Mini Transcribe')
            ->type('audio')
            ->canGenerateText()
            ->tier('standard', inputPrice: 125, outputPrice: 500));

        // Live (Realtime API) transcription and translation, per minute.
        $this->model('gpt-live-transcribe', fn ($m) => $m
            ->displayName('GPT-Live Transcribe')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 1.7));

        $this->model('gpt-realtime-whisper', fn ($m) => $m
            ->displayName('GPT Realtime Whisper')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 1.7));

        $this->model('gpt-realtime-translate', fn ($m) => $m
            ->displayName('GPT Realtime Translate')
            ->type('audio')
            ->pricingUnit('minute')
            ->canGenerateText()
            ->tier('standard', pricePerUnit: 3.4));
    }

    /**
     * GPT Image (token-priced) and DALL-E (per-image) models.
     *
     * `inputPrice` / `outputPrice` are the text rates, `imageInputPrice` /
     * `imageOutputPrice` the image rates. Models without a text output rate
     * only output image tokens, so their `outputPrice` is the image rate.
     * The catalog has one cached rate, the cached text rate; cached image
     * input only applies to the Responses API image tool, whose usage is not
     * reported separately.
     */
    protected function defineImageModels(): void
    {
        foreach (['gpt-image-2.5-sunburst' => 'GPT Image 2.5 Sunburst', 'gpt-image-2.5-flare' => 'GPT Image 2.5 Flare', 'gpt-image-2' => 'GPT Image 2'] as $id => $name) {
            $this->model($id, fn ($m) => $m
                ->displayName($name)
                ->type('image')
                ->canGenerateImages()
                ->tier('standard', inputPrice: 500, outputPrice: 3000, cachedInputPrice: 125, imageInputPrice: 800, imageOutputPrice: 3000)
                ->tier('batch', inputPrice: 250, outputPrice: 1500, cachedInputPrice: 62.5, imageInputPrice: 400, imageOutputPrice: 1500));
        }

        // Text output (which includes reasoning) has its own rate on these two.
        $this->model('gpt-image-1.5', fn ($m) => $m
            ->displayName('GPT Image 1.5')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 125, imageInputPrice: 800, imageOutputPrice: 3200)
            ->tier('batch', inputPrice: 250, outputPrice: 500, cachedInputPrice: 63, imageInputPrice: 400, imageOutputPrice: 1600));

        $this->model('chatgpt-image-latest', fn ($m) => $m
            ->displayName('ChatGPT Image Latest')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 500, outputPrice: 1000, cachedInputPrice: 125, imageInputPrice: 800, imageOutputPrice: 3200)
            ->tier('batch', inputPrice: 250, outputPrice: 500, cachedInputPrice: 63, imageInputPrice: 400, imageOutputPrice: 1600));

        $this->model('gpt-image-1', fn ($m) => $m
            ->displayName('GPT Image 1')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 500, outputPrice: 4000, cachedInputPrice: 125, imageInputPrice: 1000, imageOutputPrice: 4000)
            ->tier('batch', inputPrice: 250, outputPrice: 2000, cachedInputPrice: 63, imageInputPrice: 500, imageOutputPrice: 2000));

        $this->model('gpt-image-1-mini', fn ($m) => $m
            ->displayName('GPT Image 1 Mini')
            ->type('image')
            ->canGenerateImages()
            ->tier('standard', inputPrice: 200, outputPrice: 800, cachedInputPrice: 20, imageInputPrice: 250, imageOutputPrice: 800)
            ->tier('batch', inputPrice: 100, outputPrice: 400, cachedInputPrice: 10, imageInputPrice: 125, imageOutputPrice: 400));

        // Per 1024x1024 image; `quality: hd` selects the hd tier. Larger
        // sizes (8¢ standard / 12¢ HD) can't be told apart by the catalog.
        $this->model('dall-e-3', fn ($m) => $m
            ->displayName('DALL-E 3')
            ->retiredOn('2026-05-12')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 4)
            ->tier('hd', pricePerUnit: 8));

        $this->model('dall-e-2', fn ($m) => $m
            ->displayName('DALL-E 2')
            ->retiredOn('2026-05-12')
            ->type('image')
            ->pricingUnit('image')
            ->canGenerateImages()
            ->tier('standard', pricePerUnit: 1.6));
    }

    /** Embeddings, moderation and video. */
    protected function defineOtherModels(): void
    {
        $this->model('text-embedding-3-small', fn ($m) => $m
            ->displayName('Text Embedding 3 Small')
            ->type('embedding')
            ->tier('standard', inputPrice: 2, outputPrice: 0)
            ->tier('batch', inputPrice: 1, outputPrice: 0));

        $this->model('text-embedding-3-large', fn ($m) => $m
            ->displayName('Text Embedding 3 Large')
            ->type('embedding')
            ->tier('standard', inputPrice: 13, outputPrice: 0)
            ->tier('batch', inputPrice: 6.5, outputPrice: 0));

        $this->model('text-embedding-ada-002', fn ($m) => $m
            ->displayName('Text Embedding Ada 002')
            ->type('embedding')
            ->tier('standard', inputPrice: 10, outputPrice: 0)
            ->tier('batch', inputPrice: 5, outputPrice: 0));

        $this->model('omni-moderation-latest', fn ($m) => $m
            ->displayName('Omni Moderation')
            ->tier('standard', inputPrice: 0, outputPrice: 0));

        // The Videos API shut down on 2026-09-24.
        $this->model('sora-2', fn ($m) => $m
            ->displayName('Sora 2')
            ->retiredOn('2026-09-24')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 10));

        // 720p is standard, 1024x1792 is hd; 1080p (70¢/s) has no tier.
        $this->model('sora-2-pro', fn ($m) => $m
            ->displayName('Sora 2 Pro')
            ->retiredOn('2026-09-24')
            ->type('video')
            ->pricingUnit('second')
            ->canGenerateVideo()
            ->tier('standard', pricePerUnit: 30)
            ->tier('hd', pricePerUnit: 50));
    }

    /**
     * Built-in tool fees, in cents per call.
     *
     * Web search preview costs 2.5¢ per call on non-reasoning models; tool
     * prices are per provider here, so every web search is billed at the 1¢
     * rate. Hosted shell sessions share the container price but are not
     * counted per container, so they are not billed.
     */
    public function toolCallPricing(): array
    {
        return [
            'web_search_call' => 1.0,            // $10.00 / 1k calls
            'file_search_call' => 0.25,          // $2.50 / 1k calls (Responses API)
            'code_interpreter_call' => 3.0,      // $0.03 per 1 GB container session
        ];
    }
}
