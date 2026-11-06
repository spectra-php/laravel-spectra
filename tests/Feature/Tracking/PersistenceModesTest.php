<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Spectra\Contracts\RequestExporter;
use Spectra\Events\RequestTracked;
use Spectra\Facades\Spectra;
use Spectra\Jobs\ExportTrackedRequestJob;
use Spectra\Jobs\PersistSpectraRequestJob;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\RequestWriter;

/**
 * The three ways a tracked call reaches the database — synchronously, after
 * the response, or through PersistSpectraRequestJob — and the export to
 * integrations that follows the save.
 */

/**
 * Track a gpt-4o call that costs 2 cents.
 *
 * @param  array<string, mixed>  $options
 */
function trackPersistedCall(array $options = []): void
{
    // 4,000 prompt tokens × 250 cents/1M + 1,000 completion tokens × 1,000 cents/1M = 2 cents
    Spectra::track('openai', 'gpt-4o', fn () => [
        'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi']]],
        'usage' => ['prompt_tokens' => 4000, 'completion_tokens' => 1000],
    ], $options);
}

/**
 * Bind an exporter that keeps every payload it is given, in place of OpenTelemetry.
 */
function fakeRequestExporter(): RequestExporter
{
    $exporter = new class implements RequestExporter
    {
        /** @var list<array<string, mixed>> */
        public array $exported = [];

        public function export(array $data): void
        {
            $this->exported[] = $data;
        }

        public function exportBatch(array $requests): void
        {
            array_push($this->exported, ...$requests);
        }
    };

    app()->instance(RequestExporter::class, $exporter);

    return $exporter;
}

it('queues the write and saves nothing until PersistSpectraRequestJob runs', function () {
    Queue::fake();
    config()->set('spectra.queue.enabled', true);

    trackPersistedCall(['tags' => ['billing']]);
    $rowsBeforeJob = SpectraRequest::query()->count();
    Queue::pushed(PersistSpectraRequestJob::class)->each(fn (PersistSpectraRequestJob $job) => $job->handle(app(RequestWriter::class)));
    $request = SpectraRequest::query()->sole();
    $stat = SpectraDailyStat::query()->whereNull('trackable_type')->sole();

    expect($rowsBeforeJob)->toBe(0)
        ->and($request->total_cost_in_cents)->toEqualWithDelta(2.0, 1e-6)
        ->and($request->tags()->pluck('name')->all())->toBe(['billing'])
        ->and($stat->request_count)->toBe(1)
        ->and((float) $stat->total_cost_in_cents)->toEqualWithDelta(2.0, 1e-6);
    Queue::assertPushed(PersistSpectraRequestJob::class, 1);
});

it('writes the row when the app terminates in after-response mode', function () {
    config()->set('spectra.queue.after_response', true);
    // The test process is a console one, where after_response falls back to
    // a synchronous write; this call runs as a web request instead.
    (fn () => $this->isRunningInConsole = false)->call($this->app);

    trackPersistedCall(['tags' => ['billing']]);
    $rowsBeforeTerminate = SpectraRequest::query()->count();
    $this->app->terminate();
    $request = SpectraRequest::query()->sole();

    expect($rowsBeforeTerminate)->toBe(0)
        ->and($request->total_cost_in_cents)->toEqualWithDelta(2.0, 1e-6)
        ->and($request->tags()->pluck('name')->all())->toBe(['billing'])
        ->and(SpectraDailyStat::query()->whereNull('trackable_type')->sole()->request_count)->toBe(1);
});

it('writes the row at once in the console even with after-response mode on', function () {
    config()->set('spectra.queue.after_response', true);

    trackPersistedCall();

    expect(SpectraRequest::query()->count())->toBe(1);
});

it('exports a saved request once and announces it through RequestTracked', function () {
    Event::fake([RequestTracked::class]);
    config()->set('spectra.integrations.opentelemetry.enabled', true);
    $exporter = fakeRequestExporter();

    trackPersistedCall();
    $request = SpectraRequest::query()->sole();

    expect($exporter->exported)->toHaveCount(1)
        ->and($exporter->exported[0])->toMatchArray(['id' => $request->id, 'model' => 'GPT-4o', 'is_failed' => false])
        ->and((float) $exporter->exported[0]['total_cost_in_cents'])->toEqualWithDelta(2.0, 1e-6);
    Event::assertDispatched(RequestTracked::class, fn (RequestTracked $event) => $event->request['id'] === $request->id
        && $event->request['model'] === 'GPT-4o'
        && $event->request['is_failed'] === false);
});

it('exports inside the persist job in queue mode, without a separate export job', function () {
    Queue::fake();
    config()->set('spectra.queue.enabled', true);
    config()->set('spectra.integrations.opentelemetry.enabled', true);
    $exporter = fakeRequestExporter();

    trackPersistedCall();
    $exportedBeforeJob = count($exporter->exported);
    Queue::pushed(PersistSpectraRequestJob::class)->each(fn (PersistSpectraRequestJob $job) => $job->handle(app(RequestWriter::class)));

    expect($exportedBeforeJob)->toBe(0)
        ->and($exporter->exported)->toHaveCount(1)
        ->and($exporter->exported[0]['id'])->toBe(SpectraRequest::query()->sole()->id);
    Queue::assertNotPushed(ExportTrackedRequestJob::class);
});

it('queues ExportTrackedRequestJob for a request that is not stored', function () {
    Queue::fake();
    Event::fake([RequestTracked::class]);
    config()->set('spectra.storage.store_requests', false);
    config()->set('spectra.queue.enabled', true);
    config()->set('spectra.integrations.opentelemetry.enabled', true);
    $exporter = fakeRequestExporter();

    trackPersistedCall();
    Queue::pushed(ExportTrackedRequestJob::class)->each(fn (ExportTrackedRequestJob $job) => $job->handle($exporter));

    expect(SpectraRequest::query()->count())->toBe(0)
        ->and($exporter->exported)->toHaveCount(1)
        ->and($exporter->exported[0])->toMatchArray(['model' => 'GPT-4o', 'is_failed' => false]);
    Queue::assertNotPushed(PersistSpectraRequestJob::class);
    Event::assertDispatched(RequestTracked::class, fn (RequestTracked $event) => $event->request['model'] === 'GPT-4o');
});
