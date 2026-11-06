<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spectra\Contracts\RequestExporter;
use Spectra\Events\RequestTracked;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Annotations\AnnotationQueues;
use Spectra\Support\Budget\BudgetEnforcer;
use Spectra\Support\RequestTransformer;
use Spectra\Support\StatsAggregator;
use Throwable;

/**
 * Writes one tracked request to the database and keeps the daily stats,
 * budgets and integrations in step with it.
 *
 * Async jobs (OpenAI background responses and videos, Replicate and fal
 * predictions) are fetched several times under the same provider response id.
 * Those writes update the existing row instead of adding one, and only the
 * change is added to the daily stats, so a job polled ten times still counts
 * as one request with one cost.
 *
 * A queued write carries its row id, so a retried job finds the row an
 * earlier attempt saved and changes nothing. The row and its daily stats are
 * saved together, so a retry can neither count a call twice nor leave it out.
 * Tags, budget alerts and exports run after that and report their own
 * failures: one of them failing must neither lose the row nor make the job
 * save it again.
 *
 * Shared by the synchronous path and PersistSpectraRequestJob.
 */
class RequestWriter
{
    /** Metrics compared to decide whether an update changed anything worth reporting. */
    protected const TRACKED_METRICS = [
        'prompt_tokens',
        'completion_tokens',
        'cached_tokens',
        'cache_write_tokens',
        'reasoning_tokens',
        'image_count',
        'video_count',
        'page_count',
        'duration_seconds',
        'input_characters',
        'total_cost_in_cents',
        'status_code',
    ];

    /** How many times a transaction is tried when it deadlocks. */
    protected const TRANSACTION_ATTEMPTS = 3;

    /** Columns added after 2.0 that a table may not have until the app migrates. */
    protected const OPTIONAL_COLUMNS = ['started_at', 'origin', 'span_id', 'prompt_name', 'prompt_version', 'error_fingerprint'];

    /**
     * The optional columns this table turned out not to have, once an insert
     * failed on one (the writer is a singleton, so it is checked once).
     *
     * @var array<int, string>|null
     */
    protected ?array $missingColumns = null;

    /**
     * Create a new request writer instance.
     */
    public function __construct(
        protected StatsAggregator $statsAggregator,
        protected RequestTransformer $transformer,
        protected RequestExporter $exporter,
    ) {}

    /**
     * Save a tracked request and its daily stats, then update its tags, review
     * queues, budgets and integrations.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  bool  $export  Whether to export to integrations synchronously (the
     *                        persister handles queued export itself).
     * @param  list<string>  $reviewQueues  Review queues to put the call's trace in
     * @param  bool  $mayExist  Whether an earlier write may have saved this row (a
     *                          polled job, a retried queued job); false saves a lookup.
     */
    public function write(array $attributes, array $tags = [], bool $export = true, array $reviewQueues = [], bool $mayExist = true): SpectraRequest
    {
        $connection = (new SpectraRequest)->getConnection();

        if ($connection->transactionLevel() > 0) {
            return $this->writeInsideHostTransaction($connection, $attributes, $tags, $export, $reviewQueues, $mayExist);
        }

        [$request, $previous] = $this->saveWithStats($connection, $attributes, $mayExist);

        $this->finish($request, $previous, $tags, $export, $reviewQueues);

        return $request;
    }

    /**
     * Write while the host app's transaction is open on this connection.
     *
     * The row joins that transaction, behind a savepoint so a failed insert
     * can't abort the app's work. Everything else waits for its commit. A
     * daily stat row is shared by every call of a model, so writing it here
     * would hold that lock for as long as the app's transaction lasts, and two
     * transactions that track two models in a different order would deadlock,
     * which rolls back the app's own work. If the app rolls back, neither the
     * row nor its stats are kept.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tags
     * @param  list<string>  $reviewQueues
     */
    protected function writeInsideHostTransaction(Connection $connection, array $attributes, array $tags, bool $export, array $reviewQueues, bool $mayExist): SpectraRequest
    {
        [$request, $previous] = $connection->transaction(fn () => $this->save($attributes, $mayExist));

        $connection->afterCommit(function () use ($request, $previous, $tags, $export, $reviewQueues): void {
            rescue(fn () => $this->recordStats($request, $previous));

            $this->finish($request, $previous, $tags, $export, $reviewQueues);
        });

        return $request;
    }

