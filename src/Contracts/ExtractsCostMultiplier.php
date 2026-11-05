<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by providers that charge a surcharge the response reveals, such
 * as Anthropic's 1.1x price for US-only inference (`usage.inference_geo`).
 */
interface ExtractsCostMultiplier
{
    /**
     * Get the multiplier applied to the whole request cost, or null for none.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $requestData
     */
    public function extractCostMultiplier(array $responseData, array $requestData): ?float;
}
