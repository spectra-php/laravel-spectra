<?php

declare(strict_types=1);

/**
 * Scores: user feedback, model judgements and reviewer labels recorded with
 * Spectra::score() on a call, a trace or a conversation, and the fake that
 * records them in tests.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spectra\Enums\ScoreSource;
use Spectra\Enums\ScoreType;
use Spectra\Events\ScoreRecorded;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Workbench\App\Models\User;

it('scores the current trace when no call, trace or conversation is named', function () {
    Spectra::withTraceId('trace-123');

    $score = Spectra::score('helpful', true, comment: 'Spot on', source: ScoreSource::User);

    expect($score)->toBeInstanceOf(SpectraScore::class)
        ->and(SpectraScore::query()->sole()->only(['name', 'value', 'label', 'trace_id', 'request_id', 'comment']))->toBe([
            'name' => 'helpful',
            'value' => 1.0,
            'label' => null,
            'trace_id' => 'trace-123',
            'request_id' => null,
            'comment' => 'Spot on',
        ])
        ->and($score->type)->toBe(ScoreType::Boolean)
        ->and($score->source)->toBe(ScoreSource::User)
        ->and($score->displayValue())->toBeTrue();
});

it('scores a call by the context id it gets inside track(), its stored id', function () {
    $contextId = null;

    Spectra::track('openai', 'gpt-4o', function ($context) use (&$contextId) {
        $contextId = $context->id;

        return ['choices' => [['message' => ['content' => 'Hi']]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]];
    }, ['conversation_id' => 'chat-9']);

    $call = SpectraRequest::query()->sole();

    Spectra::score('accuracy', 0.82, request: $call, source: 'model');

    $score = SpectraScore::query()->sole();

    expect($call->id)->toBe($contextId)
        ->and($score->request_id)->toBe($call->id)
        ->and($score->trace_id)->toBe($call->trace_id)
        ->and($score->conversation_id)->toBe('chat-9')
        ->and($score->type)->toBe(ScoreType::Numeric)
        ->and($score->value)->toBe(0.82)
        ->and($score->source)->toBe(ScoreSource::Model);
});

it('stores a string value as a category and the author who gave it', function () {
    $user = User::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'secret']);

    Spectra::score('sentiment', 'negative', conversationId: 'chat-1', author: $user, metadata: ['screen' => 'chat']);

    $score = SpectraScore::query()->sole();

    expect($score->type)->toBe(ScoreType::Categorical)
        ->and($score->value)->toBeNull()
        ->and($score->label)->toBe('negative')
        ->and($score->author_type)->toBe($user->getMorphClass())
        ->and($score->author_id)->toBe((string) $user->getKey())
        ->and($score->metadata)->toEqual(['screen' => 'chat']);
});

it('rejects a score without a name, without a target, or with a value it cannot store', function (array $arguments, string $message) {
    // Without per-request traces there is no current trace to fall back on.
    config()->set('spectra.tracking.trace_per_request', false);

    expect(fn () => Spectra::score(...$arguments))->toThrow(InvalidArgumentException::class, $message)
        ->and(SpectraScore::query()->count())->toBe(0);
})->with([
    'blank name' => [['name' => '  ', 'value' => 1, 'traceId' => 't'], 'needs a name'],
    'outside a trace' => [['name' => 'helpful', 'value' => true], 'needs a call, trace or conversation'],
    'infinite number' => [['name' => 'rating', 'value' => INF, 'traceId' => 't'], 'finite number'],
    'empty category' => [['name' => 'sentiment', 'value' => '', 'traceId' => 't'], 'cannot be empty'],
]);

it('skips scores with one warning until the scores migration runs', function () {
    Schema::drop('spectra_scores');
    Log::spy();

    expect(Spectra::score('helpful', true, traceId: 't'))->toBeNull()
        ->and(Spectra::score('helpful', false, traceId: 't'))->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, 'spectra_scores'));
});

it('dispatches ScoreRecorded with the saved score', function () {
    Event::fake([ScoreRecorded::class]);

    Spectra::score('helpful', false, traceId: 'trace-1');

    Event::assertDispatched(ScoreRecorded::class, fn (ScoreRecorded $event) => $event->score->trace_id === 'trace-1' && $event->score->value === 0.0);
});

it('records scores in memory with the fake', function () {
    Spectra::fake();

    Spectra::score('helpful', true, source: ScoreSource::User);

    Spectra::assertScored('helpful', fn (array $score) => $score['value'] === 1.0 && $score['source'] === ScoreSource::User);
    Spectra::assertNotScored('accuracy');
    expect(SpectraScore::query()->count())->toBe(0);
});
