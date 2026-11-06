<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\PricingTier;
use Spectra\Pricing\ProviderPricing;
use Spectra\Support\Pricing\CostCalculator;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Pricing\RequestCostCalculator;
use Spectra\Support\Tracking\RequestContext;

/**
 * The cost model: cache reads/writes, modality rates, long-context and dated
 * prices, aliases, currency conversion, the default tier and unit dispatch. Uses a test-only catalog so the
 * numbers don't move when the built-in catalogs are updated.
 */
function acmePricing(string $currency = 'USD'): ProviderPricing
{
    return new ProviderPricing('acme', [
        'acme-1' => [
            'display_name' => 'Acme 1',
            'aliases' => ['acme-1-latest', 'acme/acme-1'],
            'capabilities' => ['text'],
            'tiers' => [
                ['tier' => 'standard', 'input_price' => 100, 'output_price' => 400, 'cached_input_price' => 10, 'cache_write_5m_price' => 125, 'cache_write_1h_price' => 200,
                    'audio_input_price' => 300, 'image_input_price' => 200, 'audio_output_price' => 800, 'image_output_price' => 1000],
                ['tier' => 'standard', 'input_price' => 200, 'output_price' => 600, 'cached_input_price' => 20, 'above_input_tokens' => 200_000],
                ['tier' => 'standard', 'input_price' => 150, 'output_price' => 500, 'since' => '2027-01-01'],
                ['tier' => 'batch', 'input_price' => 50, 'output_price' => 200],
            ],
        ],
        'acme-1-2026-01-01' => [
            'display_name' => 'Acme 1 (2026-01-01)',
            'capabilities' => ['text'],
            'tiers' => [['tier' => 'standard', 'input_price' => 999, 'output_price' => 999]],
        ],
        'acme-ocr' => [
            'display_name' => 'Acme OCR',
            'pricing_unit' => 'page',
            'tiers' => [['tier' => 'standard', 'price_per_unit' => 0.4]],
        ],
    ], $currency);
}

function useAcmePricing(string $currency = 'USD'): void
{
    app()->instance(PricingLookup::class, new PricingLookup([acmePricing($currency)]));
}

beforeEach(fn () => useAcmePricing());

it('bills uncached, cache-read, 5m and 1h cache-write tokens at their own rates', function () {
    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(
        promptTokens: 10_000 + 20_000 + 30_000,
        completionTokens: 10_000,
        cachedTokens: 20_000,
        cacheWriteTokens: 30_000,
        cacheWrite1hTokens: 10_000,
    ), at: Date::parse('2026-10-15'));

    // (10K uncached × 100 + 20K reads × 10 + 20K 5m-writes × 125 + 10K 1h-writes × 200) / 1M = 5.7; 10K out × 400 / 1M = 4
    expect($cost['prompt_cost'])->toEqualWithDelta(5.7, 1e-9)
        ->and($cost['completion_cost'])->toEqualWithDelta(4.0, 1e-9)
        ->and($cost['total_cost_in_cents'])->toEqualWithDelta(9.7, 1e-9);
});

it('bills audio and image tokens at their modality rates', function () {
    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(
        promptTokens: 30_000,
        completionTokens: 30_000,
        audioInputTokens: 10_000,
        imageInputTokens: 10_000,
        audioOutputTokens: 10_000,
        imageOutputTokens: 10_000,
    ), at: Date::parse('2026-10-15'));

    // in: 10K text × 100 + 10K audio × 300 + 10K image × 200 = 6; out: 10K × 400 + 10K × 800 + 10K × 1000 = 22 (per 1M)
    expect($cost['prompt_cost'])->toEqualWithDelta(6.0, 1e-9)
        ->and($cost['completion_cost'])->toEqualWithDelta(22.0, 1e-9);
});

it('never bills a breakdown beyond the prompt total', function () {
    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(
        promptTokens: 100_000,
        cachedTokens: 500_000,
    ), at: Date::parse('2026-10-15'));

    // Only the 100K prompt tokens are billed, all as cache reads: 100K × 10 / 1M
    expect($cost['prompt_cost'])->toEqualWithDelta(1.0, 1e-9);
});

