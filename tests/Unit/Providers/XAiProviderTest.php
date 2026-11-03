<?php

declare(strict_types=1);

use Spectra\Data\Metrics;
use Spectra\Data\TokenMetrics;
use Spectra\Enums\ModelType;
use Spectra\Providers\XAi\Handlers\ChatHandler;
use Spectra\Providers\XAi\Handlers\ImageHandler;
use Spectra\Providers\XAi\Handlers\ResponsesHandler;
use Spectra\Providers\XAi\Handlers\VideoHandler;
use Spectra\Support\ProviderRegistry;

/**
 * The xAI provider: its hosts, handler resolution, served tier, reasoning
 * tokens and tool usage.
 */
it('returns xai as provider', function () {
    expect($this->xAiProvider()->getProvider())->toBe('xai');
});

it('returns correct display name', function () {
    expect(app(ProviderRegistry::class)->displayName('xai'))->toBe('xAI');
});

it('returns the global and US regional hosts', function () {
    expect($this->xAiProvider()->getHosts())->toBe(['api.x.ai', 'us.api.x.ai']);
});

it('resolves a handler for every documented endpoint', function (string $endpoint, string $handler) {
    expect($this->xAiProvider()->resolveHandler($endpoint))->toBeInstanceOf($handler);
})->with([
    ['/v1/chat/completions', ChatHandler::class],
    ['/v1/chat/deferred-completion/req-1', ChatHandler::class],
    ['/v1/responses', ResponsesHandler::class],
    ['/v1/images/generations', ImageHandler::class],
    ['/v1/images/edits', ImageHandler::class],
    ['/v1/images/req-1', ImageHandler::class],
    ['/v1/videos/generations', VideoHandler::class],
    ['/v1/videos/edits', VideoHandler::class],
    ['/v1/videos/extensions', VideoHandler::class],
    ['/v1/videos/req-1', VideoHandler::class],
]);

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

it('adds separately reported reasoning tokens to the completion', function () {
    $metrics = (new ChatHandler)->extractMetrics([], json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat-reasoning.json'), true));

    expect($metrics->tokens->completionTokens)->toBe(103)
        ->and($metrics->tokens->reasoningTokens)->toBe(94)
        ->and($metrics->reportedCostInCents)->toEqualWithDelta(0.02912, 1e-12);
});

it('maps server-side tool usage to tool pricing keys', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/responses-tools.json'), true);

    expect((new ResponsesHandler)->extractMetrics([], $response)->toolCalls)->toBe([
        'web_search_call' => 2,
        'x_search_call' => 1,
        'x_search_post' => 10,
        'x_search_profile' => 1,
    ]);
});

it('resolves chat handler for xai endpoint', function () {
    $handler = $this->xAiProvider()->resolveHandler('/v1/chat/completions');

    expect($handler)->toBeInstanceOf(ChatHandler::class);
});

it('chat handler returns text model type', function () {
    $handler = new ChatHandler;

    expect($handler->modelType())->toBe(ModelType::Text);
});

it('extracts usage from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;
    $metrics = $handler->extractMetrics([], $response);

    expect($metrics)->toBeInstanceOf(Metrics::class)
        ->and($metrics->tokens)->toBeInstanceOf(TokenMetrics::class)
        ->and($metrics->tokens->promptTokens)->toBe(10)
        ->and($metrics->tokens->completionTokens)->toBe(12)
        ->and($metrics->tokens->cachedTokens)->toBe(5);
});

it('extracts model from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractModelFromResponse($response))->toBe('grok-2');
});

it('extracts response content from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractResponse($response))->toContain('Grok');
});

it('extracts finish reason from xai response', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->extractFinishReason($response))->toBe('stop');
});

it('matches xai response shape', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $handler = new ChatHandler;

    expect($handler->matchesResponse($response))->toBeTrue();
});

it('extracts metrics via provider', function () {
    $response = json_decode(file_get_contents(__DIR__.'/../../responses/xai/chat.json'), true);

    $metrics = $this->xAiProvider()->extractMetrics($response, '/v1/chat/completions');

    expect($metrics->tokens->promptTokens)->toBe(10)
        ->and($metrics->tokens->completionTokens)->toBe(12)
        ->and($metrics->tokens->cachedTokens)->toBe(5);
});
