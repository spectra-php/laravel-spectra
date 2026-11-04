<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;

/**
 * Turns usage into cost, in cents of the reporting currency
 * (`spectra.costs.currency`).
 *
 * Token costs follow the TokenMetrics contract: the prompt total is split into
 * uncached, cache-read, cache-write (5m / 1h), audio and image tokens, and the
 * completion total into text, audio and image tokens, each billed at its own
 * rate. A rate the catalog leaves empty falls back to the plain input or
 * output price.
 *
 * @phpstan-import-type Price from PricingLookup
 */
class CostCalculator
{
    /** @var array<string, true> Currencies we already warned about (once per process). */
    protected array $warnedCurrencies = [];

    /**
     * Calculate the cost of plain prompt, completion and cached token counts.
     *
     * @return array{prompt_cost: float, completion_cost: float, total_cost_in_cents: float}
     */
    public function calculate(
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $cachedTokens = 0,
        string|PricingTier|null $pricingTier = null
    ): array {
        return $this->forTokens(
            $provider,
            $model,
            new TokenMetrics(promptTokens: $promptTokens, completionTokens: $completionTokens, cachedTokens: $cachedTokens),
            $pricingTier,
        );
    }

    /**
     * Calculate the prompt and completion cost of token usage, billing each token kind at its own rate.
     *
     * @return array{prompt_cost: float, completion_cost: float, total_cost_in_cents: float}
     */
    public function forTokens(
        string $provider,
        string $model,
        TokenMetrics $tokens,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        $pricing = $this->getPricing($provider, $model, $pricingTier ?? $this->getDefaultTier($provider), $tokens->promptTokens, $at);

        if ($pricing === null) {
            return [
                'prompt_cost' => 0.0,
                'completion_cost' => 0.0,
                'total_cost_in_cents' => 0.0,
            ];
        }

        $remaining = max(0, $tokens->promptTokens);
        $take = static function (int $requested) use (&$remaining): int {
            $taken = min(max(0, $requested), $remaining);
            $remaining -= $taken;

            return $taken;
        };

        $cached = $take($tokens->cachedTokens);
        $writes = $take($tokens->cacheWriteTokens);
        $writes1h = min(max(0, $tokens->cacheWrite1hTokens), $writes);
        $audioIn = $take($tokens->audioInputTokens);
        $imageIn = $take($tokens->imageInputTokens);
        $regularIn = $remaining;

        $input = $pricing['input'];
        $cacheWrite5m = $pricing['cache_write_5m'] ?? $input;

        $promptCost = (
            $regularIn * $input
            + $cached * ($pricing['cached_input'] ?? $input)
            + ($writes - $writes1h) * $cacheWrite5m
            + $writes1h * ($pricing['cache_write_1h'] ?? $cacheWrite5m)
            + $audioIn * ($pricing['audio_input'] ?? $input)
            + $imageIn * ($pricing['image_input'] ?? $input)
        ) / 1_000_000;

        $outputTotal = max(0, $tokens->completionTokens);
        $audioOut = min(max(0, $tokens->audioOutputTokens), $outputTotal);
        $imageOut = min(max(0, $tokens->imageOutputTokens), $outputTotal - $audioOut);
        $regularOut = $outputTotal - $audioOut - $imageOut;

        $output = $pricing['output'];

        $completionCost = (
            $regularOut * $output
            + $audioOut * ($pricing['audio_output'] ?? $output)
            + $imageOut * ($pricing['image_output'] ?? $output)
        ) / 1_000_000;

        return [
            'prompt_cost' => (float) $promptCost,
            'completion_cost' => (float) $completionCost,
            'total_cost_in_cents' => (float) ($promptCost + $completionCost),
        ];
    }

    /**
     * Price row for a model, converted to the reporting currency.
     *
     * @return Price|null
     */
    public function getPricing(
        string $provider,
        string $model,
        string|PricingTier|null $pricingTier = null,
        ?int $promptTokens = null,
        ?CarbonInterface $at = null,
    ): ?array {
        $lookup = app(PricingLookup::class);
        $pricing = $lookup->get($provider, $model, $pricingTier, $promptTokens, $at);

        if ($pricing === null) {
            return null;
        }

        $factor = $this->currencyFactor($lookup->modelCurrency($provider, $model));

        if ($factor === 1.0) {
            return $pricing;
        }

        foreach (['input', 'output', 'cached_input', 'cache_write_5m', 'cache_write_1h', 'price_per_unit', 'audio_input', 'audio_output', 'image_input', 'image_output'] as $field) {
            if ($pricing[$field] !== null) {
                $pricing[$field] *= $factor;
            }
        }

        return $pricing;
    }

    /**
     * Determine if the catalog has a price for the model on the given tier.
     */
    public function hasPricing(string $provider, string $model, string|PricingTier|null $pricingTier = null): bool
    {
        return app(PricingLookup::class)->has($provider, $model, $pricingTier);
    }

    /**
     * Estimate the cost of a request from expected prompt and completion token counts.
     *
     * @return array{prompt_cost: float, completion_cost: float, total_cost_in_cents: float}
     */
    public function estimate(
        string $provider,
        string $model,
        int $estimatedPromptTokens,
        int $estimatedCompletionTokens = 0,
        string|PricingTier|null $pricingTier = null
    ): array {
        return $this->calculate(
            $provider,
            $model,
            $estimatedPromptTokens,
            $estimatedCompletionTokens,
            0,
            $pricingTier
        );
    }

