<?php

declare(strict_types=1);

namespace Spectra\Contracts;

interface ExtractsPricingTierFromRequest
{
    /** @param  array<string, mixed>  $requestData */
    public function extractPricingTierFromRequest(array $requestData): ?string;
}
