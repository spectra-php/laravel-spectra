<?php

declare(strict_types=1);

/**
 * Values the app or the client choose are cut to their column's length, so
 * an over-long one can't make the insert fail and lose the call (SQLite
 * accepts any length, MySQL and PostgreSQL reject the row). A tag that can't
 * be saved doesn't keep the call out of the daily stats either.
 */

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Models\SpectraTag;

function trackedChat(array $options = []): void
{
    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['content' => 'Hi']]]], $options);
}

it('cuts a long X-Request-ID header to the column length', function () {
    $this->app->instance('request', Request::create('/ai', 'POST', server: ['HTTP_X_REQUEST_ID' => str_repeat('r', 300)]));

    trackedChat();

    expect(SpectraRequest::query()->sole()->laravel_request_id)->toBe(str_repeat('r', 255));
});

it('cuts a long conversation id, prompt name and version to their column lengths', function () {
    trackedChat([
        'conversation_id' => str_repeat('c', 300),
        'prompt_name' => str_repeat('p', 120),
        'prompt_version' => str_repeat('v', 60),
    ]);

    $request = SpectraRequest::query()->sole();

    expect($request->conversation_id)->toBe(str_repeat('c', 255))
        ->and($request->prompt_name)->toBe(str_repeat('p', 100))
        ->and($request->prompt_version)->toBe(str_repeat('v', 50));
});

it('cuts a score\'s conversation id the same way, so it still matches the call', function () {
    trackedChat(['conversation_id' => str_repeat('c', 300)]);
    Spectra::score('helpful', true, conversationId: str_repeat('c', 300));

    expect(SpectraScore::query()->sole()->conversation_id)->toBe(SpectraRequest::query()->sole()->conversation_id);
});

it('cuts a long trace id to the column length for the call and its scores', function () {
    Spectra::withTraceId(str_repeat('x', 80));

    trackedChat();
    Spectra::score('helpful', true);

    expect(Spectra::currentTraceId())->toBe(str_repeat('x', 64))
        ->and(SpectraRequest::query()->sole()->trace_id)->toBe(str_repeat('x', 64))
        ->and(SpectraScore::query()->sole()->trace_id)->toBe(str_repeat('x', 64));
});

it('cuts a long tag to the column length', function () {
    trackedChat(['tags' => [str_repeat('t', 300)]]);

    expect(SpectraTag::query()->sole()->name)->toBe(str_repeat('t', 255));
});

it('keeps the call in the daily stats when its tags cannot be saved', function () {
    Exceptions::fake();
    Schema::drop('spectra_requests_tags');

    trackedChat(['tags' => ['nightly']]);

    expect(SpectraRequest::query()->count())->toBe(1)
        ->and(SpectraDailyStat::query()->sum('request_count'))->toEqual(1);
    Exceptions::assertReported(QueryException::class);
});
