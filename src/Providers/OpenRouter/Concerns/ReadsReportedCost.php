<?php

declare(strict_types=1);

namespace Spectra\Providers\OpenRouter\Concerns;

use Spectra\Support\Payload;

/**
 * Reads the cost OpenRouter reports in every response's `usage` object.
 *
 * `usage.cost` is what OpenRouter charged your credits, in US dollars. With
 * Bring Your Own Key (`usage.is_byok`) that is only OpenRouter's fee: the
 * model provider charges your own key the upstream price, reported as
 * `usage.cost_details.upstream_inference_cost`. Both are added so the
 * recorded cost is what the request really cost you.
 */
trait ReadsReportedCost
{
    /**
     * Get the reported cost in US cents, adding the upstream cost for BYOK requests.
     *
     * @param  array<string, mixed>  $usage
     */
    protected function costFromUsage(array $usage): ?float
    {
        $cost = $usage['cost'] ?? null;

        if (! is_numeric($cost)) {
            return null;
        }

        $total = Payload::float($cost);

        // Non-BYOK responses leave the upstream cost null (it is already
        // inside `cost`); older BYOK responses omit `is_byok`, so only an
        // explicit `false` rules the upstream cost out.
        $upstream = is_array($usage['cost_details'] ?? null)
            ? ($usage['cost_details']['upstream_inference_cost'] ?? null)
            : null;

        if (($usage['is_byok'] ?? null) !== false && is_numeric($upstream)) {
            $total += Payload::float($upstream);
        }

        return $total * 100;
    }
}
