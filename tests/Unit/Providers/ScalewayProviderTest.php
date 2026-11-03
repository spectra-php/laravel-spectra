<?php

declare(strict_types=1);

use Spectra\Providers\Scaleway\Handlers\ChatHandler;
use Spectra\Providers\Scaleway\Handlers\TranscriptionHandler;
use Spectra\Support\Pricing\PricingLookup;

/**
 * The Scaleway provider: transcription duration, cached and reasoning
 * tokens from chat and Responses usage, and the dedicated-only models the
 * catalog dropped.
 */
it('extracts duration from scaleway transcription response', function () {
    $response = $this->loadMockResponse('scaleway/transcription.json');

    $metrics = (new TranscriptionHandler)->extractMetrics([], $response);

    expect($metrics->audio->durationSeconds)->toBe(4.2);
});

it('reads cached and reasoning tokens from chat and responses usage', function () {
    $handler = new ChatHandler;

    $chat = $handler->tokensFromUsage(['prompt_tokens' => 100, 'completion_tokens' => 20, 'prompt_tokens_details' => ['cached_tokens' => 40]]);
    $responses = $handler->tokensFromUsage(['input_tokens' => 100, 'output_tokens' => 20, 'input_tokens_details' => ['cached_tokens' => 30], 'output_tokens_details' => ['reasoning_tokens' => 5]]);

    expect($chat->cachedTokens)->toBe(40)
        ->and($responses->promptTokens)->toBe(100)
        ->and($responses->cachedTokens)->toBe(30)
        ->and($responses->reasoningTokens)->toBe(5);
});

it('no longer lists dedicated-only models', function (string $model) {
    expect(app(PricingLookup::class)->knows('scaleway', $model))->toBeFalse();
})->with(['mistral-large-3-675b-instruct-2512', 'gpt-oss-20b', 'minimax-m2.5', 'qwen3-235b-a22b-thinking-2507']);
