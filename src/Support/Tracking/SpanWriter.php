<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Spectra\Contracts\ExportsSpans;
use Spectra\Contracts\RequestExporter;
use Spectra\Jobs\PersistSpectraSpanJob;
use Spectra\Models\SpectraSpan;
use Throwable;

/**
 * Stores finished spans the way requests are stored: now, after the
 * response, or on the queue, as `spectra.queue` says, and exports them when
 * the exporter can take spans.
 *
 * A span is written once per span id, so a retried queue job changes
 * nothing. Until the app runs the migration that adds the spans table,
 * spans are skipped with one warning; a failure never reaches the app.
 */
class SpanWriter
{
    /** Whether the missing spans table was already reported (the writer is a singleton). */
    protected bool $hasWarnedAboutMissingTable = false;

    /**
     * Create a new span writer instance.
     */
    public function __construct(
        protected RequestExporter $exporter,
    ) {}

    /**
     * Record a span now, after the response or on the queue, as the config says.
     *
     * @param  array<string, mixed>  $attributes
     * @param  bool  $deferrable  Whether after_response may delay it; a span that
     *                            ends while the app terminates is written now.
     */
    public function record(array $attributes, bool $deferrable = true): void
    {
        if (! config('spectra.enabled', true)) {
            return;
        }

        rescue(function () use ($attributes, $deferrable): void {
            if (config('spectra.queue.enabled')) {
                $this->dispatch(new PersistSpectraSpanJob($attributes));

                return;
            }

            if ($deferrable && config('spectra.queue.after_response') && ! app()->runningInConsole()) {
                app()->terminating(fn () => rescue(fn () => $this->save($attributes)));

                return;
            }

            $this->save($attributes);
        });
    }

    /**
     * Save a span to the database and export it to OpenTelemetry when enabled.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function save(array $attributes): void
    {
        if (config('spectra.storage.store_requests', true)) {
            try {
                SpectraSpan::query()->create($attributes);
            } catch (UniqueConstraintViolationException) {
                // An earlier attempt saved this span already.
                return;
            } catch (QueryException $e) {
                throw_unless($this->isMissingTable($e), $e);

                $this->warnAboutMissingTable();

                return;
            }
        }

        if (config('spectra.integrations.opentelemetry.enabled') && $this->exporter instanceof ExportsSpans) {
            rescue(fn () => $this->exporter->exportSpan($this->exportData(new SpectraSpan($attributes))));
        }
    }

    /**
     * The span's columns with dates as dates and enums as their values.
     *
     * @return array<string, mixed>
     */
    protected function exportData(SpectraSpan $span): array
    {
        return [
            'trace_id' => $span->trace_id,
            'span_id' => $span->span_id,
            'parent_span_id' => $span->parent_span_id,
            'name' => $span->name,
            'kind' => $span->kind->value,
            'origin' => $span->origin,
            'started_at' => $span->started_at,
            'ended_at' => $span->ended_at,
            'duration_ms' => $span->duration_ms,
            'status' => $span->status->value,
            'error_type' => $span->error_type,
            'error_message' => $span->error_message,
            'attributes' => $span->attributes ?? [],
        ];
    }

    /**
     * Dispatch the span job on the configured connection, queue and delay.
     */
    protected function dispatch(PersistSpectraSpanJob $job): void
    {
        if ($connection = config('spectra.queue.connection')) {
            $job->onConnection($connection);
        }

        if ($queue = config('spectra.queue.queue')) {
            $job->onQueue($queue);
        }

        if ($delay = config('spectra.queue.delay')) {
            $job->delay($delay);
        }

        dispatch($job);
    }

    /**
     * Checked by error code, not message: Postgres quotes the failed SQL.
     */
    protected function isMissingTable(Throwable $e): bool
    {
        $code = $e instanceof QueryException ? (string) ($e->errorInfo[0] ?? $e->getCode()) : '';

        // MySQL, MariaDB and SQL Server use 42S02, Postgres 42P01; SQLite only says so in words.
        return in_array($code, ['42S02', '42P01'], true)
            || ($e instanceof QueryException && str_contains((string) ($e->errorInfo[2] ?? ''), 'no such table'));
    }

    /**
     * Log once per writer that the spans table is missing.
     */
    protected function warnAboutMissingTable(): void
    {
        if ($this->hasWarnedAboutMissingTable) {
            return;
        }

        $this->hasWarnedAboutMissingTable = true;

        Log::warning('Spectra: the spectra_spans table does not exist yet, so spans are not saved. Run `php artisan spectra:install` to publish and run the new migration.');
    }
}
