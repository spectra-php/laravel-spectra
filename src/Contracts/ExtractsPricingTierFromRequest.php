<?php

namespace Spectra\Contracts;

interface ExtractsPricingTierFromRequest
{
    /** @param  array<string, mixed>  $requestData */
    public function extractPricingTierFromRequest(array $requestData): ?string;
}