    /**
     * Save the row and its daily stats in one transaction, so a crash between
     * the two can't leave a saved call out of the stats for good: a retried
     * job would find the row and add nothing. The stats come last, so the
     * rows every call shares stay locked for no longer than the commit, and a
     * deadlock is retried.
     *
     * When that fails for any other reason the row still has to be saved, so
     * it is saved on its own and the failure is reported.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: SpectraRequest, 1: SpectraRequest|null}
     */
    protected function saveWithStats(Connection $connection, array $attributes, bool $mayExist): array
    {
        try {
            return $connection->transaction(function () use ($attributes, $mayExist): array {
                [$request, $previous] = $this->save($attributes, $mayExist);

                $this->recordStats($request, $previous);

                return [$request, $previous];
            }, self::TRANSACTION_ATTEMPTS);
        } catch (Throwable) {
            [$request, $previous] = $this->save($attributes, $mayExist);

            rescue(fn () => $this->recordStats($request, $previous));

            return [$request, $previous];
        }
    }

    /**
     * Add the saved row to the daily stats, or only what changed for a row
     * that existed already.
     */
    protected function recordStats(SpectraRequest $request, ?SpectraRequest $previous): void
    {
        if ($previous === null) {
            $this->statsAggregator->recordRequest($request);

            return;
        }

        $this->statsAggregator->recordChange($request, $previous);
    }

    /**
     * Run what follows a saved call: its tags, review queues, budget alerts and
     * exports, each reporting its own failure.
     *
     * @param  array<int, string>  $tags
     * @param  list<string>  $reviewQueues
     */
    protected function finish(SpectraRequest $request, ?SpectraRequest $previous, array $tags, bool $export, array $reviewQueues): void
    {
        // A tag that can't be saved must not keep the call out of the budgets.
        if (filled($tags)) {
            rescue(fn () => $request->attachTags($tags));
        }

        if ($reviewQueues !== [] && filled($request->trace_id)) {
            $queues = app(AnnotationQueues::class);

            foreach ($reviewQueues as $queue) {
                rescue(fn () => $queues->add($queue, $request->trace_id, (string) $request->getKey()));
            }
        }

        $changed = $previous === null || $this->hasChanged($request, $previous);

        if ($changed && config('spectra.budget.enabled', true)) {
            rescue(fn () => app(BudgetEnforcer::class)->reportRecordedRequest($request));
        }

        if ($changed && $export) {
            rescue(fn () => $this->export($request));
        }
    }

    /**
     * Dispatch the RequestTracked event and export the request to OpenTelemetry when enabled.
     */
    public function export(SpectraRequest $request): void
    {
        $payload = $this->transformer->transform($request);

        RequestTracked::dispatch($payload);

        if (config('spectra.integrations.opentelemetry.enabled')) {
            $this->exporter->export($payload);
        }
    }

    /**
     * Insert the row, or update the row that already holds this provider
     * response id or row id. Returns the saved row and, for updates, its
     * previous values.
     *
     * The lookup comes before the insert on purpose: a failed insert aborts
     * the whole transaction on PostgreSQL, and the write may run inside the
     * host app's.
     *
     * @param  array<string, mixed>  $attributes
     * @return array{0: SpectraRequest, 1: SpectraRequest|null}
     */
    protected function save(array $attributes, bool $mayExist = true): array
    {
        $existing = $mayExist ? $this->findExisting($attributes) : null;

        if ($existing === null) {
            try {
                return [$this->create($attributes), null];
            } catch (UniqueConstraintViolationException $e) {
                // Another worker saved the same response id or row id first.
                $existing = $this->findExisting($attributes) ?? throw $e;
            }
        }

        $previous = clone $existing;

        // Keep the original row identity, start time, origin and place in the
        // trace (a poll may run in another job). A status poll carries no request body, so keep the
        // one from the request that started the job.
        $keep = ['id', 'created_at', 'started_at', 'origin', 'span_id', 'parent_span_id'];

        if (blank($attributes['request'] ?? null) && filled($existing->getAttribute('request'))) {
            $keep[] = 'request';
        }

        // Never replace a known model with "unknown" from a poll that couldn't name it.
        if (($attributes['model'] ?? null) === 'unknown' && $existing->getAttribute('model') !== 'unknown') {
            $keep[] = 'model';
        }

        $existing->fill(Arr::except($this->withoutMissingColumns($attributes), $keep))->save();

        return [$existing, $previous];
    }