it('switches to long-context prices above the threshold', function () {
    $calculator = app(CostCalculator::class);
    $at = Date::parse('2026-10-15');

    $short = $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 200_000), at: $at);
    $long = $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 200_001), at: $at);

    // 200K × 100 / 1M at the regular price; 200,001 × 200 / 1M once above the threshold
    expect($short['prompt_cost'])->toEqualWithDelta(20.0, 1e-9)
        ->and($long['prompt_cost'])->toEqualWithDelta(40.0002, 1e-9);
});

it('applies dated prices from their start date', function () {
    $calculator = app(CostCalculator::class);
    $tokens = new TokenMetrics(promptTokens: 100_000);

    // 100K × 100 / 1M before 2027-01-01, 100K × 150 / 1M from then on
    expect($calculator->forTokens('acme', 'acme-1', $tokens, at: Date::parse('2026-12-31'))['prompt_cost'])->toEqualWithDelta(10.0, 1e-9)
        ->and($calculator->forTokens('acme', 'acme-1', $tokens, at: Date::parse('2027-01-01'))['prompt_cost'])->toEqualWithDelta(15.0, 1e-9);
});

it('falls back to standard prices for a tier the model lacks', function () {
    $calculator = app(CostCalculator::class);
    $tokens = new TokenMetrics(promptTokens: 100_000);
    $at = Date::parse('2026-10-15');

    // 100K × 50 (batch) / 1M; priority isn't offered, so 100K × 100 (standard) / 1M
    expect($calculator->forTokens('acme', 'acme-1', $tokens, 'batch', $at)['prompt_cost'])->toEqualWithDelta(5.0, 1e-9)
        ->and($calculator->forTokens('acme', 'acme-1', $tokens, 'priority', $at)['prompt_cost'])->toEqualWithDelta(10.0, 1e-9);
});

it('resolves aliases, routing suffixes and dated ids, but never :free', function () {
    $lookup = app(PricingLookup::class);

    expect($lookup->canonicalModel('acme', 'acme-1-latest'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme/acme-1'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme-1:nitro'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme-1-20260501'))->toBe('acme-1')
        ->and($lookup->canonicalModel('acme', 'acme-1:free'))->toBeNull();
});

it('prefers an explicit dated catalog entry over the date-stripped one', function () {
    expect(app(PricingLookup::class)->canonicalModel('acme', 'acme-1-2026-01-01'))->toBe('acme-1-2026-01-01');
});

it('prices page-billed models per page', function () {
    $cost = app(CostCalculator::class)->calculateByPages('acme', 'acme-ocr', 10);

    // 0.4 cents/page × 10 pages
    expect($cost['total_cost_in_cents'])->toEqualWithDelta(4.0, 1e-9);
});

it('converts catalog prices into the reporting currency', function () {
    useAcmePricing('EUR');
    config(['spectra.costs.currency' => 'USD', 'spectra.costs.exchange_rates' => ['EUR' => 1.5]]);

    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000), at: Date::parse('2026-10-15'));

    // 100K × 100 / 1M = 10 EUR cents × 1.5
    expect($cost['prompt_cost'])->toEqualWithDelta(15.0, 1e-9);
});

it('counts a missing exchange rate as 1:1 and warns once', function () {
    $this->travelTo('2026-10-15');
    useAcmePricing('EUR');
    Log::spy();
    $calculator = new CostCalculator;

    $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000));
    $cost = $calculator->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000));

    // 100K × 100 / 1M EUR cents, counted 1:1
    expect($cost['prompt_cost'])->toEqualWithDelta(10.0, 1e-9);
    Log::shouldHaveReceived('warning')->once();
});

