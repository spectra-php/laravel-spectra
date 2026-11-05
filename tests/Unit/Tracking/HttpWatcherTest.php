<?php

declare(strict_types=1);

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Spectra\Models\SpectraRequest;
use Spectra\SpectraServiceProvider;
use Spectra\Watchers\HttpWatcher;

/**
 * HttpWatcher tracks HTTP client calls to provider hosts only, once however
 * often it is registered, and never a call marked withoutAITracking().
 */
it('tracks http client requests to provider hosts and ignores other hosts', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(['id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]]),
        'example.com/*' => Http::response(['ok' => true]),
    ]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'messages' => []]);
    Http::post('https://example.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::query()->count())->toBe(1)
        ->and(SpectraRequest::query()->first()->provider)->toBe('openai');
});

it('registers its middleware on the http client only once', function () {
    (new HttpWatcher)->register();
    (new HttpWatcher)->register();

    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-2', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::query()->count())->toBe(1);
});

it('does not track requests marked withoutAITracking()', function () {
    Http::fake(['api.openai.com/*' => Http::response(['id' => 'chatcmpl-3', 'model' => 'gpt-4o', 'choices' => []])]);

    Http::withoutAITracking()->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::query()->count())->toBe(0);
});

it('keeps the macros available, recording nothing, while Spectra is disabled', function () {
    Http::flushMacros();
    PendingRequest::flushMacros();
    config(['spectra.enabled' => false]);
    Http::fake(['proxy.example.com/*' => Http::response(['id' => 'chatcmpl-4', 'model' => 'gpt-4o', 'choices' => []])]);

    (new SpectraServiceProvider($this->app))->boot();
    Http::withAITracking('openai', 'gpt-4o')->post('https://proxy.example.com/v1/chat/completions', ['model' => 'gpt-4o']);
    Http::withoutAITracking()->post('https://proxy.example.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(Http::hasMacro('withAITracking'))->toBeTrue()
        ->and(SpectraRequest::query()->count())->toBe(0);
});

it('offers both macros on a pending request, after withHeaders() and the like', function () {
    Http::fake([
        'proxy.example.com/*' => Http::response(['id' => 'chatcmpl-5', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]]),
        'api.openai.com/*' => Http::response(['id' => 'chatcmpl-6', 'model' => 'gpt-4o', 'choices' => []]),
    ]);

    Http::withHeaders(['X-Test' => '1'])->withAITracking('openai', 'gpt-4o')->post('https://proxy.example.com/v1/chat/completions', ['model' => 'gpt-4o']);
    Http::withHeaders(['X-Test' => '1'])->withoutAITracking()->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $tracked = SpectraRequest::query()->sole();

    expect($tracked->provider)->toBe('openai')
        ->and($tracked->prompt_tokens)->toBe(3);
});
