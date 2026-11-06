<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Concerns\AggregatesSuccessfulRequests;
use Spectra\Support\Concerns\FiltersByLayout;
use Spectra\Support\Concerns\FiltersTrackables;
use Spectra\Support\DateRange;

/**
 * Internal fluent builder for the dashboard's aggregate queries.
 *
 * Owns the scaffolding every dashboard aggregate repeats — date-range
 * application, success filtering, layout and trackable scoping, and the
 * shared aggregate select bundles — so each Action only declares the parts
 * unique to its endpoint. Not part of the public package API; user-facing
 * querying lives in CostQueryBuilder / UsageQueryBuilder.
 *
 * Every aggregate alias it selects is cast where it is selected (SUM and AVG
 * come back as strings on MySQL and PostgreSQL), so Actions read typed values
 * and never cast them again.
 *
 * Started with fromRollups(), the same methods read spectra_daily_stats instead
 * of scanning the requests whenever those can answer: the columns the screens
 * group by have the same names, and the rows hold the same convention (counts
 * include failures, cost and latency cover successful calls only). Rows that
 * belong to no scope below are the global ones, so nothing counts twice.
 */
class RequestAggregateQuery
{
    use AggregatesSuccessfulRequests;
    use FiltersByLayout;
    use FiltersTrackables;

    /** @var Builder<SpectraRequest> */
    protected Builder $query;

    /** Whether this query reads the daily stats. */
    protected bool $onRollups = false;

    /** Whether the query already narrowed the daily stats to trackable rows. */
    protected bool $hasTrackableScope = false;

    /** The daily stat column a count reads: every call, or the successful ones. */
    protected string $countColumn = 'request_count';

    /**
     * Create a new aggregate query over all tracked requests.
     */
    private function __construct()
    {
        $this->query = SpectraRequest::query();
    }

    /**
     * Start an aggregate query, limited to the date range when one is given.
     */
    public static function make(?DateRange $dateRange = null): self
    {
        $instance = new self;

        if ($dateRange !== null) {
            $dateRange->apply($instance->query);
        }

        return $instance;
    }

    /**
     * Start an aggregate query that reads the daily stats when they can answer
     * for the range, and the requests otherwise.
     */
    public static function fromRollups(?DateRange $dateRange = null): self
    {
        if (! app(RollupSchema::class)->covers($dateRange)) {
            return self::make($dateRange);
        }

        $instance = new self;
        $instance->onRollups = true;
        $instance->query->from('spectra_daily_stats');
        $dateRange?->apply($instance->query, 'date');

        return $instance;
    }

    /**
     * Start an aggregate query for the trackables screens' filters: on the daily
     * stats unless a filter needs individual calls.
     */
    public static function forTrackableFilters(?DateRange $dateRange, Request $request): self
    {
        return $request->anyFilled(['status', 'finish_reason', 'has_tool_calls', 'trace_id', 'tag', 'tags'])
            ? self::make($dateRange)
            : self::fromRollups($dateRange);
    }

    /**
     * Determine if this query reads the daily stats.
     */
    public function isOnRollups(): bool
    {
        return $this->onRollups;
    }

    /**
     * Limit the query to successful requests (SpectraRequest::successful()).
     */
    public function successfulOnly(): self
    {
        if ($this->onRollups) {
            $this->query->where('successful_count', '>', 0);
            $this->countColumn = 'successful_count';

            return $this;
        }

        $this->query->successful();

        return $this;
    }

    /**
     * Limit the query to calls that cost nothing. On the daily stats a group
     * qualifies when all of its calls did.
     */
    public function withoutCost(): self
    {
        if ($this->onRollups) {
            $this->query->havingRaw('SUM(total_cost_in_cents) = 0');

            return $this;
        }

        $this->query->where('total_cost_in_cents', 0);

        return $this;
    }

    /**
     * Apply the dashboard layout's model-type filter.
     */
    public function forLayout(string $layout): self
    {
        $this->applyLayoutFilter($this->query, $layout);

        return $this;
    }

    /**
     * Scope the query to a single polymorphic trackable.
     */
    public function forTrackable(string $type, string $id): self
    {
        $this->hasTrackableScope = true;

        $this->query
            ->where('trackable_type', $type)
            ->where('trackable_id', $id);

        return $this;
    }

    /**
     * Scope the query to every trackable of the given types (morph classes).
     *
     * @param  array<int, string>  $types
     */
    public function forTrackableTypes(array $types): self
    {
        $this->hasTrackableScope = true;
        $this->avoidIndexOrderedGrouping();

        $this->query->whereIn('trackable_type', $types);

        return $this;
    }

