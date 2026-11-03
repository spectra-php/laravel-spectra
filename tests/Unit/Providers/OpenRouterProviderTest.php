<?php

declare(strict_types=1);

use Spectra\Providers\OpenRouter\Handlers\ChatHandler;
use Spectra\Providers\OpenRouter\Handlers\EmbeddingHandler;
use Spectra\Providers\OpenRouter\Handlers\ImageGenerationHandler;
use Spectra\Providers\OpenRouter\Handlers\ImageHandler;
use Spectra\Providers\OpenRouter\Handlers\RerankHandler;
use Spectra\Providers\OpenRouter\Handlers\VideoHandler;
use Spectra\Providers\OpenRouter\OpenRouter;

/**
 * The OpenRouter provider: handler resolution, the reported cost, image and
 * cache tokens, and skipped video jobs.
 */
it('resolves a handler for each openrouter endpoint', function (string $endpoint, array $body, string $handler) {
    expect((new OpenRouter)->resolveHandler($endpoint, $body))->toBeInstanceOf($handler);
})->with([
    'chat' => ['/api/v1/chat/completions', ['choices' => [['message' => ['content' => 'hi']]], 'usage' => []], ChatHandler::class],
    'chat with images' => ['/api/v1/chat/completions', ['choices' => [['message' => ['images' => [['image_url' => ['url' => 'data:image/png;base64,AA==']]]]]], 'usage' => []], ImageHandler::class],
    'image api' => ['/api/v1/images', [], ImageGenerationHandler::class],
    'video submit' => ['/api/v1/videos', [], VideoHandler::class],
    'video poll' => ['/api/v1/videos/gen-vid-1', [], VideoHandler::class],
    'embeddings' => ['/api/v1/embeddings', [], EmbeddingHandler::class],
    'rerank' => ['/api/v1/rerank', [], RerankHandler::class],
]);

it('does not track video downloads', function () {
    expect((new OpenRouter)->resolveHandler('/api/v1/videos/gen-vid-1/content'))->toBeNull();
});

it('reads the reported cost in cents', function (array $usage, ?float $expected) {
    $cost = (new ChatHandler)->reportedCostInCents($usage, []);

    $expected === null
        ? expect($cost)->toBeNull()
        : expect($cost)->toEqualWithDelta($expected, 0.0000001);
})->with([
    'plain' => [['cost' => 0.0249, 'is_byok' => false, 'cost_details' => ['upstream_inference_cost' => null]], 2.49],
    'byok adds the upstream cost' => [['cost' => 0.00005, 'is_byok' => true, 'cost_details' => ['upstream_inference_cost' => 0.001]], 0.105],
    'byok without the flag' => [['cost' => 0.95, 'cost_details' => ['upstream_inference_cost' => 19]], 1995.0],
    'not byok never adds upstream' => [['cost' => 0.01, 'is_byok' => false, 'cost_details' => ['upstream_inference_cost' => 0.01]], 1.0],
    'no cost' => [['prompt_tokens' => 10], null],
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

it('skips video jobs that are still running', function () {
    $handler = new VideoHandler;

    expect($handler->shouldSkipResponse(['status' => 'pending']))->toBeTrue()
        ->and($handler->shouldSkipResponse(['status' => 'in_progress']))->toBeTrue()
        ->and($handler->shouldSkipResponse(['status' => 'completed']))->toBeFalse()
        ->and($handler->shouldSkipResponse(['status' => 'failed']))->toBeFalse();
});
