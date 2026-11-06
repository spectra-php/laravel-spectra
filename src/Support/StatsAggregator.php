<?php

declare(strict_types=1);

namespace Spectra\Support;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\DailyStatUpsert;
use Spectra\Support\Query\RollupSchema;

/**
 * Keeps the pre-aggregated spectra_daily_stats rows in step with
 * spectra_requests: one global row and, when the request has a trackable, one
 * row for that trackable, per day / provider / model / model type.
 *
 * Like every live query, it counts failed requests but adds cost and latency
 * for successful ones only, so the daily stats agree with the dashboard and
 * stay right after the requests they came from are pruned.
 */
class StatsAggregator
{
    /** Rows saved per statement when rebuilding a day. */
    protected const REBUILD_BATCH = 500;

    /**
     * Add a tracked request to its day's stats, overall and for its trackable.
     */
    public function recordRequest(SpectraRequest $request): void
    {
        $this->apply($request, $this->amounts($request), $this->latencyOf($request));
    }

    /**
     * Add only what changed when an existing request row was updated (e.g. an
     * async job polled again, or a queued job that finished).
     */
    public function recordChange(SpectraRequest $current, SpectraRequest $previous): void
    {
        $currentAmounts = $this->amounts($current);
        $previousAmounts = $this->amounts($previous);

        // Cost and latency count for successful requests only, so when the
        // outcome flips (a job polled as running, then as failed) they move
        // with it, in either direction.
        $outcomeChanged = $current->isSuccessful() !== $previous->isSuccessful();

        $delta = [];
        foreach ($currentAmounts as $column => $value) {
            $change = $value - ($previousAmounts[$column] ?? 0);

            // Usage only grows as a job progresses; a smaller number means a
            // poll returned less detail, not that usage went away. Only the
            // success/failure split, and what follows it, may move in both directions.
            $movesBothWays = in_array($column, ['successful_count', 'failed_count'], true)
                || ($outcomeChanged && in_array($column, ['total_cost_in_cents', 'total_latency_ms', 'latency_samples'], true));

            $delta[$column] = $movesBothWays ? $change : max(0, $change);
        }

        // Still one request; only its outcome may have changed.
        $delta['request_count'] = 0;

        $latency = $this->latencyOf($current);

        if ($latency === $previous->latency_ms && ! $outcomeChanged) {
            $latency = null;
        }

        $this->apply($current, $delta, $latency);
    }

    /**
     * Get the counts, tokens, units and cost one request adds to the daily stats.
     *
     * @return array<string, int|float>
     */
    protected function amounts(SpectraRequest $request): array
    {
        $isSuccessful = $request->isSuccessful();
        $promptTokens = $request->prompt_tokens ?? 0;
        $completionTokens = $request->completion_tokens ?? 0;
        $latencyMs = $this->latencyOf($request);

        return [
            'request_count' => 1,
            'successful_count' => $isSuccessful ? 1 : 0,
            'failed_count' => $isSuccessful ? 0 : 1,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'total_cached_tokens' => $request->cached_tokens ?? 0,
            'total_cache_write_tokens' => $request->cache_write_tokens ?? 0,
            'total_reasoning_tokens' => $request->reasoning_tokens ?? 0,
            'total_images' => $request->image_count ?? 0,
            'total_videos' => $request->video_count ?? 0,
            'total_pages' => $request->page_count ?? 0,
            'total_duration_seconds' => (float) ($request->duration_seconds ?? 0),
            'total_input_characters' => $request->input_characters ?? 0,
            'total_cost_in_cents' => $isSuccessful ? (float) ($request->total_cost_in_cents ?? 0) : 0.0,
            'total_latency_ms' => $latencyMs ?? 0,
            'latency_samples' => $latencyMs !== null ? 1 : 0,
        ];
    }

    /**
     * Get the latency a request adds to the daily stats: its own when it
     * succeeded, none when it failed (a timeout or an error answer says
     * nothing about how fast the model is).
     */
    protected function latencyOf(SpectraRequest $request): ?int
    {
        return $request->isSuccessful() ? $request->latency_ms : null;
    }

