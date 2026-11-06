<?php

declare(strict_types=1);

/**
 * The dashboard's Playground: opening a stored call and sending it to its
 * provider again with edits, or writing a new prompt. A run is a real, tracked
 * call, so it needs the option and the manageSpectra gate, and always goes to a
 * stored call's endpoint or the provider's chat endpoint.
 */

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\RecordingSwitch;

beforeEach(function () {
    Gate::define('viewSpectra', fn ($user = null) => true);
    Gate::define('manageSpectra', fn ($user = null) => true);

    config()->set('spectra.dashboard.playground.enabled', true);
    config()->set('spectra.api_keys', ['openai' => 'sk-test-openai', 'anthropic' => 'sk-test-anthropic', 'google' => 'test-google']);
});

function storedChatCall(array $attributes = []): SpectraRequest
{
    return SpectraRequest::factory()->create([
        'provider' => 'openai',
        'model' => 'GPT-4o',
        'snapshot' => 'gpt-4o',
        'model_type' => 'text',
        'endpoint' => '/v1/chat/completions',
        'status_code' => 200,
        'request' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'Where is my order?']]],
        'response' => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'On its way.']]]],
        ...$attributes,
    ]);
}

function playgroundRun(SpectraRequest $call, array $input = []): TestResponse
{
    $input = ['model' => $call->request['model'] ?? 'gpt-4o', 'body' => $call->request, ...$input];

    return test()->postJson("/spectra/api/playground/{$call->id}/run", [
        ...$input,
        'body' => is_array($input['body']) ? json_encode($input['body'], JSON_THROW_ON_ERROR) : $input['body'],
    ]);
}

describe('access', function () {
    it('refuses to open or run a call while the option is off', function () {
        config()->set('spectra.dashboard.playground.enabled', false);
        Http::fake();
        $call = storedChatCall();

        $this->getJson("/spectra/api/playground/{$call->id}")->assertForbidden();
        playgroundRun($call)->assertForbidden();

        Http::assertNothingSent();
    });

    it('refuses to open or run a call without the manageSpectra gate', function () {
        Gate::define('manageSpectra', fn ($user = null) => false);
        Http::fake();
        $call = storedChatCall();

        $this->getJson("/spectra/api/playground/{$call->id}")->assertForbidden();
        playgroundRun($call)->assertForbidden();

        Http::assertNothingSent();
    });

    it('tells a viewer the playground is off without listing any calls', function () {
        config()->set('spectra.dashboard.playground.enabled', false);
        storedChatCall();

        $this->getJson('/spectra/api/playground')
            ->assertOk()
            ->assertJson(['enabled' => false, 'allowed' => false, 'calls' => []]);
    });

    it('links every call page to the playground, which says why it can not run a call', function () {
        $call = SpectraRequest::factory()->create(['model_type' => 'image', 'endpoint' => '/v1/images/generations', 'request' => null]);

        $this->getJson("/spectra/api/requests/{$call->id}")->assertJson(['can_open_in_playground' => true]);

        $this->getJson("/spectra/api/playground/{$call->id}")
            ->assertOk()
            ->assertJsonPath('unavailable_reason', fn (string $reason) => str_contains($reason, 'chat endpoint'));
    });

    it('offers the playground on a call page only when the user may run it', function () {
        $call = storedChatCall();

        $this->getJson("/spectra/api/requests/{$call->id}")->assertJson(['can_open_in_playground' => true]);

        Gate::define('manageSpectra', fn ($user = null) => false);

        $this->getJson("/spectra/api/requests/{$call->id}")->assertJson(['can_open_in_playground' => false]);
    });
});

