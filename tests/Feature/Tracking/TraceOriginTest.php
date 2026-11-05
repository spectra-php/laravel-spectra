<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;
use Spectra\Support\Tracking\TraceOrigin;
use Spectra\Tests\Fixtures\OriginJobMode;
use Spectra\Tests\Fixtures\RecordsOriginJob;

/**
 * Every call stores where it came from (the job, Artisan command or route)
 * and when it started to the millisecond, so traces can name and order
 * their calls.
 */

/**
 * Pretend the app serves HTTP instead of running in the console.
 */
function runningInConsole(bool $console): void
{
    $property = new ReflectionProperty(app(), 'isRunningInConsole');
    $property->setValue(app(), $console);
}

beforeEach(function () {
    config()->set('queue.default', 'sync');
    RecordsOriginJob::$origins = [];
});

afterEach(function () {
    runningInConsole(true);
});

it('names a call by the route pattern of the HTTP request', function () {
    runningInConsole(false);

    Route::post('/chats/{chat}', fn () => ['origin' => (new RequestContext)->origin]);

    $this->postJson('/chats/42?token=secret')
        ->assertOk()
        ->assertJson(['origin' => 'POST /chats/{chat}']);
});

it('names nothing for an HTTP request without a matched route', function () {
    runningInConsole(false);

    expect(app(TraceOrigin::class)->current())->toBeNull();
});

it('names a call by the queued job running it', function () {
    dispatch(new RecordsOriginJob);

    expect(RecordsOriginJob::$origins)->toBe([RecordsOriginJob::class])
        ->and(app(TraceOrigin::class)->current())->toBeNull();
});

it('names a call by the innermost job and returns to the outer one after it', function () {
    dispatch(new RecordsOriginJob(OriginJobMode::Nested));

    expect(RecordsOriginJob::$origins)->toBe([RecordsOriginJob::class, RecordsOriginJob::class, RecordsOriginJob::class])
        ->and(app(TraceOrigin::class)->current())->toBeNull();
});

it('forgets a job that threw', function () {
    expect(fn () => dispatch(new RecordsOriginJob(OriginJobMode::Fail)))->toThrow(RuntimeException::class);

    expect(RecordsOriginJob::$origins)->toBe([RecordsOriginJob::class])
        ->and(app(TraceOrigin::class)->current())->toBeNull();
});

it('prefers the job over the HTTP request that runs it synchronously', function () {
    runningInConsole(false);

    Route::post('/summaries', function () {
        dispatch(new RecordsOriginJob);

        return ['origin' => (new RequestContext)->origin];
    });

    $this->postJson('/summaries')->assertJson(['origin' => 'POST /summaries']);

    expect(RecordsOriginJob::$origins)->toBe([RecordsOriginJob::class]);
});

it('names a call by the Artisan command the user ran', function () {
    // Laravel only reports command events outside unit tests (see WithConsoleEvents).
    app(ConsoleKernel::class)->rerouteSymfonyCommandEvents();

    $origins = [];

    Artisan::command('spectra:inner-origin', function () use (&$origins) {
        $origins[] = (new RequestContext)->origin;
    });

    Artisan::command('spectra:outer-origin', function () use (&$origins) {
        $origins[] = (new RequestContext)->origin;
        $this->call('spectra:inner-origin');
        $origins[] = (new RequestContext)->origin;
    });

    Artisan::call('spectra:outer-origin');

    expect($origins)->toBe(['artisan spectra:outer-origin', 'artisan spectra:outer-origin', 'artisan spectra:outer-origin'])
        ->and(app(TraceOrigin::class)->current())->toBeNull();
});

it('keeps an origin given to the context', function () {
    app(TraceOrigin::class)->startCommand('reports:send');

    expect((new RequestContext(['origin' => 'nightly-digest']))->origin)->toBe('nightly-digest');
});

it('captures no origin when capture_origin is off', function () {
    config()->set('spectra.tracking.capture_origin', false);
    app(TraceOrigin::class)->startCommand('reports:send');

    expect((new RequestContext)->origin)->toBeNull();
});

it('caps the origin at the column length', function () {
    app(TraceOrigin::class)->startCommand(Str::repeat('a', 400));

    expect(Str::length((string) app(TraceOrigin::class)->current()))->toBe(255);
});

it('stores the start time to the millisecond and the origin', function () {
    app(TraceOrigin::class)->startCommand('reports:send');

    $context = new RequestContext([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'started_at' => Date::parse('2026-11-03 10:15:30.123'),
    ]);
    $context->complete(['id' => 'resp-origin'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

    app(RequestPersister::class)->persist($context);

    $row = SpectraRequest::query()->sole();

    expect($row->started_at?->format('Y-m-d H:i:s.v'))->toBe('2026-11-03 10:15:30.123')
        ->and($row->created_at?->format('Y-m-d H:i:s'))->toBe('2026-11-03 10:15:30')
        ->and($row->origin)->toBe('artisan reports:send');
});

it('keeps the start time and origin of the call that started an async job', function () {
    $writer = app(RequestWriter::class);

    $writer->write([
        'provider' => 'openai',
        'model' => 'sora-2',
        'response_id' => 'video_123',
        'status_code' => 200,
        'origin' => 'POST /videos',
        'started_at' => Date::parse('2026-11-03 10:00:00.250'),
        'created_at' => Date::parse('2026-11-03 10:00:00'),
    ]);

    $writer->write([
        'provider' => 'openai',
        'model' => 'sora-2',
        'response_id' => 'video_123',
        'status_code' => 200,
        'origin' => 'App\Jobs\PollVideo',
        'started_at' => Date::parse('2026-11-03 10:02:00.900'),
        'created_at' => Date::parse('2026-11-03 10:02:00'),
    ]);

    $row = SpectraRequest::query()->sole();

    expect($row->origin)->toBe('POST /videos')
        ->and($row->started_at?->format('H:i:s.v'))->toBe('10:00:00.250');
});

it('keeps recording calls before the app runs the trace columns migration', function () {
    Log::spy();

    $migration = include __DIR__.'/../../../database/migrations/2026_11_03_000001_add_trace_timing_columns_to_spectra_tables.php';
    $migration->down();

    app(TraceOrigin::class)->startCommand('reports:send');

    foreach (['resp-a', 'resp-b'] as $responseId) {
        $context = new RequestContext(['provider' => 'openai', 'model' => 'gpt-4o']);
        $context->complete(['id' => $responseId], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

        app(RequestPersister::class)->persist($context);
    }

    expect(SpectraRequest::query()->count())->toBe(2);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => Str::contains($message, 'spectra:install'));

    // Migrating again (as spectra:install would) brings the columns back.
    $migration->up();

    expect(Schema::hasColumns('spectra_requests', ['started_at', 'origin']))->toBeTrue();
});

it('still reports insert errors that are not a missing trace column', function () {
    expect(fn () => app(RequestWriter::class)->write(['provider' => 'openai']))
        ->toThrow(QueryException::class);
});
