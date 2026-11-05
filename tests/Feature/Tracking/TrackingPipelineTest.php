<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\PumpStream;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\StatsAggregator;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;
use Spectra\Support\Tracking\StreamEventParser;
use Workbench\App\Models\User;

/**
 * End-to-end behaviour of the tracking pipeline as real apps hit it: through
 * Laravel's HTTP client, with the HttpWatcher installed as global middleware.
 */
function openAiChunk(array $data): string
{
    return 'data: '.json_encode($data, JSON_THROW_ON_ERROR)."\n\n";
}

function openAiStreamBody(): string
{
    return openAiChunk(['id' => 'chatcmpl-s1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]]])
        .openAiChunk(['id' => 'chatcmpl-s1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo'], 'finish_reason' => 'stop']]])
        .openAiChunk(['id' => 'chatcmpl-s1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [], 'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 2, 'prompt_tokens_details' => ['cached_tokens' => 4]]])
        ."data: [DONE]\n\n";
}

/**
 * A body that can only be read once, like a real network stream, and that
 * throws like a dropped connection after $breakAfter parts when given.
 */
function oneShotBody(string $contents, int $chunk = 32, ?int $breakAfter = null): PumpStream
{
    $parts = str_split($contents, $chunk);
    $served = 0;

    return new PumpStream(function () use (&$parts, &$served, $breakAfter) {
        throw_if($breakAfter !== null && $served >= $breakAfter, RuntimeException::class, 'connection reset mid-stream');
        $served++;

        return array_shift($parts) ?? false;
    });
}

/** Fakes every request with a one-shot body that breaks off after $breakAfter parts of 32 bytes. */
function fakeBrokenResponse(string $body, int $breakAfter, string $contentType = 'text/event-stream', int $status = 200): void
{
    Http::fake(['*' => Http::response(oneShotBody($body, 32, $breakAfter), $status, ['Content-Type' => $contentType])]);
}

/** Read a PSR body to its end, returning the exception that stopped it, if any. */
function readToEnd(Response $response): ?Throwable
{
    $body = $response->toPsrResponse()->getBody();

    try {
        while (! $body->eof()) {
            $body->read(32);
        }
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

/** Fakes every request with a one-shot body, so tracking must not consume it. */
function fakeOneShotResponse(string $body, string $contentType): void
{
    Http::fake(['*' => Http::response(oneShotBody($body), 200, ['Content-Type' => $contentType])]);
}

function streamedRequest(string $body, string $contentType = 'text/event-stream'): Response
{
    fakeOneShotResponse($body, $contentType);

    return Http::withOptions(['stream' => true])
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true, 'stream_options' => ['include_usage' => true]]);
}

it('records a streamed response once the app has read it, without consuming it', function () {
    $response = streamedRequest(openAiStreamBody());

    expect(SpectraRequest::query()->count())->toBe(0);

    $body = $response->body();

    expect($body)->toBe(openAiStreamBody());

    $row = SpectraRequest::query()->sole();
    expect($row->is_streaming)->toBeTrue()
        ->and($row->prompt_tokens)->toBe(12)
        ->and($row->completion_tokens)->toBe(2)
        ->and($row->cached_tokens)->toBe(4)
        ->and($row->response_id)->toBe('chatcmpl-s1')
        ->and($row->time_to_first_token_ms)->not->toBeNull();
});

it('records a stream the app stops reading early', function () {
    $response = streamedRequest(openAiStreamBody());

    $response->toPsrResponse()->getBody()->read(64);
    unset($response);
    gc_collect_cycles();

    expect(SpectraRequest::query()->count())->toBe(1)
        ->and(SpectraRequest::query()->first()->is_streaming)->toBeTrue();
});

it('records a stream that breaks off as a failed request with its status and the usage read so far', function () {
    fakeBrokenResponse(openAiStreamBody(), breakAfter: 3);
    $response = Http::withOptions(['stream' => true])->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true]);

    $exception = readToEnd($response);
    unset($response);
    gc_collect_cycles();

    $row = SpectraRequest::query()->sole();

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($row->is_streaming)->toBeTrue()
        ->and($row->status_code)->toBe(200)
        ->and($row->error_type)->toBe(RuntimeException::class)
        ->and($row->error_message)->toBe('connection reset mid-stream')
        ->and($row->error_fingerprint)->not->toBeNull()
        ->and($row->isFailed())->toBeTrue()
        ->and(SpectraRequest::query()->failed()->count())->toBe(1);
});

