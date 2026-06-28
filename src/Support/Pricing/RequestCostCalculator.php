<?php

declare(strict_types=1);

namespace Spectra\Support\Pricing;

use Spectra\Enums\PricingTier;
use Spectra\Enums\PricingUnit;
use Spectra\Support\Tracking\RequestContext;

/**
 * Computes the full cost of a tracked request, dispatching on its pricing unit
 * (tokens, minute, second, characters, image, video, search) and adding any
 * per-tool-call surcharges.
 *
 * This is the single source of truth for request cost — used both when a
 * RequestContext completes (for in-memory consumers) and when the request is
 * persisted, so the two never diverge.
 */
class RequestCostCalculator
{
    public function __construct(
        protected CostCalculator $costCalculator,
    ) {}

    /**
     * @return array{prompt_cost?: float, completion_cost?: float, total_cost_in_cents: float}
     */
    public function forContext(RequestContext $context, string|PricingTier|null $pricingTier = null): array
    {
        $model = $context->model;
        $tier = $pricingTier instanceof PricingTier ? $pricingTier->value : $pricingTier;
        $pricingUnit = $this->costCalculator->getPricingUnit($context->provider, $model);

        $cost = match ($pricingUnit) {
            PricingUnit::Minute->value => $this->costCalculator->calculateByDuration(
                $context->provider,
                $model,
                $context->durationSeconds ?? 0,
                $tier
            ),
            PricingUnit::Second->value => $this->costCalculator->calculateByDurationSeconds(
                $context->provider,
                $model,
                $context->durationSeconds ?? 0,
                $tier
            ),
            PricingUnit::Characters->value => $this->costCalculator->calculateByCharacters(
                $context->provider,
                $model,
                $context->inputCharacters ?? 0,
                $tier
            ),
            PricingUnit::Image->value => $this->costCalculator->calculateByImages(
                $context->provider,
                $model,
                $context->imageCount ?? 0,
                $tier
            ),
            PricingUnit::Video->value => $this->costCalculator->calculateByVideos(
                $context->provider,
                $model,
                $context->videoCount ?? 0,
                $tier
            ),
            PricingUnit::Search->value => $this->costCalculator->calculateBySearches(
                $context->provider,
                $model,
                1,
                $tier
            ),
            default => $this->costCalculator->calculate(
                $context->provider,
                $model,
                $context->promptTokens,
                $context->completionTokens,
                $context->cachedTokens,
                $pricingTier
            ),
        };

        // Add tool call surcharges (e.g. web_search_call, code_interpreter_call).
        $toolCallSurcharge = $this->calculateToolCallCost($context);
        if ($toolCallSurcharge > 0) {
            $cost['total_cost_in_cents'] = $cost['total_cost_in_cents'] + $toolCallSurcharge;
        }

        return $cost;
    }

    /**
     * Calculate the cost of tool calls based on per-provider pricing, in cents.
     */
    protected function calculateToolCallCost(RequestContext $context): float
    {
        if (empty($context->toolCallCounts)) {
            return 0.0;
        }

        $pricing = app(PricingLookup::class)->getToolCallPricing($context->provider);

        if (empty($pricing)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($context->toolCallCounts as $type => $count) {
            if (isset($pricing[$type])) {
                $total += $pricing[$type] * $count;
            }
        }

        return $total;
    }
}
