<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Providers\OpenAI\OpenAI;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\ResponseProcessor;

/**
 * Tracking never breaks the host app: a malformed provider payload, a failing
 * tracking step or a media download that fails is reported, and the request
 * is still recorded with whatever could be read, including its cost.
 */
describe('Spectra::track()', function () {
    it('returns the callback result when recording it fails', function () {
        Exceptions::fake();
        $this->mock(ResponseProcessor::class)
            ->shouldReceive('processResponse')
            ->andThrow(new TypeError('handler read a malformed field'));

        $result = Spectra::track('openai', 'gpt-4.1', fn () => ['answer' => 42]);

        expect($result)->toBe(['answer' => 42]);
        Exceptions::assertReported(TypeError::class);
    });

    it('rethrows the callback exception when recording the failure fails', function () {
        Exceptions::fake();
        $this->mock(RequestPersister::class)
            ->shouldReceive('persist')
            ->andThrow(new RuntimeException('database is down'));

        expect(fn () => Spectra::track('openai', 'gpt-4.1', fn () => throw new LogicException('the app failed')))
            ->toThrow(LogicException::class, 'the app failed');
        Exceptions::assertReported(RuntimeException::class);
    });
});

describe('Spectra::stream()', function () {
    it('keeps yielding text and records the stream when a chunk is malformed', function () {
        Exceptions::fake();
        $tracker = Spectra::stream('openai', 'gpt-4.1');
        $chunks = [
            ['choices' => [['delta' => ['content' => 'Hel']]]],
            // An error event whose message is not a string, and a finish reason that is not one.
            ['type' => 'error', 'error' => ['message' => ['nested' => 'oops']]],
            ['choices' => [['delta' => ['content' => 'lo'], 'finish_reason' => ['stop']]]],
            ['choices' => [], 'usage' => ['prompt_tokens' => 19, 'completion_tokens' => 10]],
        ];

        $content = '';
        foreach ($tracker->track($chunks) as $text) {
            $content .= $text;
        }
        $row = $tracker->finish();

        expect($content)->toBe('Hello')
            ->and($row->exists)->toBeTrue()
            ->and($row->prompt_tokens)->toBe(19)
            ->and($row->total_cost_in_cents)->toBeGreaterThan(0);
    });

    it('rethrows an exception from the underlying stream', function () {
        $stream = (function () {
            yield ['choices' => [['delta' => ['content' => 'Hi']]]];

            throw new RuntimeException('connection reset');
        })();

        $consume = function () use ($stream) {
            foreach (Spectra::stream('openai', 'gpt-4.1')->track($stream) as $text) {
                //
            }
        };

        expect($consume)->toThrow(RuntimeException::class, 'connection reset');
    });

    it('returns an unsaved request when recording the stream fails', function () {
        Exceptions::fake();
        $this->mock(ResponseProcessor::class)
            ->shouldReceive('processResponse')
            ->andThrow(new TypeError('handler read a malformed field'));
        $tracker = Spectra::stream('openai', 'gpt-4.1');

        foreach ($tracker->track([['choices' => [['delta' => ['content' => 'Hi']]]]]) as $text) {
            //
        }

        expect($tracker->finish()->exists)->toBeFalse()
            ->and($tracker->isFinished())->toBeTrue();
        Exceptions::assertReported(TypeError::class);
    });
});