    /**
     * Limit the query to rows that belong to some trackable.
     */
    public function withTrackable(): self
    {
        $this->hasTrackableScope = true;
        $this->avoidIndexOrderedGrouping();

        $this->query
            ->whereNotNull('trackable_type')
            ->whereNotNull('trackable_id');

        return $this;
    }

    /**
     * Limit the query to rows that name a prompt template.
     */
    public function withPrompt(): self
    {
        $this->query->whereNotNull('prompt_name');

        return $this;
    }

    /**
     * Limit the query to rows that belong to a trace.
     */
    public function withTrace(): self
    {
        $this->query->whereNotNull('trace_id');

        return $this;
    }

    /**
     * Limit the query to the calls of the given traces.
     *
     * @param  array<int, string>  $traceIds
     */
    public function forTraces(array $traceIds): self
    {
        $this->query->whereIn('trace_id', $traceIds);

        return $this;
    }

    /**
     * Limit the query to rows that belong to a conversation.
     */
    public function withConversation(): self
    {
        $this->query->whereNotNull('conversation_id');

        return $this;
    }

    /**
     * Limit the query to the calls of one conversation.
     */
    public function forConversation(string $conversationId): self
    {
        $this->query->where('conversation_id', $conversationId);

        return $this;
    }

    /**
     * Limit the query to the calls of the given conversations.
     *
     * @param  array<int, string>  $conversationIds
     */
    public function forConversations(array $conversationIds): self
    {
        $this->query->whereIn('conversation_id', $conversationIds);

        return $this;
    }

    /**
     * Limit the query to rows of the conversations the given query's
     * conversation ids name, so a conversation keeps all its calls in its totals.
     *
     * @param  Builder<SpectraRequest>  $conversationIds  a query selecting conversation_id
     */
    public function inConversations(Builder $conversationIds): self
    {
        $this->query->whereIn('conversation_id', $conversationIds);

        return $this;
    }

    /**
     * Limit the query to the calls of one trace.
     */
    public function forTrace(string $traceId): self
    {
        $this->query->forTrace($traceId);

        return $this;
    }

    /**
     * Limit the query to rows of the traces the given query's trace ids name,
     * so a trace keeps all its calls in its totals even when only one call matched.
     *
     * @param  Builder<SpectraRequest>  $traceIds  a query selecting trace_id
     */
    public function inTraces(Builder $traceIds): self
    {
        $this->query->whereIn('trace_id', $traceIds);

        return $this;
    }

