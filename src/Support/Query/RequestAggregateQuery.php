<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
 */
class RequestAggregateQuery
{
    use AggregatesSuccessfulRequests;
    use FiltersByLayout;
    use FiltersTrackables;

    /** @var Builder<SpectraRequest> */
    protected Builder $query;

    private function __construct()
    {
        $this->query = SpectraRequest::query();
    }

    public static function make(?DateRange $dateRange = null): self
    {
        $instance = new self;

        if ($dateRange !== null) {
            $dateRange->apply($instance->query);
        }

        return $instance;
    }

    /** Only include successful (2xx) requests. */
    public function successfulOnly(): self
    {
        $this->query->whereBetween('status_code', [200, 299]);

        return $this;
    }

    /** Apply the dashboard layout's model-type filter. */
    public function forLayout(string $layout): self
    {
        $this->applyLayoutFilter($this->query, $layout);

        return $this;
    }

    /** Scope to a single polymorphic trackable. */
    public function forTrackable(string $type, string $id): self
    {
        $this->query
            ->where('trackable_type', $type)
            ->where('trackable_id', $id);

        return $this;
    }

    /** Only rows that belong to some trackable. */
    public function withTrackable(): self
    {
        $this->query
            ->whereNotNull('trackable_type')
            ->whereNotNull('trackable_id');

        return $this;
    }

    /** Only rows that belong to a trace. */
    public function withTrace(): self
    {
        $this->query->whereNotNull('trace_id');

        return $this;
    }

    /**
     * Only the calls of the given traces.
     *
     * @param  array<int, string>  $traceIds
     */
    public function forTraces(array $traceIds): self
    {
        $this->query->whereIn('trace_id', $traceIds);

        return $this;
    }

    /** Only the calls of one trace. */
    public function forTrace(string $traceId): self
    {
        $this->query->forTrace($traceId);

        return $this;
    }

    /**
     * Only rows of the traces the given query's trace ids name, so a trace
     * keeps all its calls in its totals even when only one call matched.
     *
     * @param  Builder<SpectraRequest>  $traceIds  a query selecting trace_id
     */
    public function inTraces(Builder $traceIds): self
    {
        $this->query->whereIn('trace_id', $traceIds);

        return $this;
    }

    /**
     * One trace's totals: calls, failures, cost (2xx only), tokens, start and
     * duration. A call ends at its start plus its latency (completed_at is
     * stored to the second), and rows saved before started_at existed start
     * at created_at.
     */
    public function traceTotals(): self
    {
        $start = $this->epochMilliseconds('COALESCE(started_at, created_at)');

        return $this
            ->countAs('request_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 0 ELSE 1 END), 0) as failed_count')
            ->successfulCost()
            ->tokenSum('tokens')
            ->selectRaw('MIN(COALESCE(started_at, created_at)) as first_started_at')
            ->selectRaw("MAX({$start} + COALESCE(latency_ms, 0)) - MIN({$start}) as duration_ms")
            ->selectRaw('COUNT(DISTINCT model) as model_count');
    }

    public function filteredBy(Request $request): self
    {
        $this->applyTrackableFilters($this->query, $request);

        return $this;
    }

    /**
     * COUNT(*) under the given alias.
     *
     * @param  literal-string  $alias
     */
    public function countAs(string $alias = 'count'): self
    {
        return $this->selectRaw("COUNT(*) as {$alias}");
    }

    /**
     * Cost over successful requests only (safe on unfiltered row sets).
     *
     * @param  literal-string  $alias
     */
    public function successfulCost(string $alias = 'cost'): self
    {
        return $this->selectRaw($this->successfulCostSum($alias));
    }

    /**
     * Latency over successful requests only.
     *
     * @param  literal-string  $alias
     */
    public function successfulLatency(string $alias = 'avg_latency'): self
    {
        return $this->selectRaw($this->successfulLatencyAvg($alias));
    }

    /**
     * Token sum under the given alias.
     *
     * @param  literal-string  $alias
     */
    public function tokenSum(string $alias = 'tokens_sum'): self
    {
        return $this->selectRaw("COALESCE(SUM(prompt_tokens + completion_tokens), 0) as {$alias}");
    }

    /** The per-modality usage sums shared by the model/type breakdowns. */
    public function usageSums(): self
    {
        return $this
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw('COALESCE(SUM(input_characters), 0) as input_characters_sum')
            ->selectRaw('COALESCE(SUM(duration_seconds), 0) as duration_seconds_sum');
    }

    /** The TTS/STT sums shared by the overview and trackable stats. */
    public function audioSums(): self
    {
        return $this
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0) as tts_characters_sum")
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN duration_seconds ELSE 0 END), 0) as tts_duration_sum")
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'stt' THEN duration_seconds ELSE 0 END), 0) as stt_duration_sum");
    }

    /** Image, video and audio usage as the trackables screens show it. */
    public function mediaSums(): self
    {
        return $this
            ->selectRaw('COALESCE(SUM(image_count), 0) as images_sum')
            ->selectRaw('COALESCE(SUM(video_count), 0) as videos_sum')
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type = 'tts' THEN input_characters ELSE 0 END), 0) as tts_characters_sum")
            ->selectRaw("COALESCE(SUM(CASE WHEN model_type IN ('tts', 'stt') THEN duration_seconds ELSE 0 END), 0) as audio_duration_sum");
    }

    /** Bucket rows by calendar date (adds select, group, and order). */
    public function byDate(): self
    {
        $this->query
            ->selectRaw('DATE(created_at) as date')
            ->groupBy('date')
            ->orderBy('date');

        return $this;
    }

    /** Group by the given columns, selecting them as dimensions. */
    public function groupByColumns(string ...$columns): self
    {
        $this->query->select($columns)->groupBy($columns);

        return $this;
    }

    /**
     * @param  literal-string  $expression
     */
    public function selectRaw(string $expression): self
    {
        $this->query->selectRaw($expression);

        return $this;
    }

    public function orderByDesc(string $column): self
    {
        $this->query->orderByDesc($column);

        return $this;
    }

    /**
     * @param  'asc'|'desc'  $direction
     */
    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->query->orderBy($column, $direction);

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->query->limit($limit);

        return $this;
    }

    /**
     * Milliseconds since the Unix epoch of a date expression, keeping its
     * fraction. Each driver spells this differently.
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
     * The built query, for wrapping it as a subquery.
     *
     * @return Builder<SpectraRequest>
     */
    public function toQuery(): Builder
    {
        return $this->query;
    }

    /** @return Collection<int, SpectraRequest> */
    public function get(): Collection
    {
        return $this->query->get();
    }

    public function first(): ?SpectraRequest
    {
        return $this->query->first();
    }

    /** @return LengthAwarePaginator<int, SpectraRequest> */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }

    /**
     * How many distinct combinations of the given columns the rows hold.
     *
     * Counted over a grouped subquery: COUNT(DISTINCT a, b) is MySQL-only and
     * concatenating the columns needs a different operator per driver.
     */
    public function countDistinct(string ...$columns): int
    {
        return $this->query->select($columns)->groupBy($columns)->toBase()->getCountForPagination();
    }

    public function sumCost(): float
    {
        return (float) $this->query->sum('total_cost_in_cents');
    }
}
