<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Support\Tracking\RequestContext;

/**
 * Computes the full cost of a tracked request, dispatching on its pricing unit
 * and adding any per-tool-call surcharges.
 *
 * This is the single source of truth for request cost — used both when a
 * RequestContext completes (for in-memory consumers) and when the request is
 * persisted, so the two never diverge.
 *
 * Order of precedence:
 *  1. A cost the provider reported itself (OpenRouter, xAI) — exact.
 *  2. The catalog price of the model the provider actually served (the
 *     response snapshot), then of the model that was requested.
 */
class RequestCostCalculator
{
    /**
     * Create a new request cost calculator instance.
     */
    public function __construct(
        protected CostCalculator $costCalculator,
    ) {}

    /**
     * Calculate a tracked request's cost in cents, with multipliers, tool-call fees and reported costs.
     *
     * @return array{prompt_cost?: float, completion_cost?: float, total_cost_in_cents: float}
     */
    public function forContext(RequestContext $context, string|PricingTier|null $pricingTier = null): array
    {
        $tier = $pricingTier ?? $context->pricingTier;
        $model = $this->getPricedModel($context);
        $at = $context->getStartedAt();
        $provider = $context->provider;
        // A unit string the enum doesn't know (a custom CostCalculator) bills as tokens.
        $pricingUnit = PricingUnit::tryFrom($this->costCalculator->getPricingUnit($provider, $model)) ?? PricingUnit::Tokens;

        $cost = match ($pricingUnit) {
            PricingUnit::Tokens => $this->costCalculator->forTokens($provider, $model, $context->tokens(), $tier, $at),
            PricingUnit::Minute => $this->costCalculator->calculateByDuration($provider, $model, $context->durationSeconds ?? 0, $tier, $at),
            PricingUnit::Second => $this->costCalculator->calculateByDurationSeconds($provider, $model, $context->durationSeconds ?? 0, $tier, $at),
            PricingUnit::Characters => $this->costCalculator->calculateByCharacters($provider, $model, $context->inputCharacters ?? 0, $tier, $at),
            PricingUnit::Image => $this->costCalculator->calculateByImages($provider, $model, $context->imageCount ?? 0, $tier, $at),
            PricingUnit::Megapixel => $this->costCalculator->calculateByMegapixels($provider, $model, $context->megapixels ?? 0, $tier, $at),
            PricingUnit::Video => $this->costCalculator->calculateByVideos($provider, $model, $context->videoCount ?? 0, $tier, $at),
            PricingUnit::Search => $this->costCalculator->calculateBySearches($provider, $model, $context->searchCount ?? 0, $tier, $at),
            PricingUnit::Page => $this->costCalculator->calculateByPages($provider, $model, $context->pageCount ?? 0, $tier, $at),
            PricingUnit::Request => $this->costCalculator->calculateByRequests($provider, $model, $this->hasSucceeded($context) ? 1 : 0, $tier, $at),
        };

        // Regional surcharges (e.g. 1.1x for US-only inference) apply to the
        // model price, not to per-call tool fees.
        if ($context->costMultiplier !== 1.0 && $context->costMultiplier > 0) {
            $cost = array_map(fn (float $value) => $value * $context->costMultiplier, $cost);
        }

        $toolCallSurcharge = $this->calculateToolCallCost($context);
        if ($toolCallSurcharge > 0) {
            $cost['total_cost_in_cents'] += $toolCallSurcharge;
        }

        if ($context->reportedCostInCents !== null) {
            $cost = $this->applyReportedCost($cost, $this->costCalculator->convertFromUsd($context->reportedCostInCents));
        }

        return $cost;
    }

    /**
     * Get the model id to price: the snapshot the provider served when the
     * catalog knows it (it may differ from the request — routing, aliases,
     * retired models that redirect), otherwise the requested model.
     */
    protected function getPricedModel(RequestContext $context): string
    {
        $lookup = app(PricingLookup::class);

        if (filled($context->snapshot) && $lookup->knows($context->provider, $context->snapshot)) {
            return $context->snapshot;
        }

        return $context->model;
    }

    /**
     * Determine if the request succeeded, so a per-request model bills it.
     */
    protected function hasSucceeded(RequestContext $context): bool
    {
        return $context->errorType === null && ($context->httpStatus ?? 200) < 400;
    }

    /**
     * Use the provider-reported total, keeping the catalog's prompt/completion
     * split in proportion when there is one.
     *
     * @param  array{prompt_cost?: float, completion_cost?: float, total_cost_in_cents: float}  $estimate
     * @return array{prompt_cost?: float, completion_cost?: float, total_cost_in_cents: float}
     */
    protected function applyReportedCost(array $estimate, float $reported): array
    {
        $estimatedTotal = $estimate['total_cost_in_cents'];
        $ratio = $estimatedTotal > 0 ? $reported / $estimatedTotal : 0.0;

        return [
            'prompt_cost' => ($estimate['prompt_cost'] ?? 0.0) * $ratio,
            'completion_cost' => ($estimate['completion_cost'] ?? 0.0) * $ratio,
            'total_cost_in_cents' => $reported,
        ];
    }

    /**
     * Calculate the cost of tool calls based on per-provider pricing, in cents.
     */
    protected function calculateToolCallCost(RequestContext $context): float
    {
        if (blank($context->toolCallCounts)) {
            return 0.0;
        }

        $lookup = app(PricingLookup::class);
        $pricing = $lookup->getToolCallPricing($context->provider);

        if (blank($pricing)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($context->toolCallCounts as $type => $count) {
            if (isset($pricing[$type])) {
                $total += $pricing[$type] * $count;
            }
        }

        return $total * $this->costCalculator->currencyFactor($lookup->toolCallCurrency($context->provider));
    }
}
