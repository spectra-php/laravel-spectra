<?php

declare(strict_types=1);

namespace Spectra\Contracts;

interface ExtractsPricingTierFromResponse
{
    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string;
}
