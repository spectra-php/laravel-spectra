<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by providers whose request asks for a pricing tier (OpenAI
 * `service_tier`, a Veo 1080p resolution billed as `hd`). It is the fallback
 * when the response doesn't say which tier was served.
 */
interface ExtractsPricingTierFromRequest
{
    /**
     * Get the pricing tier the request asks for, or null when it names none.
     *
     * @param  array<string, mixed>  $requestData
     */
    public function extractPricingTierFromRequest(array $requestData): ?string;
}
