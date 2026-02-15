<?php

namespace Spectra\Concerns;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Spectra\Data\UsageStats;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-ignore trait.unused
 */
trait HasAiUsageStats
{
    public function aiUsageToday(): UsageStats
    {
        return $this->getAiUsageForPeriod(now()->startOfDay(), now());
    }

    public function aiUsageThisWeek(): UsageStats
    {
        return $this->getAiUsageForPeriod(now()->startOfWeek(), now());
    }

    public function aiUsageThisMonth(): UsageStats
    {
        return $this->getAiUsageForPeriod(now()->startOfMonth(), now());
    }

    public function getAiUsageForPeriod(Carbon|string $start, Carbon|string $end): UsageStats
    {
        $stats = $this->aiRequests()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COUNT(*) as request_count,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as successful_count,
                SUM(CASE WHEN status_code >= 400 OR status_code IS NULL THEN 1 ELSE 0 END) as failed_count,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost_in_cents,
                AVG(latency_ms) as avg_latency_ms
            ')
            ->first();

        return new UsageStats(
            requestCount: (int) $stats->request_count,
            successfulCount: (int) $stats->successful_count,
            failedCount: (int) $stats->failed_count,
            successRate: $stats->request_count > 0
                ? round(($stats->successful_count / $stats->request_count) * 100, 2)
                : 0,
            totalTokens: (int) $stats->total_tokens,
            totalCostInCents: (int) $stats->total_cost_in_cents,
            totalCost: round((int) $stats->total_cost_in_cents / 100, 4),
            avgLatencyMs: round((float) $stats->avg_latency_ms, 2),
        );
    }

    public function getAiUsageByProvider(Carbon|string|null $start = null, Carbon|string|null $end = null): array
    {
        $start = $start ?? now()->startOfMonth();
        $end = $end ?? now();

        return $this->aiRequests()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                provider,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost_in_cents
            ')
            ->groupBy('provider')
            ->get()
            ->mapWithKeys(fn ($item) => [
                $item->provider => [
                    'request_count' => (int) $item->request_count,
                    'total_tokens' => (int) $item->total_tokens,
                    'total_cost_in_cents' => (int) $item->total_cost_in_cents,
                    'total_cost' => round((int) $item->total_cost_in_cents / 100, 4),
                ],
            ])
            ->toArray();
    }

    public function getAiUsageByModel(Carbon|string|null $start = null, Carbon|string|null $end = null): array
    {
        $start = $start ?? now()->startOfMonth();
        $end = $end ?? now();

        return $this->aiRequests()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                provider,
                model,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost_in_cents
            ')
            ->groupBy('provider', 'model')
            ->get()
            ->map(fn ($item) => [
                'provider' => $item->provider,
                'model' => $item->model,
                'request_count' => (int) $item->request_count,
                'total_tokens' => (int) $item->total_tokens,
                'total_cost_in_cents' => (int) $item->total_cost_in_cents,
                'total_cost' => round((int) $item->total_cost_in_cents / 100, 4),
            ])
            ->toArray();
    }

    public function recentAiRequests(int $limit = 10): Collection
    {
        return $this->aiRequests()
            ->latest('created_at')
            ->limit($limit)
            ->get();
    }

    public function expensiveAiRequests(int $limit = 10): Collection
    {
        return $this->aiRequests()
            ->orderByDesc('total_cost_in_cents')
            ->limit($limit)
            ->get();
    }
}
