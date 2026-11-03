<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by providers that report the tier they actually served, which
 * can differ from the one requested (a priority request downgraded to
 * standard, a project defaulting to flex). It wins over the request's tier.
 */
interface ExtractsPricingTierFromResponse
{
    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string;
}
