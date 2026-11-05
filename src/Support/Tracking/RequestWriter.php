<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

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
 * earlier attempt saved and changes nothing. Stats, budget alerts and
 * exports run after the row is saved and report their own failures: one
 * of them failing must neither lose the row nor make the job save it again.
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
     * Save a tracked request, then update its tags, review queues, daily
     * stats, budgets and integrations.
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
        [$request, $previous] = $this->save($attributes, $mayExist);

        // A tag that can't be saved must not keep the call out of the stats and budgets.
        if (filled($tags)) {
            rescue(fn () => $request->attachTags($tags));
        }

        if ($reviewQueues !== [] && filled($request->trace_id)) {
            $queues = app(AnnotationQueues::class);

            foreach ($reviewQueues as $queue) {
                rescue(fn () => $queues->add($queue, $request->trace_id, (string) $request->getKey()));
            }
        }

        if ($previous === null) {
            rescue(fn () => $this->statsAggregator->recordRequest($request));
        } else {
            rescue(fn () => $this->statsAggregator->recordChange($request, $previous));
        }

        $changed = $previous === null || $this->hasChanged($request, $previous);

        if ($changed && config('spectra.budget.enabled', true)) {
            rescue(fn () => app(BudgetEnforcer::class)->reportRecordedRequest($request));
        }

        if ($changed && $export) {
            rescue(fn () => $this->export($request));
        }

        return $request;
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