it('records a one-pass body that breaks off as a failed request, not as an empty answer', function () {
    fakeBrokenResponse(json_encode($this->loadMockResponse('openai/completion.json'), JSON_THROW_ON_ERROR), breakAfter: 2, contentType: 'application/json');
    $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);

    $exception = readToEnd($response);
    unset($response);
    gc_collect_cycles();

    $row = SpectraRequest::query()->sole();

    expect($exception)->toBeInstanceOf(RuntimeException::class)
        ->and($row->status_code)->toBe(200)
        ->and($row->error_type)->toBe(RuntimeException::class)
        ->and($row->prompt_tokens)->toBe(0)
        ->and($row->isFailed())->toBeTrue();
});

it('leaves an unread tracked stream to its own tracker when an unrelated manual stream is tracked', function () {
    $unread = streamedRequest(openAiStreamBody());

    $tracker = Spectra::stream('anthropic', 'claude-sonnet-4-5');
    foreach ($tracker->track([
        ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'model' => 'claude-sonnet-4-5', 'usage' => ['input_tokens' => 7]]],
        ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Hey']],
        ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 5]],
    ]) as $text) {
        //
    }
    $manual = $tracker->finish();

    $unread->body();
    unset($unread);
    gc_collect_cycles();

    $rows = SpectraRequest::query()->orderBy('provider')->get();

    expect($manual->provider)->toBe('anthropic')
        ->and($manual->prompt_tokens)->toBe(7)
        ->and($rows)->toHaveCount(2)
        ->and($rows->pluck('provider')->all())->toBe(['anthropic', 'openai'])
        ->and($rows->last()->endpoint)->toBe('/v1/chat/completions')
        ->and($rows->last()->completion_tokens)->toBe(2);
});

/** Wrap a tracked stream's body in Spectra::stream(), as an app with its own SSE parser would. */
function wrapStreamManually(Response $response): array
{
    $tracker = Spectra::stream();
    $events = (function () use ($response) {
        $body = $response->toPsrResponse()->getBody();
        $parser = new StreamEventParser('text/event-stream');
        while (! $body->eof()) {
            yield from $parser->push($body->read(32));
        }
    })();

    return [$tracker, function () use ($tracker, $events) {
        foreach ($tracker->track($events) as $text) {
            //
        }
    }];
}

it('records a wrapped stream that breaks off before finish() when the tracker is released', function () {
    // The first event is 128 bytes: six parts of 32 deliver it before the break.
    fakeBrokenResponse(openAiStreamBody(), breakAfter: 6);
    $response = Http::withOptions(['stream' => true])->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true]);
    [$tracker, $consume] = wrapStreamManually($response);

    expect($consume)->toThrow(RuntimeException::class, 'connection reset mid-stream')
        ->and($tracker->getContext()->streamClaimed)->toBeTrue()
        ->and(SpectraRequest::query()->count())->toBe(0);

    unset($tracker, $response, $consume);
    gc_collect_cycles();

    $row = SpectraRequest::query()->sole();

    expect($row->provider)->toBe('openai')
        ->and($row->endpoint)->toBe('/v1/chat/completions')
        ->and($row->status_code)->toBe(200)
        ->and($row->error_type)->toBe(RuntimeException::class)
        ->and($row->isFailed())->toBeTrue();
});

it('records a wrapped stream that breaks off before its first event once, through the middleware', function () {
    fakeBrokenResponse(openAiStreamBody(), breakAfter: 3);
    $response = Http::withOptions(['stream' => true])->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true]);
    [$tracker, $consume] = wrapStreamManually($response);

    expect($consume)->toThrow(RuntimeException::class, 'connection reset mid-stream');

    unset($tracker, $response, $consume);
    gc_collect_cycles();

    $row = SpectraRequest::query()->sole();

    expect($row->provider)->toBe('openai')
        ->and($row->error_type)->toBe(RuntimeException::class)
        ->and($row->is_streaming)->toBeTrue();
});

