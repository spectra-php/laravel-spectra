<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Spectra\Data\UsageStats;
use Spectra\Support\DateRange;

/**
 * Usage numbers for one trackable model (a user, a team): requests, tokens,
 * cost and latency over a period, and the same broken down by provider or
 * model.
 *
 * Request counts include failed requests; cost and latency cover successful
 * (2xx) requests only, like the dashboard. A date-only end ("2026-01-31")
 * includes that whole day.
 *
 * @phpstan-require-extends Model
 *
 * @phpstan-ignore trait.unused
 */
trait HasAiUsageStats
{
    /**
     * Get this model's AI usage since the start of today.
     */
    public function aiUsageToday(): UsageStats
    {
        return $this->getAiUsageForPeriod(today(), now());
    }

    /**
     * Get this model's AI usage since the start of this week.
     */
    public function aiUsageThisWeek(): UsageStats
    {
        return $this->getAiUsageForPeriod(now()->startOfWeek(), now());
    }

    /**
     * Get this model's AI usage since the start of this month.
     */
    public function aiUsageThisMonth(): UsageStats
    {
        return $this->getAiUsageForPeriod(now()->startOfMonth(), now());
    }

    /**
     * Get this model's request counts, tokens, cost and latency between two dates.
     */
    public function getAiUsageForPeriod(CarbonInterface|string $start, CarbonInterface|string $end): UsageStats
    {
        $stats = $this->aiRequests()
            ->whereBetween('created_at', [$start, DateRange::inclusiveEnd($end)])
            ->selectRaw('
                COUNT(*) as request_count,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as successful_count,
                SUM(CASE WHEN status_code IS NULL OR status_code NOT BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as failed_count,
                SUM(prompt_tokens + completion_tokens) as tokens_sum,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN total_cost_in_cents ELSE 0 END) as total_cost_in_cents,
                AVG(CASE WHEN status_code BETWEEN 200 AND 299 THEN latency_ms END) as avg_latency_ms
            ')
            ->first();

        $requestCount = (int) $stats->request_count;
        $successfulCount = (int) $stats->successful_count;
        $totalCostInCents = (float) $stats->total_cost_in_cents;

        return new UsageStats(
            requestCount: $requestCount,
            successfulCount: $successfulCount,
            failedCount: (int) $stats->failed_count,
            successRate: $requestCount > 0
                ? round(($successfulCount / $requestCount) * 100, 2)
                : 0,
            totalTokens: (int) $stats->tokens_sum,
            totalCostInCents: $totalCostInCents,
            totalCost: round($totalCostInCents / 100, 4),
            avgLatencyMs: round((float) $stats->avg_latency_ms, 2),
        );
    }

    /**
     * Get this model's requests, tokens and cost per provider, this month by default.
     */
    public function getAiUsageByProvider(CarbonInterface|string|null $start = null, CarbonInterface|string|null $end = null): array
    {
        $start ??= now()->startOfMonth();
        $end ??= now();

        return $this->aiRequests()
            ->whereBetween('created_at', [$start, DateRange::inclusiveEnd($end)])
            ->selectRaw('
                provider,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as tokens_sum,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN total_cost_in_cents ELSE 0 END) as total_cost_in_cents
            ')
            ->groupBy('provider')
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->provider => [
                    'request_count' => (int) $item->request_count,
                    'total_tokens' => (int) $item->tokens_sum,
                    'total_cost_in_cents' => (float) $item->total_cost_in_cents,
                    'total_cost' => round((float) $item->total_cost_in_cents / 100, 4),
                ],
            ])
            ->toArray();
    }

    /**
     * Get this model's requests, tokens and cost per provider and model, this month by default.
     */
    public function getAiUsageByModel(CarbonInterface|string|null $start = null, CarbonInterface|string|null $end = null): array
    {
        $start ??= now()->startOfMonth();
        $end ??= now();

        return $this->aiRequests()
            ->whereBetween('created_at', [$start, DateRange::inclusiveEnd($end)])
            ->selectRaw('
                provider,
                model,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as tokens_sum,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN total_cost_in_cents ELSE 0 END) as total_cost_in_cents
            ')
            ->groupBy('provider', 'model')
            ->get()
            ->map(fn ($item) => [
                'provider' => $item->provider,
                'model' => $item->model,
                'request_count' => (int) $item->request_count,
                'total_tokens' => (int) $item->tokens_sum,
                'total_cost_in_cents' => (float) $item->total_cost_in_cents,
                'total_cost' => round((float) $item->total_cost_in_cents / 100, 4),
            ])
            ->toArray();
    }

    /**
     * Get this model's most recent AI requests.
     */
    public function recentAiRequests(int $limit = 10): Collection
    {
        return $this->aiRequests()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get this model's AI requests that cost the most.
     */
    public function expensiveAiRequests(int $limit = 10): Collection
    {
        return $this->aiRequests()
            ->orderByDesc('total_cost_in_cents')
            ->limit($limit)
            ->get();
    }
}
