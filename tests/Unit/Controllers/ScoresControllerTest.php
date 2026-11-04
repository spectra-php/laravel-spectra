<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\ScoresController;
use Spectra\Http\Controllers\Api\ShowConversationController;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Http\Controllers\Api\ShowTraceController;
use Spectra\Models\SpectraRequest;

/**
 * Scores on the dashboard: a call, trace or conversation screen lists its own
 * scores and those given to its calls, and the overview sums each score name
 * for the period.
 */
function scoresFor(string $controller, array $parameters): array
{
    return collect(app()->call([app($controller), '__invoke'], $parameters)->toArray()['scores'])
        ->map(fn ($score) => is_array($score) ? $score : $score->toArray())
        ->all();
}

function scoreSummary(array $query = []): array
{
    $scores = app()->call([app(ScoresController::class), '__invoke'], ['request' => new Request(['period' => 'all', ...$query])])->toArray()['scores'];

    return collect($scores)->map(fn ($score) => is_array($score) ? $score : $score->toArray())->all();
}

it('lists a call\'s scores on the request screen', function () {
    $call = SpectraRequest::factory()->create(['trace_id' => 'trace-a']);
    Spectra::score('helpful', true, request: $call->id, comment: 'Great');
    Spectra::score('helpful', false, traceId: 'trace-other');

    $scores = scoresFor(ShowRequestController::class, ['id' => $call->id]);

    expect($scores)->toHaveCount(1)
        ->and($scores[0])->toMatchArray(['name' => 'helpful', 'type' => 'boolean', 'value' => true, 'comment' => 'Great', 'source' => 'api', 'request_id' => $call->id]);
});

it('lists a trace\'s scores with those given to its calls', function () {
    $call = SpectraRequest::factory()->create(['trace_id' => 'trace-a', 'status_code' => 200]);
    Spectra::score('accuracy', 0.75, request: $call->id);
    Spectra::score('helpful', true, traceId: 'trace-a');
    Spectra::score('helpful', true, traceId: 'trace-b');

    $scores = scoresFor(ShowTraceController::class, ['traceId' => 'trace-a']);

    expect(collect($scores)->pluck('name')->sort()->values()->all())->toBe(['accuracy', 'helpful']);
});

it('lists a conversation\'s scores with those given to its calls and traces', function () {
    $call = SpectraRequest::factory()->create(['conversation_id' => 'chat-1', 'trace_id' => 'trace-a', 'status_code' => 200]);
    Spectra::score('sentiment', 'positive', conversationId: 'chat-1');
    Spectra::score('helpful', true, traceId: 'trace-a');
    Spectra::score('accuracy', 0.5, request: $call->id);
    Spectra::score('helpful', false, conversationId: 'chat-2');

    $scores = scoresFor(ShowConversationController::class, ['conversationId' => 'chat-1']);

    expect(collect($scores)->pluck('name')->sort()->values()->all())->toBe(['accuracy', 'helpful', 'sentiment']);
});

it('sums each score name for the overview: averages, the share of yes and the top labels', function () {
    foreach ([true, true, true, false] as $helpful) {
        Spectra::score('helpful', $helpful, traceId: 't');
    }
    Spectra::score('rating', 2, traceId: 't');
    Spectra::score('rating', 5, traceId: 't');
    foreach (['positive', 'positive', 'negative'] as $sentiment) {
        Spectra::score('sentiment', $sentiment, traceId: 't');
    }

    $summary = collect(scoreSummary())->keyBy('name');

    expect($summary['helpful'])->toMatchArray(['type' => 'boolean', 'count' => 4, 'average' => 0.75])
        ->and($summary['rating'])->toMatchArray(['type' => 'numeric', 'count' => 2, 'average' => 3.5, 'minimum' => 2.0, 'maximum' => 5.0])
        ->and($summary['sentiment']['labels'])->toBe([['label' => 'positive', 'count' => 2], ['label' => 'negative', 'count' => 1]]);
});

it('shows no scores before the scores migration runs', function () {
    $call = SpectraRequest::factory()->create(['trace_id' => 'trace-a']);
    Schema::connection(config('spectra.storage.connection'))->drop('spectra_scores');

    expect(scoresFor(ShowRequestController::class, ['id' => $call->id]))->toBe([])
        ->and(scoreSummary())->toBe([]);
});