it('records a stream once when the app also wraps it in Spectra::stream()', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o');
    $response = streamedRequest(openAiStreamBody());

    $text = implode('', iterator_to_array($tracker->track($response->body()), false));
    $tracker->finish();
    $tracker->finish();

    expect($text)->toBe('Hello')
        ->and(SpectraRequest::query()->count())->toBe(1)
        ->and(SpectraRequest::query()->first()->completion_tokens)->toBe(2);
});

it('tracks newline-delimited JSON streams (Ollama)', function () {
    $ndjson = json_encode(['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => 'Hi'], 'done' => false], JSON_THROW_ON_ERROR)."\n"
        .json_encode(['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 7, 'eval_count' => 3], JSON_THROW_ON_ERROR)."\n";

    fakeOneShotResponse($ndjson, 'application/x-ndjson');

    $response = Http::post('http://localhost:11434/api/chat', ['model' => 'llama3', 'messages' => []]);

    expect($response->body())->toBe($ndjson);

    $row = SpectraRequest::query()->sole();
    expect($row->prompt_tokens)->toBe(7)
        ->and($row->completion_tokens)->toBe(3)
        ->and($row->is_streaming)->toBeTrue();
});

it('records a stream error event as a failure', function () {
    $body = openAiChunk(['id' => 'chatcmpl-e1', 'object' => 'chat.completion.chunk', 'model' => 'gpt-4o', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hi']]]])
        .openAiChunk(['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']]);

    streamedRequest($body)->body();

    $row = SpectraRequest::query()->sole();
    expect($row->error_message)->toBe('Overloaded')
        ->and($row->error_type)->toContain('StreamErrorException');
});

it('reads one-pass JSON bodies only as the app reads them', function () {
    fakeOneShotResponse(json_encode($this->loadMockResponse('openai/completion.json'), JSON_THROW_ON_ERROR), 'application/json');

    $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);

    expect($response->json('choices.0.message.content'))->toBe('Hello! How can I assist you today?');

    $row = SpectraRequest::query()->sole();
    expect($row->prompt_tokens)->toBe(19)
        ->and($row->is_streaming)->toBeFalse();
});

it('records provider error responses with the provider message', function () {
    Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'Rate limit reached', 'type' => 'requests']], 429)]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $row = SpectraRequest::query()->sole();
    expect($row->status_code)->toBe(429)
        ->and($row->error_message)->toBe('Rate limit reached')
        ->and((float) $row->total_cost_in_cents)->toEqualWithDelta(0.0, 1e-9);
});

it('records connection failures and still throws them to the app', function () {
    Http::fake(['api.openai.com/*' => fn () => throw new ConnectionException('Connection timed out')]);

    expect(fn () => Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']))
        ->toThrow(ConnectionException::class);

    expect(SpectraRequest::query()->sole()->error_message)->toBe('Connection timed out');
});

it('never lets a tracking failure reach the app', function () {
    app()->instance(StatsAggregator::class, new class extends StatsAggregator
    {
        public function recordRequest(SpectraRequest $request): void
        {
            throw new RuntimeException('stats are down');
        }
    });
    app()->forgetInstance(RequestWriter::class);
    app()->forgetInstance(RequestPersister::class);

    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect($response->successful())->toBeTrue();
});

it('keeps raw request data out of storage when request storage is off', function () {
    config(['spectra.storage.store_prompts' => false, 'spectra.storage.store_request_data' => false]);
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'secret']]]);

    expect(SpectraRequest::query()->value('request'))->toBeNull();
});

it('stores the request without its system prompt and tools when those switches are off', function () {
    config(['spectra.storage.store_system_prompts' => false, 'spectra.storage.store_tools' => false]);
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4o',
        'messages' => [['role' => 'system', 'content' => 'internal rules'], ['role' => 'user', 'content' => 'hi']],
        'tools' => [['type' => 'function', 'function' => ['name' => 'lookup_order']]],
    ]);

    expect(SpectraRequest::query()->sole()->makeVisible('request')->request)
        ->toBe(['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => 'hi']]]);
});