    /**
     * Select the totals of a group of calls (a trace, a conversation): calls,
     * failures, cost (2xx only), tokens, first and last start, and duration.
     *
     * A call ends at its start plus its latency (completed_at is stored to the
     * second), and rows saved before started_at existed start at created_at.
     */
    public function callTotals(): self
    {
        $start = $this->epochMilliseconds('COALESCE(started_at, created_at)');

        return $this
            ->countAs('request_count')
            ->failedCount()
            ->successfulCost()
            ->tokenSum('tokens')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) as first_started_at')
            ->selectRaw('MAX(COALESCE(started_at, created_at)) as last_started_at')
            ->aggregate("COALESCE(MAX({$start} + COALESCE(latency_ms, 0)) - MIN({$start}), 0)", 'duration_ms', 'float')
            ->aggregate('COUNT(DISTINCT model)', 'model_count', 'integer');
    }

    /**
     * Select a conversation's totals: the call totals plus its turns.
     *
     * Calls with the same turn number are one turn; a call without one is a
     * turn of its own.
     */
    public function conversationTotals(): self
    {
        return $this
            ->callTotals()
            ->aggregate('COUNT(DISTINCT conversation_turn) + COALESCE(SUM(CASE WHEN conversation_turn IS NULL THEN 1 ELSE 0 END), 0)', 'turn_count', 'integer');
    }

    /**
     * Apply the dashboard's trackable filters from the request.
     */
    public function filteredBy(Request $request): self
    {
        $this->applyTrackableFilters($this->query, $request);

        return $this;
    }

    /**
     * Select COUNT(*) under the given alias.
     *
     * @param  literal-string  $alias
     */
    public function countAs(string $alias = 'count'): self
    {
        if ($this->onRollups) {
            return $this->aggregate($this->countColumn === 'successful_count' ? 'COALESCE(SUM(successful_count), 0)' : 'COALESCE(SUM(request_count), 0)', $alias, 'integer');
        }

        return $this->aggregate('COUNT(*)', $alias, 'integer');
    }

    /**
     * Select the count of failed requests (SpectraRequest::FAILED_SQL) under the given alias.
     *
     * @param  literal-string  $alias
     */
    public function failedCount(string $alias = 'failed_count'): self
    {
        return $this->aggregate($this->onRollups ? 'COALESCE(SUM(failed_count), 0)' : $this->failedCountExpression(), $alias, 'integer');
    }

    /**
     * Select the cost over successful requests only (safe on unfiltered row sets).
     *
     * @param  literal-string  $alias
     */
    public function successfulCost(string $alias = 'cost'): self
    {
        return $this->aggregate($this->onRollups ? 'COALESCE(SUM(total_cost_in_cents), 0)' : $this->successfulCostExpression(), $alias, 'float');
    }

    /**
     * Select the average latency over successful requests only; 0 when none succeeded.
     *
     * @param  literal-string  $alias
     */
    public function successfulLatency(string $alias = 'avg_latency'): self
    {
        if ($this->onRollups) {
            // Rows saved before latency_samples existed counted every successful call.
            return $this->aggregate('COALESCE(1.0 * SUM(total_latency_ms) / NULLIF(SUM(COALESCE(latency_samples, successful_count)), 0), 0)', $alias, 'float');
        }

        return $this->aggregate("COALESCE({$this->successfulLatencyExpression()}, 0)", $alias, 'float');
    }

    /**
     * Select the token sum under the given alias.
     *
     * @param  literal-string  $alias
     */
    public function tokenSum(string $alias = 'tokens_sum'): self
    {
        return $this->aggregate($this->onRollups ? 'COALESCE(SUM(total_tokens), 0)' : 'COALESCE(SUM(prompt_tokens + completion_tokens), 0)', $alias, 'integer');
    }

    /**
     * Select the per-modality usage sums shared by the model/type breakdowns.
     */
    public function usageSums(): self
    {
        if ($this->onRollups) {
            return $this
                ->aggregate('COALESCE(SUM(total_images), 0)', 'images_sum', 'integer')
                ->aggregate('COALESCE(SUM(total_videos), 0)', 'videos_sum', 'integer')
                ->aggregate('COALESCE(SUM(total_input_characters), 0)', 'input_characters_sum', 'integer')
                ->aggregate('COALESCE(SUM(total_duration_seconds), 0)', 'duration_seconds_sum', 'float');
        }

        return $this
            ->aggregate('COALESCE(SUM(image_count), 0)', 'images_sum', 'integer')
            ->aggregate('COALESCE(SUM(video_count), 0)', 'videos_sum', 'integer')
            ->aggregate('COALESCE(SUM(input_characters), 0)', 'input_characters_sum', 'integer')
            ->aggregate('COALESCE(SUM(duration_seconds), 0)', 'duration_seconds_sum', 'float');
    }

    /**
     * Select the TTS/STT sums shared by the overview and trackable stats.
     */
    public function audioSums(): self
    {
        if ($this->onRollups) {
            return $this
                ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN total_input_characters ELSE 0 END), 0)", 'tts_characters_sum', 'integer')
                ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN total_duration_seconds ELSE 0 END), 0)", 'tts_duration_sum', 'float')
                ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'stt' THEN total_duration_seconds ELSE 0 END), 0)", 'stt_duration_sum', 'float');
        }

        return $this
            ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0)", 'tts_characters_sum', 'integer')
            ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN duration_seconds ELSE 0 END), 0)", 'tts_duration_sum', 'float')
            ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'stt' THEN duration_seconds ELSE 0 END), 0)", 'stt_duration_sum', 'float');
    }

    /**
     * Select image, video and audio usage as the trackables screens show it.
     */
    public function mediaSums(): self
    {
        if ($this->onRollups) {
            return $this
                ->aggregate('COALESCE(SUM(total_images), 0)', 'images_sum', 'integer')
                ->aggregate('COALESCE(SUM(total_videos), 0)', 'videos_sum', 'integer')
                ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN total_input_characters ELSE 0 END), 0)", 'tts_characters_sum', 'integer')
                ->aggregate("COALESCE(SUM(CASE WHEN model_type IN ('tts', 'stt') THEN total_duration_seconds ELSE 0 END), 0)", 'audio_duration_sum', 'float');
        }

        return $this
            ->aggregate('COALESCE(SUM(image_count), 0)', 'images_sum', 'integer')
            ->aggregate('COALESCE(SUM(video_count), 0)', 'videos_sum', 'integer')
            ->aggregate("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0)", 'tts_characters_sum', 'integer')
            ->aggregate("COALESCE(SUM(CASE WHEN model_type IN ('tts', 'stt') THEN duration_seconds ELSE 0 END), 0)", 'audio_duration_sum', 'float');
    }

    /**
     * Bucket rows by calendar date (adds select, group, and order).
     */
    public function byDate(): self
    {
        $this->query
            ->selectRaw($this->onRollups ? 'DATE(date) as date' : 'DATE(created_at) as date')
            ->groupBy('date')
            ->orderBy('date');

        return $this;
    }

    /**
     * Group by the given columns, selecting them as dimensions.
     */
    public function groupByColumns(string ...$columns): self
    {
        $this->query->select($columns)->groupBy($columns);

        return $this;
    }

    /**
     * Select an aggregate under an alias, cast to its type.
     *
     * @param  literal-string  $expression
     * @param  literal-string  $alias
     * @param  'integer'|'float'  $cast
     */
    protected function aggregate(string $expression, string $alias, string $cast): self
    {
        $this->query->selectRaw("{$expression} as {$alias}")->withCasts([$alias => $cast]);

        return $this;
    }

    /**
     * Add a raw select expression to the query.
     *
     * @param  literal-string  $expression
     */
    public function selectRaw(string $expression): self
    {
        $this->query->selectRaw($expression);

        return $this;
    }

    /**
     * Order the results by the column, highest first.
     */
    public function orderByDesc(string $column): self
    {
        $this->query->orderByDesc($column);

        return $this;
    }

    /**
     * Order the results by the column in the given direction.
     *
     * @param  'asc'|'desc'  $direction
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    /**
     * Limit the number of rows returned.
     */
    public function limit(int $limit): self
    {
        $this->query->limit($limit);

        return $this;
    }

    /**
     * Build the milliseconds since the Unix epoch of a date expression,
     * keeping its fraction.
     *
     * Each driver spells this differently.
     *
     * @param  literal-string  $expression
     * @return literal-string
     */
    protected function epochMilliseconds(string $expression): string
    {
        return match ($this->query->getModel()->getConnection()->getDriverName()) {
            'sqlite' => "((julianday({$expression}) - 2440587.5) * 86400000.0)",
            'pgsql' => "(EXTRACT(EPOCH FROM {$expression}) * 1000)",
            'sqlsrv' => "CAST(DATEDIFF_BIG(millisecond, '1970-01-01', {$expression}) AS FLOAT)",
            default => "(UNIX_TIMESTAMP({$expression}) * 1000)",
        };
    }

    /**
     * Get the built query, for wrapping it as a subquery.
     *
     * @return Builder<SpectraRequest>
     */
    public function toQuery(): Builder
    {
        $this->scopeToGlobalRows();

        return $this->query;
    }

    /**
     * Get the aggregated rows.
     *
     * @return Collection<int, SpectraRequest>
     */
    public function get(): Collection
    {
        return $this->toQuery()->get();
    }

    /**
     * Get the first aggregated row.
     */
    public function first(): ?SpectraRequest
    {
        return $this->toQuery()->first();
    }

    /**
     * Get the aggregated rows a page at a time.
     *
     * @return LengthAwarePaginator<int, SpectraRequest>
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->toQuery()->paginate($perPage);
    }

    /**
     * Count the distinct combinations of the given columns the rows hold.
     *
     * Counted over a grouped subquery: COUNT(DISTINCT a, b) is MySQL-only and
     * concatenating the columns needs a different operator per driver.
     */
    public function countDistinct(string ...$columns): int
    {
        return $this->toQuery()->select($columns)->groupBy($columns)->toBase()->getCountForPagination();
    }

    /**
     * Get the summed cost of the matching requests, in cents.
     */
    public function sumCost(): float
    {
        return (float) $this->toQuery()->sum('total_cost_in_cents');
    }

    /**
     * Keep MySQL from reading every trackable's rows through this index in
     * order to group them: over a long period that is one row lookup per stat,
     * several times slower than a scan of the table. The date index still
     * serves short periods.
     */
    protected function avoidIndexOrderedGrouping(): void
    {
        $connection = $this->query->getModel()->getConnection();

        if ($this->onRollups && in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) && $connection->getTablePrefix() === '') {
            $this->query->from(new Expression('spectra_daily_stats IGNORE INDEX (spectra_daily_stats_trackable_date)'));
        }
    }

    /**
     * Narrow the daily stats to the global rows, once, unless the query asked
     * for trackable rows: every call is in both, and must count once.
     */
    protected function scopeToGlobalRows(): void
    {
        if (! $this->onRollups || $this->hasTrackableScope) {
            return;
        }

        $this->hasTrackableScope = true;
        $this->query->whereNull('trackable_type');
    }
}
