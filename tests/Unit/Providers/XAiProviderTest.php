<?php

declare(strict_types=1);

/**
 * The xAI provider: the response id of a video job (from the poll path or
 * the submit body) and the served tier, including the `fast` alias.
 */
it('takes the response id of a video poll from the path', function () {
    $provider = $this->xAiProvider();

    expect($provider->extractResponseId(['status' => 'done'], '/v1/videos/req-1'))->toBe('req-1')
        ->and($provider->extractResponseId(['request_id' => 'req-2'], '/v1/videos/generations'))->toBe('req-2')
        ->and($provider->extractResponseId([], '/v1/videos/generations'))->toBeNull();
});

it('reads the served tier from service_tier', function () {
    $provider = $this->xAiProvider();

    expect($provider->extractPricingTierFromResponse(['service_tier' => 'priority']))->toBe('priority')
        ->and($provider->extractPricingTierFromResponse(['service_tier' => 'fast']))->toBe('priority')
        ->and($provider->extractPricingTierFromResponse(['service_tier' => 'default']))->toBe('standard')
        ->and($provider->extractPricingTierFromResponse([]))->toBeNull()
        ->and($provider->extractPricingTierFromRequest(['service_tier' => 'priority']))->toBe('priority');
});
