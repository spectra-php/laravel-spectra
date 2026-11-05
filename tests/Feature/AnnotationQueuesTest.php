<?php

declare(strict_types=1);

/**
 * Annotation queues: traces queued for review (sampled as calls are
 * recorded, or added by hand), reviewed on the dashboard as Annotation
 * scores, or skipped. Writing needs the annotateSpectra gate.
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spectra\Enums\AnnotationStatus;
use Spectra\Enums\ScoreSource;
use Spectra\Facades\Spectra;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraScore;
use Spectra\Support\Annotations\AnnotationQueues;
use Spectra\Support\Tracking\RequestWriter;
use Workbench\App\Models\User;

beforeEach(function () {
    // annotateSpectra follows viewSpectra unless the app defines it.
    Gate::define('viewSpectra', fn ($user = null) => true);
    config()->set('spectra.annotations.queues', [
        'support-review' => [
            'description' => 'Check the support answers',
            'scores' => [
                'helpful' => 'boolean',
                'accuracy' => ['type' => 'numeric', 'min' => 1, 'max' => 5],
                'issue' => ['type' => 'categorical', 'options' => ['wrong-facts', 'tone']],
            ],
            'sample' => 1.0,
            'filter' => ['prompt_name' => 'support-answer'],
        ],
        'manual' => ['scores' => ['ok' => 'boolean']],
    ]);
});

/**
 * Track one chat call, tagged with a prompt when given.
 */
function trackReviewCall(?string $prompt = null, string $question = 'Where is my order?'): void
{
    Spectra::track('openai', 'gpt-4o', fn () => ['choices' => [['message' => ['role' => 'assistant', 'content' => 'It shipped today.']]]], Arr::whereNotNull([
        'prompt_name' => $prompt,
        'endpoint' => '/v1/chat/completions',
        'request_data' => ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => $question]]],
    ]));
}

it('samples the traces of matching calls into a queue, once per trace', function () {
    trackReviewCall('support-answer');
    trackReviewCall('support-answer');
    trackReviewCall('summary');

    $item = SpectraAnnotationItem::query()->sole();

    expect($item->queue)->toBe('support-review')
        ->and($item->status)->toBe(AnnotationStatus::Pending)
        ->and($item->trace_id)->toBe(Spectra::currentTraceId());
});

it('samples only the configured share of traces', function () {
    config()->set('spectra.annotations.queues.support-review.sample', 0.5);

    foreach (range(1, 200) as $ignored) {
        Spectra::newTrace();
        trackReviewCall('support-answer');
    }

    expect(SpectraAnnotationItem::query()->count())->toBeGreaterThan(60)->toBeLessThan(140);
});

it('queues the trace when the request is written, so the queue setting keeps it off the request', function () {
    config()->set('spectra.queue.enabled', true);
    Queue::fake();

    trackReviewCall('support-answer');

    expect(SpectraAnnotationItem::query()->count())->toBe(0);

    Queue::pushed(PersistSpectraRequestJob::class)->each(fn (PersistSpectraRequestJob $job) => $job->handle(app(RequestWriter::class)));

    $item = SpectraAnnotationItem::query()->sole();

    expect($item->queue)->toBe('support-review')
        ->and($item->request_id)->toBe(SpectraRequest::query()->sole()->id);
});

it('saves no scores when another reviewer finished the item first', function () {
    trackReviewCall('support-answer');
    $item = SpectraAnnotationItem::query()->sole();
    $queues = app(AnnotationQueues::class);

    // Both reviewers loaded the item while it was pending; the other one submits first.
    $stale = SpectraAnnotationItem::query()->findOrFail($item->id);
    expect($queues->skip($item))->toBeTrue();

    expect($queues->review($stale, ['helpful' => true]))->toBeFalse()
        ->and(SpectraScore::query()->count())->toBe(0)
        ->and($item->fresh()->status)->toBe(AnnotationStatus::Skipped);
});

it('queues the current trace by hand, and rejects an unknown queue', function () {
    trackReviewCall();

    expect(Spectra::queueForReview('manual'))->toBeTrue()
        ->and(Spectra::queueForReview('manual'))->toBeTrue()
        ->and(SpectraAnnotationItem::query()->where('queue', 'manual')->count())->toBe(1)
        ->and(fn () => Spectra::queueForReview('nope'))->toThrow(InvalidArgumentException::class, 'no Spectra annotation queue [nope]');
});

it('lists the queues with their counts, and serves the next trace with its calls', function () {
    trackReviewCall('support-answer', 'Where is order 42?');

    $queues = $this->getJson('/spectra/api/annotations')->assertOk()->json();
    $next = $this->getJson('/spectra/api/annotations/support-review/next')->assertOk()->json();

    expect($queues['can_annotate'])->toBeTrue()
        ->and(collect($queues['queues'])->firstWhere('name', 'support-review'))->toMatchArray(['pending_count' => 1, 'completed_count' => 0])
        ->and($next['item']['calls'][0])->toMatchArray(['prompt' => 'Where is order 42?', 'reply' => 'It shipped today.', 'is_queued_call' => true])
        ->and($next['queue']['scores'])->toHaveCount(3);
});