it('converts each model at the currency of the catalog that defines it', function () {
    $extraModels = new ProviderPricing('acme', [
        'acme-extra' => ['capabilities' => ['text'], 'tiers' => [['tier' => 'standard', 'input_price' => 100, 'output_price' => 400]]],
    ]);
    app()->instance(PricingLookup::class, new PricingLookup([acmePricing('EUR'), $extraModels]));
    config(['spectra.costs.currency' => 'USD', 'spectra.costs.exchange_rates' => ['EUR' => 1.5]]);

    $builtIn = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000), at: Date::parse('2026-10-15'));
    $extra = app(CostCalculator::class)->forTokens('acme', 'acme-extra', new TokenMetrics(promptTokens: 100_000));

    // 100K × 100 / 1M = 10 EUR cents × 1.5; the USD catalog's model stays at 10.
    expect($builtIn['prompt_cost'])->toEqualWithDelta(15.0, 1e-9)
        ->and($extra['prompt_cost'])->toEqualWithDelta(10.0, 1e-9);
});

it('converts tool calls at the currency of the catalog that prices them', function () {
    $toolCatalog = new ProviderPricing('acme', [], 'EUR', toolCalls: ['web_search_call' => 1.0]);
    app()->instance(PricingLookup::class, new PricingLookup([$toolCatalog, acmePricing('USD')]));
    config(['spectra.costs.currency' => 'USD', 'spectra.costs.exchange_rates' => ['EUR' => 1.5]]);

    $cost = app(RequestCostCalculator::class)->forContext(new RequestContext([
        'provider' => 'acme',
        'model' => 'acme-unknown',
        'tool_call_counts' => ['web_search_call' => 2],
    ]));

    // 2 calls × 1 EUR cent × 1.5
    expect($cost['total_cost_in_cents'])->toEqualWithDelta(3.0, 1e-9);
});

it('bills an unknown default tier at standard and warns once', function () {
    config(['spectra.costs.provider_settings.acme.default_tier' => 'turbo']);
    Log::spy();

    $first = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000), at: Date::parse('2026-10-15'));
    app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000), at: Date::parse('2026-10-15'));

    // 100K × 100 (standard input) / 1M
    expect($first['prompt_cost'])->toEqualWithDelta(10.0, 1e-9)
        ->and(app(PricingLookup::class)->defaultTier('acme'))->toBe(PricingTier::Standard);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, "'turbo'"));
});

it('bills a valid default tier from config', function () {
    config(['spectra.costs.provider_settings.acme.default_tier' => 'batch']);

    $cost = app(CostCalculator::class)->forTokens('acme', 'acme-1', new TokenMetrics(promptTokens: 100_000), at: Date::parse('2026-10-15'));

    // 100K × 50 (batch input) / 1M
    expect($cost['prompt_cost'])->toEqualWithDelta(5.0, 1e-9)
        ->and(app(PricingLookup::class)->defaultTier('acme'))->toBe(PricingTier::Batch);
});

it('bills a pricing unit the enum does not know as tokens', function () {
    $calculator = new RequestCostCalculator(new class extends CostCalculator
    {
        public function getPricingUnit(string $provider, string $model): string
        {
            return 'liters';
        }
    });

    $cost = $calculator->forContext(new RequestContext([
        'provider' => 'acme',
        'model' => 'acme-1',
        'prompt_tokens' => 100_000,
        'started_at' => Date::parse('2026-10-15'),
    ]));

    // 100K × 100 / 1M
    expect($cost['total_cost_in_cents'])->toEqualWithDelta(10.0, 1e-9);
});

it('reads provider usage keys and round-trips its own usage array', function () {
    $tokens = TokenMetrics::fromArray(['input_tokens' => 10, 'output_tokens' => 5, 'cache_write_tokens' => 3, 'cached_tokens' => 2]);

    expect($tokens->promptTokens)->toBe(10)
        ->and($tokens->completionTokens)->toBe(5)
        ->and($tokens->cacheWriteTokens)->toBe(3)
        ->and(TokenMetrics::fromArray($tokens->toUsageArray()))->toEqual($tokens);
});