    /**
     * Get the input, output and cached input price of a single token, in cents.
     *
     * @return array{input_per_token: float, output_per_token: float, cached_input_per_token: float}|null
     */
    public function getCostPerToken(string $provider, string $model, string|PricingTier|null $pricingTier = null): ?array
    {
        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null) {
            return null;
        }

        return [
            'input_per_token' => $pricing['input'] / 1_000_000,
            'output_per_token' => $pricing['output'] / 1_000_000,
            'cached_input_per_token' => ($pricing['cached_input'] ?? $pricing['input']) / 1_000_000,
        ];
    }

    /**
     * Cost for minute-billed models (e.g. Whisper), from a duration in seconds.
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByDuration(
        string $provider,
        string $model,
        float $durationSeconds,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $durationSeconds / 60, $pricingTier, $at);
    }

    /**
     * Calculate the cost for second-billed models (e.g. video generation) from a duration.
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByDurationSeconds(
        string $provider,
        string $model,
        float $durationSeconds,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $durationSeconds, $pricingTier, $at);
    }

    /**
     * price_per_unit for character-based models is cents per 1M characters,
     * matching the cents-per-1M-tokens convention used everywhere else.
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByCharacters(
        string $provider,
        string $model,
        int $characters,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $characters / 1_000_000, $pricingTier, $at);
    }

    /**
     * Calculate the cost for image-billed models from the number of images generated.
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByImages(
        string $provider,
        string $model,
        int $imageCount,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $imageCount, $pricingTier, $at);
    }

    /**
     * Calculate the cost for megapixel-billed image models from the megapixels generated.
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByMegapixels(
        string $provider,
        string $model,
        float $megapixels,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $megapixels, $pricingTier, $at);
    }

    /**
     * Calculate the cost for video-billed models from the number of videos generated.
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByVideos(
        string $provider,
        string $model,
        int $videoCount,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $videoCount, $pricingTier, $at);
    }

    /**
     * Cost for search-billed models (e.g. Cohere Rerank, priced per search).
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateBySearches(
        string $provider,
        string $model,
        int $searchCount = 1,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $searchCount, $pricingTier, $at);
    }

    /**
     * Cost for page-billed models (e.g. OCR).
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByPages(
        string $provider,
        string $model,
        int $pages,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $pages, $pricingTier, $at);
    }

    /**
     * Cost for models billed a flat price per request (e.g. per song).
     *
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByRequests(
        string $provider,
        string $model,
        int $requests = 1,
        string|PricingTier|null $pricingTier = null,
        ?CarbonInterface $at = null,
    ): array {
        return $this->perUnit($provider, $model, $requests, $pricingTier, $at);
    }

    /**
     * Get the unit the model is billed in, defaulting to tokens for an unknown model.
     */
    public function getPricingUnit(string $provider, string $model): string
    {
        return app(PricingLookup::class)->getUnit($provider, $model) ?? PricingUnit::Tokens->value;
    }

    /**
     * Convert a US-cent amount (e.g. a provider-reported cost) to the
     * reporting currency.
     */
    public function convertFromUsd(float $cents): float
    {
        return $cents * $this->currencyFactor('USD');
    }

    /**
     * How many units of the reporting currency one unit of $currency is worth.
     *
     * Rates come from `spectra.costs.exchange_rates` (e.g. ['EUR' => 1.17] when
     * reporting in USD). A missing rate counts as 1.0 and is logged once.
     */
    public function currencyFactor(string $currency): float
    {
        $currency = strtoupper($currency);
        $target = strtoupper((string) config('spectra.costs.currency', 'USD'));

        if ($currency === $target) {
            return 1.0;
        }

        $rate = config("spectra.costs.exchange_rates.{$currency}");

        if (is_numeric($rate) && (float) $rate > 0) {
            return (float) $rate;
        }

        if (! isset($this->warnedCurrencies[$currency])) {
            $this->warnedCurrencies[$currency] = true;

            Log::warning("Spectra: no exchange rate from {$currency} to {$target}. Add one under spectra.costs.exchange_rates; {$currency} prices are counted 1:1 until then.");
        }

        return 1.0;
    }

    /**
     * Multiply a quantity by the model's unit price, or bill nothing when it has none.
     *
     * @return array{total_cost_in_cents: float}
     */
    protected function perUnit(string $provider, string $model, float $quantity, string|PricingTier|null $pricingTier, ?CarbonInterface $at): array
    {
        $pricing = $this->getPricing($provider, $model, $pricingTier ?? $this->getDefaultTier($provider), null, $at);

        if ($pricing === null || $pricing['price_per_unit'] === null) {
            return ['total_cost_in_cents' => 0.0];
        }

        return ['total_cost_in_cents' => (float) (max(0, $quantity) * $pricing['price_per_unit'])];
    }

    /**
     * Get the tier a provider's requests bill at when none was detected.
     */
    protected function getDefaultTier(string $provider): PricingTier|string
    {
        return app(PricingLookup::class)->defaultTier($provider);
    }
}
