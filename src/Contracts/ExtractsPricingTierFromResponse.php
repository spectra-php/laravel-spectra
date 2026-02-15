<?php

namespace Spectra\Contracts;

interface ExtractsPricingTierFromResponse
{
    /** @param  array<string, mixed>  $responseData */
    public function extractPricingTierFromResponse(array $responseData): ?string;
}
