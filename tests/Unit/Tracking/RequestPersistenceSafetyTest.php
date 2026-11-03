<?php

declare(strict_types=1);

/**
 * Saving a tracked request off the request cycle is safe to repeat and never
 * reaches the app: a retried job updates its own row, and failures after the
 * save, or on the after-response path, are reported and go no further.
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Spectra\Events\RequestTracked;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;

function completedContext(): RequestContext
{
    $context = new RequestContext(['provider' => 'openai', 'model' => 'gpt-4o']);
    $context->complete(['model' => 'gpt-4o'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

    return $context;
}

it('gives a queued request its row id before the job is dispatched', function () {
    Queue::fake();
    config(['spectra.queue.enabled' => true]);

    $returned = app(RequestPersister::class)->persist(completedContext());

    Queue::assertPushed(
        PersistSpectraRequestJob::class,
        fn (PersistSpectraRequestJob $job) => is_string($job->attributes['id'] ?? null)
            && $job->attributes['id'] === $returned->id
    );
});

it('does not save a second row or count the request twice when the job is retried', function () {
    config(['spectra.queue.enabled' => true]);
    Queue::fake();
    app(RequestPersister::class)->persist(completedContext());
    $job = Queue::pushed(PersistSpectraRequestJob::class)->first();

    $job->handle(app(RequestWriter::class));
    $job->handle(app(RequestWriter::class));

    expect(SpectraRequest::query()->count())->toBe(1)
        ->and(SpectraDailyStat::query()->sum('request_count'))->toEqual(1);
});

it('keeps the saved row when a RequestTracked listener throws', function () {
    Exceptions::fake();
    Event::listen(RequestTracked::class, fn () => throw new RuntimeException('listener failed'));

    $request = app(RequestPersister::class)->persist(completedContext());

    expect($request->exists)->toBeTrue()
        ->and(SpectraRequest::query()->count())->toBe(1);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'listener failed');
});

it('reports an after-response save failure and still runs later terminating callbacks', function () {
    Exceptions::fake();
    $writer = Mockery::mock(RequestWriter::class);
    $writer->shouldReceive('write')->andThrow(new RuntimeException('database is down'));
    app()->instance(RequestWriter::class, $writer);
    $persister = new class(app(RequestPersister::class)) extends RequestPersister
    {
        public function __construct(RequestPersister $persister)
        {
            parent::__construct($persister->costCalculator, $persister->writer, $persister->exporter, $persister->transformer);
        }

        public function saveAfterResponse(): void
        {
            $this->persistAfterResponse(['provider' => 'openai', 'model' => 'gpt-4o'], []);
        }
    };
    $laterCallbackRan = false;

    $persister->saveAfterResponse();
    app()->terminating(function () use (&$laterCallbackRan) {
        $laterCallbackRan = true;
    });
    app()->terminate();

    expect($laterCallbackRan)->toBeTrue();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'database is down');
});