describe('opening a call', function () {
    it('lists recent chat calls and leaves out embeddings and the playground runs', function () {
        $chat = storedChatCall();
        storedChatCall(['model_type' => 'embedding', 'endpoint' => '/v1/embeddings']);
        storedChatCall(['request' => null]);
        Http::fake(['*' => Http::response($this->loadMockResponse('openai/chat_completion_cache_writes.json'))]);
        playgroundRun($chat)->assertOk();

        $calls = $this->getJson('/spectra/api/playground')->assertOk()->json('calls');

        expect($calls)->toHaveCount(1)
            ->and($calls[0]['id'])->toBe($chat->id)
            ->and($calls[0]['prompt'])->toBe('Where is my order?');
    });

    it('opens a call with its body, reply and the provider\'s models', function () {
        $call = storedChatCall();

        $response = $this->getJson("/spectra/api/playground/{$call->id}")->assertOk();

        expect($response->json('body.messages.0.content'))->toBe('Where is my order?')
            ->and($response->json('model'))->toBe('gpt-4o')
            ->and($response->json('call.reply'))->toBe('On its way.')
            ->and($response->json('models'))->toContain('gpt-4o')
            ->and($response->json('models'))->not->toContain('claude-opus-4-8')
            ->and($response->json('unavailable_reason'))->toBeNull();
    });

    it('opens and lists a call that failed before it got a model type', function () {
        $call = storedChatCall(['model_type' => null, 'status_code' => 429, 'error_message' => 'Rate limit reached', 'response' => null]);

        $opened = $this->getJson("/spectra/api/playground/{$call->id}")->assertOk();

        expect($opened->json('unavailable_reason'))->toBeNull()
            ->and($opened->json('call.error_message'))->toBe('Rate limit reached')
            ->and($this->getJson('/spectra/api/playground')->json('calls.0.id'))->toBe($call->id)
            ->and($this->getJson("/spectra/api/requests/{$call->id}")->json('can_open_in_playground'))->toBeTrue();
    });

    it('reads a gemini model from the endpoint, since its body names none', function () {
        $call = storedChatCall([
            'provider' => 'google',
            'endpoint' => '/v1beta/models/gemini-2.5-flash:generateContent',
            'request' => ['contents' => [['role' => 'user', 'parts' => [['text' => 'Hi']]]]],
        ]);

        $this->getJson("/spectra/api/playground/{$call->id}")->assertJson(['model' => 'gemini-2.5-flash']);
    });

    it('says what a stored body has lost', function (array $attributes, array $config, string $expected) {
        config()->set($config);
        $call = storedChatCall($attributes);

        $notices = $this->getJson("/spectra/api/playground/{$call->id}")->json('notices');

        expect(implode(' ', $notices))->toContain($expected);
    })->with([
        'redacted text' => [['request' => ['messages' => [['role' => 'user', 'content' => 'Mail [REDACTED:email]']]]], [], 'Redaction masked'],
        'inline files' => [['request' => ['messages' => [['role' => 'user', 'content' => [['type' => 'image_url', 'image_url' => ['url' => '[stripped]']]]]]]], [], 'inline files'],
        'system prompts off' => [[], ['spectra.storage.store_system_prompts' => false], 'System prompts aren\'t stored'],
        'tool calls without tools' => [['has_tool_calls' => true], [], 'Tool definitions aren\'t stored'],
        'a streamed call' => [['is_streaming' => true], [], 'was streamed'],
        'an app that also uses azure' => [[], ['spectra.endpoint_urls.azure' => 'https://acme.openai.azure.com'], 'Azure OpenAI'],
    ]);

    it('says where a run will go', function () {
        config()->set('spectra.endpoint_urls.openai', 'http://llm-proxy.test:8080/v1');
        $call = storedChatCall();

        $this->getJson("/spectra/api/playground/{$call->id}")->assertJson(['base_url' => 'http://llm-proxy.test:8080']);
    });

    it('explains why a call cannot run', function (array $attributes, array $config, string $expected) {
        config()->set($config);
        $call = storedChatCall($attributes);

        $this->getJson("/spectra/api/playground/{$call->id}")->assertJsonPath('unavailable_reason', fn (string $reason) => str_contains($reason, $expected));
    })->with([
        'not a chat endpoint' => [['endpoint' => '/v1/embeddings'], [], 'chat endpoint'],
        'not a text call' => [['model_type' => 'image', 'endpoint' => '/v1/images/generations'], [], 'chat endpoint'],
        'a custom provider' => [['provider' => 'acme'], [], 'chat endpoint'],
        'no stored body' => [['request' => null], [], 'wasn\'t stored'],
        'no api key' => [[], ['spectra.api_keys' => []], 'No API key'],
    ]);
});