    /**
     * Add the amounts to the request's daily stat rows, overall and for its trackable.
     *
     * @param  array<string, int|float>  $amounts
     */
    protected function apply(SpectraRequest $request, array $amounts, ?int $latencyMs): void
    {
        $date = $request->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        $rows = [];

        if ($request->trackable_type && $request->trackable_id !== null) {
            $rows[] = [$request->trackable_type, $request->trackable_id];
        }

        // The global row comes last. Every call of a model writes it, so inside
        // a transaction it should be locked for no longer than the commit, and
        // one order for everyone means two calls can't wait for each other.
        $rows[] = [null, null];

        foreach ($rows as [$trackableType, $trackableId]) {
            SpectraDailyStat::accumulate(
                date: $date,
                provider: $request->provider ?? 'unknown',
                model: $request->model,
                modelType: $request->model_type,
                trackableType: $trackableType,
                trackableId: $trackableId,
                increments: $amounts,
                latencyMs: $latencyMs,
            );
        }
    }

    /**
     * Rebuild the daily stats from the stored requests, optionally between two
     * dates, and return how many requests were counted.
     *
     * Useful for backfilling or fixing stat inconsistencies. The database adds
     * up each day and the rows replace that day's stats in one transaction, so
     * readers never see a day half rebuilt. A call saved on a day while that
     * day is being rebuilt can be missed; rebuild finished days, or rebuild
     * today again afterwards.
     */
    public function rebuild(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): int
    {
        $requests = SpectraRequest::query()
            ->when($startDate, fn ($query, CarbonInterface $start) => $query->where('created_at', '>=', $start->copy()->startOfDay()))
            ->when($endDate, fn ($query, CarbonInterface $end) => $query->where('created_at', '<=', $end->copy()->endOfDay()));

        $oldest = $requests->clone()->min('created_at');
        $newest = $requests->clone()->max('created_at');

        if ($oldest === null || $newest === null) {
            $this->statsBetween($startDate, $endDate)->delete();

            return 0;
        }

        $firstDay = Date::parse($oldest)->startOfDay();
        $lastDay = Date::parse($newest)->startOfDay();

        // Days around the stored requests have nothing to rebuild from.
        $this->statsBetween($startDate, $firstDay->copy()->subDay())->delete();
        $this->statsBetween($lastDay->copy()->addDay(), $endDate)->delete();

        $count = 0;

        for ($day = $firstDay; $day->lessThanOrEqualTo($lastDay); $day = $day->copy()->addDay()) {
            $count += $this->rebuildDay($day);
        }

        return $count;
    }

    /**
     * Get the daily stats between two dates, both included.
     *
     * @return Builder<SpectraDailyStat>
     */
    protected function statsBetween(?CarbonInterface $start, ?CarbonInterface $end): Builder
    {
        // whereDate on both bounds: SQLite stores the date cast as
        // "Y-m-d 00:00:00", so a plain comparison with "Y-m-d" would skip the
        // end day's rows and the rebuild would count that day twice.
        return SpectraDailyStat::query()
            ->when($start, fn ($query, CarbonInterface $from) => $query->whereDate('date', '>=', $from->format('Y-m-d')))
            ->when($end, fn ($query, CarbonInterface $to) => $query->whereDate('date', '<=', $to->format('Y-m-d')));
    }

    /**
     * Replace one day's stats and return how many requests it holds.
     */
    protected function rebuildDay(CarbonInterface $day): int
    {
        $hasKey = app(RollupSchema::class)->hasStatKey();
        $global = $this->groupedRows($day, ['provider', 'model', 'model_type'], $hasKey);
        $byTrackable = $this->groupedRows($day, ['provider', 'model', 'model_type', 'trackable_type', 'trackable_id'], $hasKey)
            ->filter(fn (array $row) => $row['trackable_type'] !== null && $row['trackable_id'] !== null);

        (new SpectraDailyStat)->getConnection()->transaction(function () use ($day, $global, $byTrackable, $hasKey): void {
            $this->statsBetween($day, $day)->delete();

            foreach ($global->concat($byTrackable)->chunk(self::REBUILD_BATCH) as $rows) {
                $rows = $rows->values()->all();

                if ($hasKey) {
                    SpectraDailyStat::query()->upsert($rows, ['stat_key'], [...DailyStatUpsert::COLUMNS, 'min_latency_ms', 'max_latency_ms', 'updated_at']);
                } else {
                    SpectraDailyStat::query()->insert($rows);
                }
            }
        });

        return (int) $global->sum('request_count');
    }

