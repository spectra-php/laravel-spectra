<?php

namespace Spectra\Support\Pricing;

use Spectra\Enums\PricingTier;

class CostCalculator
{
    /**
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
        if (in_array($provider, ['openai', 'anthropic']) && $pricingTier === null) {
            $pricingTier = $this->getDefaultTier($provider);
        }

        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null) {
            return [
                'prompt_cost' => 0.0,
                'completion_cost' => 0.0,
                'total_cost_in_cents' => 0.0,
            ];
        }

        $regularPromptTokens = max(0, $promptTokens - $cachedTokens);
        $cachedInputPrice = $pricing['cached_input'] ?? $pricing['input'];

        $promptCost = ($regularPromptTokens * $pricing['input'] + $cachedTokens * $cachedInputPrice) / 1_000_000;
        $completionCost = ($completionTokens * $pricing['output']) / 1_000_000;

        return [
            'prompt_cost' => $promptCost,
            'completion_cost' => $completionCost,
            'total_cost_in_cents' => $promptCost + $completionCost,
        ];
    }

    /**
     * @return array{input: int|float, output: int|float, cached_input?: int|float|null, cache_write_5m?: int|float|null, cache_write_1h?: int|float|null, price_per_unit?: int|float|null}|null
     */
    public function getPricing(string $provider, string $model, string|PricingTier|null $pricingTier = null): ?array
    {
        return app(PricingLookup::class)->get($provider, $model, $pricingTier);
    }

    public function hasPricing(string $provider, string $model, string|PricingTier|null $pricingTier = null): bool
    {
        return app(PricingLookup::class)->has($provider, $model, $pricingTier);
    }

    /**
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
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByDuration(
        string $provider,
        string $model,
        float $durationSeconds,
        ?string $pricingTier = null
    ): array {
        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null || ! isset($pricing['price_per_unit'])) {
            return ['total_cost_in_cents' => 0.0];
        }

        $durationMinutes = $durationSeconds / 60;
        $totalCost = $durationMinutes * $pricing['price_per_unit'];

        return ['total_cost_in_cents' => $totalCost];
    }

    /**
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByDurationSeconds(
        string $provider,
        string $model,
        float $durationSeconds,
        ?string $pricingTier = null
    ): array {
        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null || ! isset($pricing['price_per_unit'])) {
            return ['total_cost_in_cents' => 0.0];
        }

        $totalCost = $durationSeconds * $pricing['price_per_unit'];

        return ['total_cost_in_cents' => $totalCost];
    }

    /**
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByCharacters(
        string $provider,
        string $model,
        int $characters,
        ?string $pricingTier = null
    ): array {
        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null || ! isset($pricing['price_per_unit'])) {
            return ['total_cost_in_cents' => 0.0];
        }

        $totalCost = ($characters * $pricing['price_per_unit']) / 1_000_000;

        return ['total_cost_in_cents' => $totalCost];
    }

    /**
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByImages(
        string $provider,
        string $model,
        int $imageCount,
        ?string $pricingTier = null
    ): array {
        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null || ! isset($pricing['price_per_unit'])) {
            return ['total_cost_in_cents' => 0.0];
        }

        $totalCost = $imageCount * $pricing['price_per_unit'];

        return ['total_cost_in_cents' => $totalCost];
    }

    /**
     * @return array{total_cost_in_cents: float}
     */
    public function calculateByVideos(
        string $provider,
        string $model,
        int $videoCount,
        ?string $pricingTier = null
    ): array {
        $pricing = $this->getPricing($provider, $model, $pricingTier);

        if ($pricing === null || ! isset($pricing['price_per_unit'])) {
            return ['total_cost_in_cents' => 0.0];
        }

        $totalCost = $videoCount * $pricing['price_per_unit'];

        return ['total_cost_in_cents' => $totalCost];
    }

    public function getPricingUnit(string $provider, string $model): string
    {
        return app(PricingLookup::class)->getUnit($provider, $model) ?? 'tokens';
    }

    protected function getDefaultTier(string $provider): PricingTier
    {
        $tierValue = config("spectra.costs.provider_settings.{$provider}.default_tier", 'standard');

        return PricingTier::tryFrom($tierValue) ?? PricingTier::Standard;
    }
}