it('stores the response without the system prompt and tools the Responses API echoes', function () {
    config(['spectra.storage.store_system_prompts' => false, 'spectra.storage.store_tools' => false]);
    $body = $this->loadMockResponse('openai/response.json');
    $body['instructions'] = 'internal rules';
    $body['tools'] = [['type' => 'web_search_preview']];
    Http::fake(['api.openai.com/*' => Http::response($body)]);

    Http::post('https://api.openai.com/v1/responses', [
        'model' => 'gpt-4o',
        'instructions' => 'internal rules',
        'input' => 'hi',
        'tools' => [['type' => 'web_search_preview']],
    ]);

    $stored = SpectraRequest::query()->sole()->makeVisible('response')->response;

    expect($stored)->not->toHaveKey('instructions')
        ->not->toHaveKey('tools')
        ->and($stored['output'])->toBe($body['output']);
});

it('saves a call with one lookup by its response id and none for a response without one', function () {
    Http::fake([
        'api.openai.com/v1/chat/completions' => Http::response($this->loadMockResponse('openai/completion.json')),
        'api.openai.com/v1/audio/speech' => Http::response('ID3fake-mp3-bytes', 200, ['Content-Type' => 'audio/mpeg']),
    ]);

    DB::enableQueryLog();
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);
    $chatQueries = collect(DB::getQueryLog())->pluck('query');
    DB::flushQueryLog();
    Http::post('https://api.openai.com/v1/audio/speech', ['model' => 'tts-1', 'voice' => 'alloy', 'input' => 'Hello world']);
    $speechQueries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $lookups = fn ($queries) => $queries->filter(fn (string $sql) => Str::startsWith($sql, 'select') && Str::contains($sql, 'spectra_requests'));

    expect($lookups($chatQueries))->toHaveCount(1)
        ->and($lookups($speechQueries))->toHaveCount(0)
        ->and(SpectraRequest::query()->count())->toBe(2);
});

it('reads the async job cache for polls only', function () {
    Http::fake([
        'api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json')),
        'api.replicate.com/*' => Http::response($this->loadMockResponse('replicate/prediction.json')),
    ]);
    $keys = [];
    Cache::spy();
    Cache::shouldReceive('get')->andReturnUsing(function (string $key, mixed $default = null) use (&$keys) {
        $keys[] = $key;

        return $default;
    });

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);
    $afterChat = collect($keys)->filter(fn (string $key) => Str::startsWith($key, 'spectra:job-model:'))->count();
    Http::get('https://api.replicate.com/v1/predictions/p7pxyz123abc');

    expect($afterChat)->toBe(0)
        ->and(collect($keys)->filter(fn (string $key) => Str::startsWith($key, 'spectra:job-model:replicate:')))->toHaveCount(1);
});

it('stores a vision request without its inline image', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);
    $image = 'data:image/png;base64,'.Str::repeat('A', 200_000);

    Http::post('https://api.openai.com/v1/chat/completions', [
        'model' => 'gpt-4.1',
        'messages' => [['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'What is in this picture?'],
            ['type' => 'image_url', 'image_url' => ['url' => $image, 'detail' => 'high']],
        ]]],
    ]);

    $stored = SpectraRequest::query()->sole()->request;

    expect($stored['messages'][0]['content'][1]['image_url']['url'])->toBe('[stripped]')
        ->and($stored['messages'][0]['content'][0]['text'])->toBe('What is in this picture?')
        ->and(strlen(json_encode($stored, JSON_THROW_ON_ERROR)))->toBeLessThan(2_000);
});

