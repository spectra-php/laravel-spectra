<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Implemented by providers whose price depends on what the request asked a
 * given model for, such as fal.ai video models that cost more with audio or
 * at a higher resolution.
 *
 * Unlike {@see ExtractsCostMultiplier}, it receives the model id.
 */
interface ExtractsCostMultiplierForModel
{
    /**
     * Multiplier applied to the model's catalog price, or null for none.
     *
     * @param  array<string, mixed>  $responseData
     * @param  array<string, mixed>  $requestData
     */
    public function extractCostMultiplierForModel(string $model, array $responseData, array $requestData): ?float;
}