    /**
     * Add up one day's requests by the given columns, as daily stat rows.
     *
     * @param  list<string>  $dimensions
     * @return Collection<int, non-empty-array<array-key, mixed>>
     */
    protected function groupedRows(CarbonInterface $day, array $dimensions, bool $hasKey): Collection
    {
        $successful = SpectraRequest::SUCCESSFUL_SQL;
        $stat = new SpectraDailyStat;
        $date = $stat->fromDateTime($day->copy()->startOfDay());
        $now = $stat->fromDateTime(Date::now());

        return SpectraRequest::query()
            ->toBase()
            ->select($dimensions)
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->selectRaw('COUNT(*) as request_count')
            ->selectRaw("SUM(CASE WHEN {$successful} THEN 1 ELSE 0 END) as successful_count")
            ->selectRaw("SUM(CASE WHEN {$successful} THEN 0 ELSE 1 END) as failed_count")
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens')
            ->selectRaw('SUM(completion_tokens) as completion_tokens')
            ->selectRaw('SUM(prompt_tokens + completion_tokens) as total_tokens')
            ->selectRaw('SUM(reasoning_tokens) as total_reasoning_tokens')
            ->selectRaw('SUM(cached_tokens) as total_cached_tokens')
            ->selectRaw('SUM(cache_write_tokens) as total_cache_write_tokens')
            ->selectRaw('SUM(COALESCE(image_count, 0)) as total_images')
            ->selectRaw('SUM(COALESCE(video_count, 0)) as total_videos')
            ->selectRaw('SUM(COALESCE(page_count, 0)) as total_pages')
            ->selectRaw('SUM(COALESCE(duration_seconds, 0)) as total_duration_seconds')
            ->selectRaw('SUM(COALESCE(input_characters, 0)) as total_input_characters')
            ->selectRaw("SUM(CASE WHEN {$successful} THEN total_cost_in_cents ELSE 0 END) as total_cost_in_cents")
            ->selectRaw("SUM(CASE WHEN {$successful} THEN COALESCE(latency_ms, 0) ELSE 0 END) as total_latency_ms")
            ->selectRaw("SUM(CASE WHEN {$successful} AND latency_ms IS NOT NULL THEN 1 ELSE 0 END) as latency_samples")
            ->selectRaw("MIN(CASE WHEN {$successful} THEN latency_ms END) as min_latency_ms")
            ->selectRaw("MAX(CASE WHEN {$successful} THEN latency_ms END) as max_latency_ms")
            ->groupBy($dimensions)
            ->get()
            ->map(function (object $row) use ($stat, $date, $now, $hasKey, $day): array {
                $values = (array) $row;
                $values['trackable_type'] ??= null;
                $values['trackable_id'] ??= null;

                if ($hasKey) {
                    $values['stat_key'] = DailyStatUpsert::key($day->format('Y-m-d'), $values['provider'], $values['model'], $values['model_type'], $values['trackable_type'], $values['trackable_id']);
                } else {
                    unset($values['latency_samples']);
                }

                return [
                    ...$values,
                    'id' => $stat->newUniqueId(),
                    'date' => $date,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });
    }

    /**
     * Rebuild the daily stats of one day from the stored requests.
     */
    public function rebuildForDate(CarbonInterface|string $date): int
    {
        $date = $date instanceof CarbonInterface ? $date : Date::parse($date);

        return $this->rebuild($date->copy()->startOfDay(), $date->copy()->endOfDay());
    }

    /**
     * Rebuild stats for yesterday (useful for scheduled task).
     */
    public function rebuildYesterday(): int
    {
        return $this->rebuildForDate(now()->subDay());
    }
}