describe('ResponseProcessor', function () {
    it('records an OpenAI chat response whose fields have the wrong types, with its cost', function () {
        Exceptions::fake();
        $body = $this->loadMockResponse('openai/completion.json');
        $body['id'] = ['chatcmpl-B9MBs8CjcvOU2jLn4n570S5qMJKcT'];
        $body['model'] = ['gpt-4.1'];
        $body['choices'][0]['finish_reason'] = ['stop'];
        $body['choices'][0]['message']['content'] = ['text' => ['Hello!']];
        Http::fake(['api.openai.com/*' => Http::response($body)]);

        $response = Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);

        $row = SpectraRequest::sole();

        // gpt-4.1: $2 in / $8 out per 1M tokens: (19 × 200 + 10 × 800) / 1M = 0.0118¢
        expect($response->successful())->toBeTrue()
            ->and($row->model)->toBe('GPT-4.1')
            ->and($row->response_id)->toBeNull()
            ->and($row->finish_reason)->toBeNull()
            ->and($row->prompt_tokens)->toBe(19)
            ->and($row->total_cost_in_cents)->toEqualWithDelta(0.0118, 0.000001);
        Exceptions::assertReported(TypeError::class);
    });

    it('records a failed request with the raw body when the error message is not a string', function () {
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => ['quota' => 'exceeded']]], 429)]);

        Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);

        expect(SpectraRequest::sole()->error_message)->toBe('{"error":{"message":{"quota":"exceeded"}}}')
            ->and(SpectraRequest::sole()->status_code)->toBe(429);
    });

    it('records a Gemini image response whose parts have the wrong types, with its cost', function () {
        Exceptions::fake();
        config()->set('spectra.storage.media.enabled', true);
        config()->set('spectra.storage.media.disk', 'spectra-test');
        Storage::fake('spectra-test');
        $body = $this->loadMockResponse('google/generate-content-image.json');
        $body['modelVersion'] = ['gemini-3.1-flash-image'];
        $body['candidates'][0]['content']['parts'][] = 'not a part';
        $body['candidates'][0]['content']['parts'][] = ['inlineData' => ['mimeType' => ['image/png'], 'data' => 42]];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body)]);

        Http::withHeaders(['x-goog-api-key' => 'test-key'])
            ->post('https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-flash-image:generateContent', [
                'contents' => [['role' => 'user', 'parts' => [['text' => 'Hello']]]],
            ]);

        $row = SpectraRequest::sole();

        // Gemini 3.1 Flash Image: (20×50 + (30 + 200)×300 + 1120×6000) / 1M = 6.79¢
        expect($row->model_type)->toBe('image')
            ->and($row->image_count)->toBe(1)
            ->and($row->total_cost_in_cents)->toEqualWithDelta(6.79, 0.000001);
        Exceptions::assertReported(TypeError::class);
    });

    it('records the request with its cost when a handler step throws', function () {
        Exceptions::fake();
        app()->instance('spectra.provider.openai', new class extends OpenAI
        {
            public function extractFinishReason(mixed $response, string $endpoint = ''): ?string
            {
                throw new TypeError('finish_reason is not a string');
            }
        });
        Http::fake(['api.openai.com/*' => Http::response($this->loadMockResponse('openai/completion.json'))]);

        Http::post('https://api.openai.com/v1/chat/completions', ['model' => 'gpt-4.1']);

        $row = SpectraRequest::sole();

        // gpt-4.1: (19 × 200 + 10 × 800) / 1M = 0.0118¢
        expect($row->finish_reason)->toBeNull()
            ->and($row->response_id)->toBe('chatcmpl-B9MBs8CjcvOU2jLn4n570S5qMJKcT')
            ->and($row->total_cost_in_cents)->toEqualWithDelta(0.0118, 0.000001);
        Exceptions::assertReported(TypeError::class);
    });
});

describe('media downloads', function () {
    it('stores the other images and still records the request when one download fails', function () {
        Exceptions::fake();
        config()->set('spectra.storage.media.enabled', true);
        config()->set('spectra.storage.media.disk', 'spectra-test');
        Storage::fake('spectra-test');
        $body = $this->loadMockResponse('replicate/prediction-flux-1.1-pro.json');
        $body['output'] = ['https://replicate.delivery/a/broken.webp', 'https://replicate.delivery/b/fine.webp'];
        Http::fake([
            'api.replicate.com/*' => Http::response($body, 201),
            'replicate.delivery/a/*' => fn () => throw new ConnectionException('Connection timed out'),
            'replicate.delivery/b/*' => Http::response('webp-bytes', 200, ['Content-Type' => 'image/webp']),
        ]);

        Http::post('https://api.replicate.com/v1/predictions', ['version' => 'black-forest-labs/flux-1.1-pro', 'input' => ['prompt' => 'x']]);

        $row = SpectraRequest::sole();

        expect($row->media_storage_path)->toHaveCount(1)
            ->and(Storage::disk('spectra-test')->get($row->media_storage_path[0]))->toBe('webp-bytes')
            ->and($row->total_cost_in_cents)->toBeGreaterThan(0);
        Exceptions::assertReported(ConnectionException::class);
    });
});
