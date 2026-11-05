<?php

declare(strict_types=1);

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\File;
use OpenAI\Client;
use OpenAI\Laravel\Facades\OpenAI;
use Spectra\Models\SpectraRequest;
use Spectra\Watchers\OpenAiWatcher;

/**
 * The openai-php/laravel SDK tracked through OpenAiWatcher, which binds a
 * client whose own Guzzle stack carries the tracking middleware. The watcher
 * builds that stack on Guzzle's default (curl) handler with no way to inject
 * another, so the stack's terminal handler is swapped for a MockHandler and
 * the very client the watcher bound answers from fixtures: a chat completion
 * is recorded once with its cost, a stream once it has been iterated, and a
 * custom base URI still records under openai.
 */
function registerTrackedOpenAiSdk(MockHandler $responses): void
{
    config(['openai.api_key' => 'sk-test-spectra']);

    (new OpenAiWatcher)->register();

    $client = app(Client::class);
    $transporter = (new ReflectionProperty($client, 'transporter'))->getValue($client);
    $http = (new ReflectionProperty($transporter, 'client'))->getValue($transporter);

    $http->getConfig('handler')->setHandler($responses);
}

function openAiSdkJsonResponse(array $body): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode($body, JSON_THROW_ON_ERROR));
}

it('records a chat completion made through the OpenAI facade once with its model, tokens and cost', function () {
    $responses = new MockHandler([openAiSdkJsonResponse($this->loadMockResponse('openai/completion.json'))]);
    registerTrackedOpenAiSdk($responses);

    $result = OpenAI::chat()->create([
        'model' => 'gpt-4.1',
        'messages' => [['role' => 'user', 'content' => 'Hello']],
    ]);

    $record = SpectraRequest::query()->sole();

    // gpt-4.1 standard (in 200, out 800): (19×200 + 10×800) / 1M = 0.0118¢
    expect($result->usage->promptTokens)->toBe(19)
        ->and($responses->getLastRequest()->getUri()->getHost())->toBe('api.openai.com')
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4.1')
        ->and($record->endpoint)->toBe('/v1/chat/completions')
        ->and($record->is_streaming)->toBeFalse()
        ->and($record->prompt_tokens)->toBe(19)
        ->and($record->completion_tokens)->toBe(10)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0118, 1e-9);
});

it('records a streamed chat completion made through the SDK once the stream has been iterated', function () {
    $body = File::get(__DIR__.'/../../responses/openai/chat_stream_with_usage.sse');
    registerTrackedOpenAiSdk(new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], $body)]));

    $stream = OpenAI::chat()->createStreamed([
        'model' => 'gpt-5.4-mini',
        'messages' => [['role' => 'user', 'content' => 'Hi']],
    ]);

    expect(SpectraRequest::query()->count())->toBe(0);

    $content = '';
    foreach ($stream as $chunk) {
        $content .= $chunk->choices[0]->delta->content ?? '';
    }

    $record = SpectraRequest::query()->sole();

    // Served at service_tier "flex": gpt-5.4-mini flex in 37.5, cached 3.75, out 225.
    // (1000×37.5 + 2000×3.75 + 400×225) / 1M = 0.135¢
    expect($content)->toBe('Hi there')
        ->and($record->provider)->toBe('openai')
        ->and($record->is_streaming)->toBeTrue()
        ->and($record->pricing_tier)->toBe('flex')
        ->and($record->prompt_tokens)->toBe(3000)
        ->and($record->completion_tokens)->toBe(400)
        ->and($record->cached_tokens)->toBe(2000)
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.135, 1e-9);
});

it('records SDK calls sent to a custom base URI under openai', function () {
    config(['openai.base_uri' => 'https://ai-proxy.example.test/v1']);
    $responses = new MockHandler([openAiSdkJsonResponse($this->loadMockResponse('openai/completion.json'))]);
    registerTrackedOpenAiSdk($responses);

    OpenAI::chat()->create(['model' => 'gpt-4.1', 'messages' => [['role' => 'user', 'content' => 'Hello']]]);

    $record = SpectraRequest::query()->sole();

    // gpt-4.1 standard (in 200, out 800): (19×200 + 10×800) / 1M = 0.0118¢
    expect((string) $responses->getLastRequest()->getUri())->toBe('https://ai-proxy.example.test/v1/chat/completions')
        ->and($record->provider)->toBe('openai')
        ->and($record->model)->toBe('GPT-4.1')
        ->and((float) $record->total_cost_in_cents)->toEqualWithDelta(0.0118, 1e-9);
});