describe('running a call', function () {
    it('sends each provider its own host and key header', function (array $attributes, string $url, string $header, string $value) {
        Http::fake(['*' => Http::response(['id' => 'x'])]);
        config()->set('spectra.api_keys.groq', 'test-groq');
        $call = storedChatCall($attributes);

        playgroundRun($call, ['model' => 'some-model'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === $url && $request->hasHeader($header, $value));
    })->with([
        'openai' => [[], 'https://api.openai.com/v1/chat/completions', 'Authorization', 'Bearer sk-test-openai'],
        'anthropic' => [['provider' => 'anthropic', 'endpoint' => '/v1/messages'], 'https://api.anthropic.com/v1/messages', 'x-api-key', 'sk-test-anthropic'],
        'groq' => [['provider' => 'groq', 'endpoint' => '/openai/v1/chat/completions'], 'https://api.groq.com/openai/v1/chat/completions', 'Authorization', 'Bearer test-groq'],
        'google' => [['provider' => 'google', 'endpoint' => '/v1beta/models/gemini-2.5-flash:generateContent'], 'https://generativelanguage.googleapis.com/v1beta/models/some-model:generateContent', 'x-goog-api-key', 'test-google'],
    ]);

    it('uses only the scheme and host of a configured base url', function () {
        Http::fake(['*' => Http::response(['id' => 'x'])]);
        config()->set('spectra.endpoint_urls.openai', 'http://llm-proxy.test:8080/v1');

        playgroundRun(storedChatCall())->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://llm-proxy.test:8080/v1/chat/completions');
    });

    it('sends a call to its stored endpoint whatever the input says', function () {
        Http::fake(['*' => Http::response(['id' => 'x'])]);

        playgroundRun(storedChatCall(), ['endpoint' => '/v1/files', 'provider' => 'anthropic', 'url' => 'https://evil.test'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/chat/completions');
    });

    it('sends the edited model and body with streaming turned off', function () {
        Http::fake(['*' => Http::response(['id' => 'x'])]);
        $call = storedChatCall();

        playgroundRun($call, [
            'model' => 'gpt-4o-mini',
            'body' => ['model' => 'gpt-4o', 'stream' => true, 'stream_options' => ['include_usage' => true], 'messages' => [['role' => 'user', 'content' => 'Edited']]],
        ])->assertOk();

        Http::assertSent(fn (Request $request) => $request['model'] === 'gpt-4o-mini'
            && $request['messages'][0]['content'] === 'Edited'
            && ! isset($request['stream'])
            && ! isset($request['stream_options']));
    });

    it('asks ollama not to stream, since it streams by default, and sends no key', function () {
        Http::fake(['*' => Http::response(['model' => 'llama3'])]);
        $call = storedChatCall(['provider' => 'ollama', 'endpoint' => '/api/chat']);

        playgroundRun($call, ['model' => 'llama3'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'http://localhost:11434/api/chat'
            && $request['stream'] === false
            && ! $request->hasHeader('Authorization'));
    });

    it('calls gemini without streaming and leaves the model out of the body', function () {
        Http::fake(['*' => Http::response($this->loadMockResponse('google/response.json'))]);
        $call = storedChatCall(['provider' => 'google', 'endpoint' => '/v1beta/models/gemini-2.5-flash:streamGenerateContent']);

        playgroundRun($call, ['model' => 'gemini-2.5-pro'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent'
            && ! isset($request['model']));
    });

    it('cannot leave the gemini models path through the model name', function () {
        Http::fake(['*' => Http::response(['id' => 'x'])]);
        $call = storedChatCall(['provider' => 'google', 'endpoint' => '/v1beta/models/gemini-2.5-flash:generateContent']);

        playgroundRun($call, ['model' => '../../v1/files?x='])->assertOk();

        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/v1beta/models/..%2F..%2Fv1%2Ffiles%3Fx%3D:generateContent'));
    });

    it('sends empty json objects as objects, though the database gave back lists', function () {
        Http::fake(['*' => Http::response(['id' => 'x'])]);
        $call = storedChatCall();

        playgroundRun($call, ['body' => [
            'messages' => [
                ['role' => 'user', 'content' => []],
                ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'a', 'name' => 'ping', 'input' => []]]],
            ],
            'tools' => [['type' => 'function', 'function' => ['name' => 'ping', 'parameters' => ['type' => 'object', 'properties' => []]]]],
        ]])->assertOk();

        Http::assertSent(function (Request $request) {
            $sent = $request->body();

            return str_contains($sent, '"properties":{}') && str_contains($sent, '"input":{}') && str_contains($sent, '"content":[]');
        });
    });

    it('records a run as a priced call tagged playground that points at its source', function () {
        Http::fake(['*' => Http::response($this->loadMockResponse('openai/chat_completion_cache_writes.json'))]);
        $source = storedChatCall();

        $result = playgroundRun($source)->assertOk();

        $run = SpectraRequest::query()->with('tags')->findOrFail($result->json('request_id'));

        expect($result->json('succeeded'))->toBeTrue()
            ->and($result->json('prompt_tokens'))->toBeGreaterThan(0)
            ->and($result->json('total_cost_in_cents'))->toBeGreaterThan(0)
            ->and($result->json('total_cost_in_cents'))->toEqualWithDelta($run->total_cost_in_cents, 0.000001)
            ->and($result->json('latency_ms'))->not->toBeNull()
            ->and($run->id)->not->toBe($source->id)
            ->and($run->tags->pluck('name')->all())->toBe(['playground'])
            ->and($run->metadata)->toEqual(['playground_source' => $source->id]);
    });

    it('answers with the reply text read by the provider\'s handler', function () {
        Http::fake(['*' => Http::response(['id' => 'msg', 'model' => 'claude', 'type' => 'message', 'content' => [['type' => 'text', 'text' => 'Hello there']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 2]])]);
        $call = storedChatCall(['provider' => 'anthropic', 'endpoint' => '/v1/messages']);

        playgroundRun($call, ['model' => 'claude-opus-4-8'])->assertOk()->assertJson(['reply' => 'Hello there']);
    });

    it('says when the model asked for a tool instead of replying', function () {
        Http::fake(['*' => Http::response(['id' => 'x', 'model' => 'gpt-4o', 'choices' => [['message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'a', 'type' => 'function', 'function' => ['name' => 'lookup_order', 'arguments' => '{}']]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]])]);

        $result = playgroundRun(storedChatCall())->assertOk();

        expect($result->json('reply'))->toBeNull()
            ->and($result->json('has_tool_calls'))->toBeTrue()
            ->and($result->json('finish_reason'))->toBe('tool_calls')
            ->and($result->json('response.choices.0.message.tool_calls.0.function.name'))->toBe('lookup_order');
    });

    it('answers a provider error as a failed result and records it', function () {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Unknown model', 'type' => 'invalid_request_error']], 400)]);

        $result = playgroundRun(storedChatCall())->assertOk();

        expect($result->json('succeeded'))->toBeFalse()
            ->and($result->json('status_code'))->toBe(400)
            ->and($result->json('error_message'))->toContain('Unknown model')
            ->and(SpectraRequest::query()->findOrFail($result->json('request_id'))->status_code)->toBe(400);
    });

    it('answers an unreachable provider as a failed result', function () {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $result = playgroundRun(storedChatCall())->assertOk();

        expect($result->json('succeeded'))->toBeFalse()
            ->and($result->json('status_code'))->toBeNull()
            ->and($result->json('error_message'))->toContain('Connection refused');
    });

    it('still answers while recording is paused, with no row to link to', function () {
        Http::fake(['*' => Http::response($this->loadMockResponse('openai/chat_completion_cache_writes.json'))]);
        RecordingSwitch::pause();
        $call = storedChatCall();

        $result = playgroundRun($call)->assertOk();

        RecordingSwitch::resume();

        expect($result->json('succeeded'))->toBeTrue()
            ->and($result->json('total_cost_in_cents'))->toBeGreaterThan(0)
            ->and($result->json('request_id'))->toBeNull()
            ->and(SpectraRequest::query()->count())->toBe(1);
    });

    it('refuses a call that cannot run and sends nothing', function () {
        Http::fake();
        config()->set('spectra.api_keys', []);

        playgroundRun(storedChatCall())->assertStatus(422)->assertJsonPath('message', fn (string $message) => str_contains($message, 'No API key'));

        Http::assertNothingSent();
    });

    it('rejects a body that is not a json object', function (string $body) {
        Http::fake();

        playgroundRun(storedChatCall(), ['body' => $body])->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    })->with([
        'not json' => ['not json'],
        'a list' => ['[1, 2]'],
        'a string' => ['"text"'],
    ]);

    it('sends the prompt text byte for byte, spaces included', function () {
        Http::fake(['*' => Http::response(['id' => 'x'])]);

        playgroundRun(storedChatCall(), ['body' => ['messages' => [['role' => 'user', 'content' => "  Keep my spaces\n\n"], ['role' => 'assistant', 'content' => '']]]])->assertOk();

        Http::assertSent(fn (Request $request) => $request['messages'][0]['content'] === "  Keep my spaces\n\n" && $request['messages'][1]['content'] === '');
    });
});

describe('a new prompt', function () {
    function runNewPrompt(array $input = []): TestResponse
    {
        $input = ['provider' => 'openai', 'model' => 'gpt-4o', 'body' => ['messages' => [['role' => 'user', 'content' => 'Hello']]], ...$input];

        return test()->postJson('/spectra/api/playground/run', [
            ...$input,
            'body' => is_array($input['body']) ? json_encode($input['body'], JSON_THROW_ON_ERROR) : $input['body'],
        ]);
    }

    it('refuses to open or run while the option is off or without the gate', function () {
        Http::fake();
        config()->set('spectra.dashboard.playground.enabled', false);

        $this->getJson('/spectra/api/playground/new')->assertForbidden();
        runNewPrompt()->assertForbidden();

        config()->set('spectra.dashboard.playground.enabled', true);
        Gate::define('manageSpectra', fn ($user = null) => false);

        $this->getJson('/spectra/api/playground/new')->assertForbidden();
        runNewPrompt()->assertForbidden();

        Http::assertNothingSent();
    });

    it('starts from the provider asked for, with its endpoint and models', function () {
        $response = $this->getJson('/spectra/api/playground/new?provider=anthropic')->assertOk();

        expect($response->json('call'))->toBeNull()
            ->and($response->json('provider'))->toBe('anthropic')
            ->and($response->json('endpoint'))->toBe('/v1/messages')
            ->and($response->json('body.max_tokens'))->toBe(1024)
            ->and($response->json('models'))->toContain('claude-opus-4-8')
            ->and($response->json('base_url'))->toBe('https://api.anthropic.com');
    });

    it('starts from the provider and model the app used last, when it has a key', function () {
        storedChatCall(['provider' => 'google', 'endpoint' => '/v1beta/models/gemini-2.5-flash:generateContent', 'snapshot' => 'gemini-2.5-flash', 'created_at' => now()->subHour()]);
        storedChatCall(['provider' => 'groq', 'endpoint' => '/openai/v1/chat/completions', 'snapshot' => 'llama-x', 'created_at' => now()]);

        $response = $this->getJson('/spectra/api/playground/new')->assertOk();

        expect($response->json('provider'))->toBe('google')
            ->and($response->json('model'))->toBe('gemini-2.5-flash')
            ->and(collect($response->json('providers'))->firstWhere('slug', 'groq')['has_key'])->toBeFalse()
            ->and(collect($response->json('providers'))->firstWhere('slug', 'google')['has_key'])->toBeTrue();
    });

    it('starts from a provider with a key when the app has no calls yet', function () {
        config()->set('spectra.api_keys', ['mistral' => 'test-mistral']);

        $this->getJson('/spectra/api/playground/new')->assertJson(['provider' => 'mistral', 'model' => '', 'unavailable_reason' => null]);
    });

    it('says when the provider has no key', function () {
        $this->getJson('/spectra/api/playground/new?provider=groq')->assertJsonPath('unavailable_reason', fn (string $reason) => str_contains($reason, 'No API key'));
    });

    it('rejects a provider it cannot send to', function (string $provider) {
        Http::fake();

        $this->getJson("/spectra/api/playground/new?provider={$provider}")->assertJsonValidationErrors('provider');
        runNewPrompt(['provider' => $provider])->assertJsonValidationErrors('provider');

        Http::assertNothingSent();
    })->with([
        'a custom provider' => ['acme'],
        'one with no chat api' => ['elevenlabs'],
    ]);

    it('sends a new prompt to the provider\'s chat endpoint whatever the input says', function (string $provider, string $url) {
        Http::fake(['*' => Http::response(['id' => 'x'])]);

        runNewPrompt(['provider' => $provider, 'endpoint' => '/v1/files', 'url' => 'https://evil.test'])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === $url);
    })->with([
        'openai' => ['openai', 'https://api.openai.com/v1/chat/completions'],
        'anthropic' => ['anthropic', 'https://api.anthropic.com/v1/messages'],
    ]);

    it('puts the model in the gemini endpoint', function () {
        Http::fake(['*' => Http::response($this->loadMockResponse('google/response.json'))]);

        runNewPrompt(['provider' => 'google', 'model' => 'gemini-2.5-pro', 'body' => ['contents' => [['role' => 'user', 'parts' => [['text' => 'Hello']]]]]])->assertOk();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-pro:generateContent');
    });

    it('records a new prompt as a priced call tagged playground with no source', function () {
        Http::fake(['*' => Http::response($this->loadMockResponse('openai/chat_completion_cache_writes.json'))]);

        $result = runNewPrompt()->assertOk();

        $run = SpectraRequest::query()->with('tags')->findOrFail($result->json('request_id'));

        expect($result->json('succeeded'))->toBeTrue()
            ->and($run->total_cost_in_cents)->toBeGreaterThan(0)
            ->and($run->tags->pluck('name')->all())->toBe(['playground'])
            ->and($run->metadata)->toBeNull();
    });

    it('refuses a new prompt to a provider with no key and sends nothing', function () {
        Http::fake();

        runNewPrompt(['provider' => 'groq'])->assertStatus(422);

        Http::assertNothingSent();
    });

    it('rejects a body that is not a json object', function () {
        Http::fake();

        runNewPrompt(['body' => '[1, 2]'])->assertJsonValidationErrors('body');

        Http::assertNothingSent();
    });
});
