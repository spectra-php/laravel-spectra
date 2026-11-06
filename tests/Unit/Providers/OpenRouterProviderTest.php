<?php

declare(strict_types=1);

use Spectra\Providers\OpenRouter\Handlers\ChatHandler;
use Spectra\Providers\OpenRouter\OpenRouter;

/**
 * The OpenRouter provider: untracked video downloads, the BYOK branches of
 * the reported cost, and image and 1-hour cache-write tokens.
 */
it('does not track video downloads', function () {
    expect((new OpenRouter)->resolveHandler('/api/v1/videos/gen-vid-1/content'))->toBeNull();
});

it('reads the reported cost in cents', function (array $usage, float $expected) {
    $cost = (new ChatHandler)->reportedCostInCents($usage, []);

    expect($cost)->toEqualWithDelta($expected, 0.0000001);
})->with([
    // ($0.95 fee + $19 upstream) × 100
    'byok without the flag' => [['cost' => 0.95, 'cost_details' => ['upstream_inference_cost' => 19]], 1995.0],
    // $0.01 × 100, the upstream cost is already inside `cost`
    'not byok never adds upstream' => [['cost' => 0.01, 'is_byok' => false, 'cost_details' => ['upstream_inference_cost' => 0.01]], 1.0],
]);

it('reads image output tokens and 1-hour cache writes', function () {
    $tokens = (new ChatHandler)->tokensFromUsage([
        'prompt_tokens' => 1000,
        'completion_tokens' => 1300,
        'prompt_tokens_details' => ['cached_tokens' => 200, 'cache_write_tokens' => 300],
        'completion_tokens_details' => ['image_tokens' => 1290, 'reasoning_tokens' => 10],
        'cache_creation' => ['ephemeral_5m_input_tokens' => 100, 'ephemeral_1h_input_tokens' => 200],
    ]);

    expect($tokens->imageOutputTokens)->toBe(1290)
        ->and($tokens->cacheWriteTokens)->toBe(300)
        ->and($tokens->cacheWrite1hTokens)->toBe(200)
        ->and($tokens->reasoningTokens)->toBe(10);
});