    /**
     * Insert a new row.
     *
     * An app that updated Spectra but hasn't run a migration that adds
     * optional columns must keep recording its calls, so when the table
     * lacks some, the row is saved without exactly those (and a warning
     * naming them is logged once per process).
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function create(array $attributes): SpectraRequest
    {
        try {
            return SpectraRequest::query()->create($this->withoutMissingColumns($attributes));
        } catch (QueryException $e) {
            throw_if($e instanceof UniqueConstraintViolationException || ! $this->isMissingColumn($e) || $this->missingColumns !== null, $e);

            $this->missingColumns = $this->detectMissingColumns();

            if ($this->missingColumns !== []) {
                $columns = collect($this->missingColumns)->implode(', ');

                Log::warning("Spectra: spectra_requests has no {$columns} column yet, so calls are saved without them. Run `php artisan spectra:install` to publish and run the new migrations.");
            }

            return SpectraRequest::query()->create($this->withoutMissingColumns($attributes));
        }
    }

    /**
     * Leave out the optional columns the table is known not to have.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function withoutMissingColumns(array $attributes): array
    {
        return $this->missingColumns ? Arr::except($attributes, $this->missingColumns) : $attributes;
    }

    /**
     * Get the optional columns the requests table doesn't have.
     *
     * @return array<int, string>
     */
    protected function detectMissingColumns(): array
    {
        $model = new SpectraRequest;
        $existing = $model->getConnection()->getSchemaBuilder()->getColumnListing($model->getTable());

        return collect(self::OPTIONAL_COLUMNS)->diff($existing)->values()->all();
    }

    /**
     * Determine if the insert failed on a column the table doesn't have.
     *
     * Checked by error code, not message: Postgres quotes the failed SQL,
     * which names every column.
     */
    protected function isMissingColumn(QueryException $exception): bool
    {
        $code = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        // MySQL, MariaDB and SQL Server use 42S22, Postgres 42703; SQLite only says so in words.
        return in_array($code, ['42S22', '42703'], true)
            || Str::contains((string) ($exception->errorInfo[2] ?? ''), 'has no column named');
    }

    /**
     * Find the row an earlier write saved, by provider response id or by row
     * id, in one query. The response id wins: a poll of a job saved under
     * another row id must update that row.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function findExisting(array $attributes): ?SpectraRequest
    {
        $responseId = $attributes['response_id'] ?? null;
        $id = $attributes['id'] ?? null;

        if (blank($responseId) && blank($id)) {
            return null;
        }

        $rows = SpectraRequest::query()
            ->where(fn (Builder $match) => $match
                ->when(filled($responseId), fn (Builder $byResponse) => $byResponse->orWhere('response_id', $responseId))
                ->when(filled($id), fn (Builder $byId) => $byId->orWhere('id', $id)))
            ->limit(2)
            ->get();

        return $rows->first(fn (SpectraRequest $row) => filled($responseId) && $row->response_id === $responseId)
            ?? $rows->first();
    }

    /**
     * Determine if an update changed any metric the stats, budgets or exports report.
     */
    protected function hasChanged(SpectraRequest $request, SpectraRequest $previous): bool
    {
        foreach (self::TRACKED_METRICS as $field) {
            if ((string) $request->getAttribute($field) !== (string) $previous->getAttribute($field)) {
                return true;
            }
        }

        return false;
    }
}