it('saves a review as Annotation scores on the trace, by the signed-in reviewer', function () {
    $reviewer = User::query()->create(['name' => 'Ava', 'email' => 'ava@example.test', 'password' => 'secret']);
    trackReviewCall('support-answer');
    $item = SpectraAnnotationItem::query()->sole();

    $this->actingAs($reviewer)->postJson("/spectra/api/annotations/items/{$item->id}", [
        'action' => 'review',
        'scores' => ['helpful' => true, 'accuracy' => 4, 'issue' => ''],
        'comment' => 'Good, a bit long.',
    ])->assertOk()->assertJson(['status' => 'completed']);

    $scores = SpectraScore::query()->orderBy('name')->get();

    expect($scores->pluck('name')->all())->toBe(['accuracy', 'helpful'])
        ->and($scores->pluck('source')->unique()->all())->toBe([ScoreSource::Annotation])
        ->and($scores->pluck('trace_id')->unique()->all())->toBe([$item->trace_id])
        ->and($scores->first()->author_id)->toBe((string) $reviewer->id)
        ->and($scores->first()->metadata)->toEqual(['annotation_queue' => 'support-review'])
        ->and($item->fresh())->status->toBe(AnnotationStatus::Completed)
        ->and($item->fresh())->comment->toBe('Good, a bit long.')
        ->and($item->fresh())->reviewer_id->toBe((string) $reviewer->id);

    $this->getJson('/spectra/api/annotations/support-review/next')->assertOk()->assertJsonPath('item', null);
});

it('rejects values outside a score\'s range or options, and a second review', function () {
    trackReviewCall('support-answer');
    $item = SpectraAnnotationItem::query()->sole();

    $this->postJson("/spectra/api/annotations/items/{$item->id}", ['action' => 'review', 'scores' => ['accuracy' => 9]])->assertUnprocessable()->assertJsonValidationErrors('scores');
    $this->postJson("/spectra/api/annotations/items/{$item->id}", ['action' => 'review', 'scores' => ['issue' => 'spelling']])->assertUnprocessable();
    $this->postJson("/spectra/api/annotations/items/{$item->id}", ['action' => 'review', 'scores' => []])->assertUnprocessable();

    $this->postJson("/spectra/api/annotations/items/{$item->id}", ['action' => 'skip'])->assertOk()->assertJson(['status' => 'skipped']);
    $this->postJson("/spectra/api/annotations/items/{$item->id}", ['action' => 'skip'])->assertUnprocessable();

    expect(SpectraScore::query()->count())->toBe(0);
});

it('queues a trace from the dashboard', function () {
    $this->postJson('/spectra/api/annotations/manual/items', ['trace_id' => 'trace-from-the-dashboard'])->assertOk()->assertJson(['queued' => true]);
    $this->postJson('/spectra/api/annotations/nope/items', ['trace_id' => 'x'])->assertNotFound();

    expect(SpectraAnnotationItem::query()->sole()->trace_id)->toBe('trace-from-the-dashboard');
});

it('needs the annotateSpectra gate to review or queue', function () {
    Gate::define('annotateSpectra', fn ($user = null) => false);
    trackReviewCall('support-answer');
    $item = SpectraAnnotationItem::query()->sole();

    $this->getJson('/spectra/api/annotations')->assertOk()->assertJsonPath('can_annotate', false);
    $this->postJson("/spectra/api/annotations/items/{$item->id}", ['action' => 'skip'])->assertForbidden();
    $this->postJson('/spectra/api/annotations/manual/items', ['trace_id' => 'x'])->assertForbidden();
});

it('rejects a queue whose config has a typo', function (array $queue, string $message) {
    config()->set('spectra.annotations.queues', ['broken' => $queue]);

    expect(fn () => Spectra::queueForReview('broken', 'trace'))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'no scores' => [[], 'needs at least one score'],
    'unknown type' => [['scores' => ['x' => 'stars']], 'needs a type'],
    'no options' => [['scores' => ['x' => 'categorical']], 'needs options'],
    'unknown filter' => [['scores' => ['x' => 'boolean'], 'filter' => ['user' => 1]], 'unknown filter [user]'],
]);

it('keeps tracking without the annotation table, and warns once', function () {
    Schema::drop('spectra_annotation_items');
    Log::spy();

    trackReviewCall('support-answer');
    trackReviewCall('support-answer');

    expect(Spectra::queueForReview('manual'))->toBeFalse();
    $this->getJson('/spectra/api/annotations')->assertOk()->assertJsonPath('migrated', false);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, 'spectra_annotation_items'));
});

it('prunes old review items with the requests', function () {
    trackReviewCall('support-answer');
    SpectraAnnotationItem::query()->update(['created_at' => now()->subDays(40)]);

    $this->artisan('spectra:prune', ['--hours' => 24 * 30])->expectsOutputToContain('1 review items')->assertSuccessful();

    expect(SpectraAnnotationItem::query()->count())->toBe(0);
});

it('remembers queued traces in the fake', function () {
    Spectra::fake();

    Spectra::queueForReview('manual', 'trace-1');

    Spectra::assertQueuedForReview('manual', 'trace-1');
});
