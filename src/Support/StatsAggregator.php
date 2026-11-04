<?php

declare(strict_types=1);

namespace Spectra\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use Spectra\Models\SpectraDailyStat;
use Spectra\Models\SpectraRequest;

/**
 * Keeps the pre-aggregated spectra_daily_stats rows in step with
 * spectra_requests: one global row and, when the request has a trackable, one
 * row for that trackable, per day / provider / model / model type.
 */
class StatsAggregator
{
    /**
     * Add a tracked request to its day's stats, overall and for its trackable.
     */
    public function recordRequest(SpectraRequest $request): void
    {
        $this->apply($request, $this->amounts($request), $request->latency_ms);
    }

    /**
     * Add only what changed when an existing request row was updated (e.g. an
     * async job polled again, or a queued job that finished).
     */
    public function recordChange(SpectraRequest $current, SpectraRequest $previous): void
    {
        $now = $this->amounts($current);
        $before = $this->amounts($previous);

        $delta = [];
        foreach ($now as $column => $value) {
            $change = $value - ($before[$column] ?? 0);

            // Usage only grows as a job progresses; a smaller number means a
            // poll returned less detail, not that usage went away. Only the
            // success/failure split may move in both directions.
            $delta[$column] = in_array($column, ['successful_count', 'failed_count'], true) ? $change : max(0, $change);
        }

        // Still one request; only its outcome may have changed.
        $delta['request_count'] = 0;

        $latency = $current->latency_ms !== null && $current->latency_ms !== $previous->latency_ms
            ? $current->latency_ms
            : null;

        $this->apply($current, $delta, $latency);
    }

    /**
     * Get the counts, tokens, units and cost one request adds to the daily stats.
     *
     * @return array<string, int|float>
     */
    protected function amounts(SpectraRequest $request): array
    {
        $successful = $request->isSuccessful();
        $promptTokens = $request->prompt_tokens ?? 0;
        $completionTokens = $request->completion_tokens ?? 0;

        return [
            'request_count' => 1,
            'successful_count' => $successful ? 1 : 0,
            'failed_count' => $successful ? 0 : 1,
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
            'total_cost_in_cents' => (float) ($request->total_cost_in_cents ?? 0),
            'total_latency_ms' => $request->latency_ms ?? 0,
        ];
    }

    /**
     * Add the amounts to the request's daily stat rows, overall and for its trackable.
     *
     * @param  array<string, int|float>  $amounts
     */
    protected function apply(SpectraRequest $request, array $amounts, ?int $latencyMs): void
    {
        $date = $request->created_at?->format('Y-m-d') ?? now()->format('Y-m-d');

        $rows = [[null, null]];

        if ($request->trackable_type && $request->trackable_id !== null) {
            $rows[] = [$request->trackable_type, $request->trackable_id];
        }

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
     * Useful for backfilling or fixing stat inconsistencies.
     */
    public function rebuild(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): int
    {
        // whereDate on both bounds: SQLite stores the date cast as
        // "Y-m-d 00:00:00", so a plain comparison with "Y-m-d" would skip the
        // end day's rows and the rebuild would count that day twice.
        SpectraDailyStat::query()
            ->when($startDate, fn ($query, CarbonInterface $start) => $query->whereDate('date', '>=', $start->format('Y-m-d')))
            ->when($endDate, fn ($query, CarbonInterface $end) => $query->whereDate('date', '<=', $end->format('Y-m-d')))
            ->delete();

        $requestQuery = SpectraRequest::query()
            // Only what recordRequest() reads: the request/response payloads
            // are long text and would make every chunk heavy.
            ->select([
                'id', 'created_at', 'provider', 'model', 'model_type', 'trackable_type', 'trackable_id',
                'status_code', 'prompt_tokens', 'completion_tokens', 'cached_tokens', 'cache_write_tokens',
                'reasoning_tokens', 'image_count', 'video_count', 'page_count', 'duration_seconds',
                'input_characters', 'total_cost_in_cents', 'latency_ms',
            ])
            ->when($startDate, fn ($query, CarbonInterface $start) => $query->where('created_at', '>=', $start->copy()->startOfDay()))
            ->when($endDate, fn ($query, CarbonInterface $end) => $query->where('created_at', '<=', $end->copy()->endOfDay()));

        $count = 0;
        $requestQuery->chunkById(1000, function ($requests) use (&$count) {
            foreach ($requests as $request) {
                $this->recordRequest($request);
                $count++;
            }
        });

        return $count;
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