it('counts a polled job once and keeps its original start time', function () {
    $this->travelTo('2026-03-10 12:00:00');
    $writer = app(RequestWriter::class);
    $base = ['provider' => 'openai', 'model' => 'gpt-4o', 'response_id' => 'job-1', 'status_code' => 200, 'model_type' => 'text'];

    $first = $writer->write($base + ['prompt_tokens' => 0, 'total_cost_in_cents' => 0, 'created_at' => now()->subMinute()]);
    $writer->write($base + ['prompt_tokens' => 100, 'total_cost_in_cents' => 5, 'created_at' => now()]);
    $writer->write($base + ['prompt_tokens' => 100, 'total_cost_in_cents' => 5, 'created_at' => now()]);

    $global = SpectraDailyStat::query()->whereNull('trackable_type')->sole();

    expect(SpectraRequest::query()->count())->toBe(1)
        ->and(SpectraRequest::query()->first()->created_at->equalTo($first->created_at))->toBeTrue()
        ->and($global->request_count)->toBe(1)
        ->and($global->prompt_tokens)->toBe(100)
        ->and((float) $global->total_cost_in_cents)->toEqualWithDelta(5.0, 1e-9);
});

it('stores the trackable morph alias so budgets and usage find it', function () {
    Relation::morphMap(['user' => User::class]);

    $user = User::query()->create(['name' => 'a', 'email' => 'a@example.test', 'password' => 'x']);
    Spectra::forTrackable($user);

    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::query()->first()->trackable_type)->toBe('user')
        ->and(SpectraRequest::query()->forTrackable($user)->count())->toBe(1);

    Relation::morphMap([], false);
});

it('lets an explicit trackable win over the signed-in user', function () {
    $owner = User::query()->create(['name' => 'owner', 'email' => 'owner@example.test', 'password' => 'x']);
    $actor = User::query()->create(['name' => 'actor', 'email' => 'actor@example.test', 'password' => 'x']);
    $this->actingAs($actor);

    Spectra::forTrackable($owner);

    expect(Spectra::startRequest('openai', 'gpt-4o')->trackableId)->toBe($owner->getKey())
        ->and(Spectra::startRequest('openai', 'gpt-4o', ['trackable_type' => User::class, 'trackable_id' => $owner->getKey()])->trackableId)->toBe($owner->getKey());
});

it('resets global settings between queued jobs', function () {
    Spectra::addGlobalTags(['job-a'])->withPricingTier('batch');

    // What the queue worker does between jobs.
    app()->forgetScopedInstances();
    Facade::clearResolvedInstances();

    $context = Spectra::startRequest('openai', 'gpt-4o');

    expect($context->tags)->toBe([])
        ->and($context->pricingTier)->toBeNull();
});

it('reads the model from multipart bodies even when the file comes first', function () {
    Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/transcription_duration_usage.json'))]);

    Http::attach('file', Str::repeat('A', 300_000), 'audio.mp3')
        ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'whisper-1']);

    expect(SpectraRequest::query()->sole()->model)->not->toBe('unknown');
});

it('tracks Gemini streamed JSON arrays as streams', function () {
    $body = json_encode([
        ['candidates' => [['content' => ['parts' => [['text' => 'Hel']], 'role' => 'model']]], 'modelVersion' => 'gemini-2.5-flash', 'responseId' => 'g-1'],
        ['candidates' => [['content' => ['parts' => [['text' => 'lo']], 'role' => 'model'], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 9, 'candidatesTokenCount' => 2, 'thoughtsTokenCount' => 5, 'totalTokenCount' => 16], 'modelVersion' => 'gemini-2.5-flash', 'responseId' => 'g-1'],
    ], JSON_THROW_ON_ERROR);

    fakeOneShotResponse($body, 'application/json');

    $response = Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent', ['contents' => []]);

    expect($response->body())->toBe($body);

    $row = SpectraRequest::query()->sole();
    expect($row->is_streaming)->toBeTrue()
        ->and($row->prompt_tokens)->toBe(9)
        ->and($row->completion_tokens)->toBe(7)
        ->and($row->reasoning_tokens)->toBe(5);
});

it('merges per-request tags and metadata with the global ones', function () {
    Spectra::addGlobalTags(['global'])->withMetadata(['env' => 'test']);

    $context = Spectra::startRequest('openai', 'gpt-4o', ['tags' => ['local'], 'metadata' => ['feature' => 'chat']]);

    expect($context->tags)->toBe(['global', 'local'])
        ->and($context->metadata)->toBe(['env' => 'test', 'feature' => 'chat']);
});

it('tags only the requests sent inside withTags()', function () {
    $completion = $this->loadMockResponse('openai/completion.json');
    Http::fake(['api.openai.com/*' => Http::sequence([
        Http::response([...$completion, 'id' => 'chatcmpl-1']),
        Http::response([...$completion, 'id' => 'chatcmpl-2']),
    ])]);
    Spectra::addGlobalTags(['global']);

    Spectra::withTags(['summary'], fn () => Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']));
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $tags = SpectraRequest::with('tags')->oldest('id')->get()
        ->map(fn (SpectraRequest $row): array => $row->tags->pluck('name')->sort()->values()->all());

    expect($tags->all())->toBe([['global', 'summary'], ['global']]);
});

it('keeps the tags of withTags() on a stream read after the callback', function () {
    $response = Spectra::withTags(['reply'], fn () => streamedRequest(openAiStreamBody()));

    $response->body();

    expect(SpectraRequest::with('tags')->sole()->tags->pluck('name')->all())->toBe(['reply']);
});

it('tags requests sent inside withPrompt()', function () {
    $completion = $this->loadMockResponse('openai/completion.json');
    Http::fake(['api.openai.com/*' => Http::sequence([
        Http::response([...$completion, 'id' => 'chatcmpl-1']),
        Http::response([...$completion, 'id' => 'chatcmpl-2']),
    ])]);

    Spectra::withPrompt('support-answer', 'v3', fn () => Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']));
    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    expect(SpectraRequest::query()->where('response_id', 'chatcmpl-1')->value('prompt_version'))->toBe('v3')
        ->and(SpectraRequest::query()->where('response_id', 'chatcmpl-2')->value('prompt_version'))->toBeNull();
});

it('keeps the prompt of withPrompt() on a stream read after the callback', function () {
    $response = Spectra::withPrompt('support-answer', 'v4', fn () => streamedRequest(openAiStreamBody()));

    $response->body();

    expect(SpectraRequest::query()->sole()->only(['prompt_name', 'prompt_version']))->toBe(['prompt_name' => 'support-answer', 'prompt_version' => 'v4']);
});

it('nests withTags() calls and restores the outer tags, even after an exception', function () {
    $seen = Spectra::withTags(['outer'], function () {
        $inner = Spectra::withTags(['inner'], fn () => Spectra::startRequest('openai', 'gpt-4o')->tags);

        try {
            Spectra::withTags(['failing'], fn () => throw new RuntimeException('boom'));
        } catch (RuntimeException) {
        }

        return [$inner, Spectra::startRequest('openai', 'gpt-4o')->tags];
    });

    expect($seen)->toBe([['outer', 'inner'], ['outer']])
        ->and(Spectra::startRequest('openai', 'gpt-4o')->tags)->toBe([]);
});

it('keeps Spectra::stream() options when it takes over a tracked stream', function () {
    $tracker = Spectra::stream('openai', 'gpt-4o', ['tags' => ['wrapped'], 'metadata' => ['source' => 'manual']]);
    $response = streamedRequest(openAiStreamBody());

    iterator_to_array($tracker->track($response->body()), false);
    $tracker->finish();

    $row = SpectraRequest::with('tags')->sole();
    expect($row->tags->pluck('name')->all())->toContain('wrapped')
        ->and($row->metadata)->toMatchArray(['source' => 'manual']);
});

it('prices a stream at the tier the request asked for', function () {
    fakeOneShotResponse(openAiStreamBody(), 'text/event-stream');

    $response = Http::withOptions(['stream' => true])
        ->post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o', 'stream' => true, 'service_tier' => 'flex']);

    $response->body();

    expect(SpectraRequest::query()->sole()->pricing_tier)->toBe('flex');
});

it('stores the provider model id even when the response echoes the request', function () {
    Http::fake(['api.openai.com/*' => Http::response([...$this->loadMockResponse('openai/completion.json'), 'model' => 'gpt-4o'])]);

    Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4o']);

    $row = SpectraRequest::query()->sole();
    expect($row->snapshot)->toBe('gpt-4o')
        ->and($row->model)->toBe('GPT-4o');
});
