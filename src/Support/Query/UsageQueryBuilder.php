<?php

namespace Spectra\Support\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spectra\Data\UsageStats;
use Spectra\Models\SpectraRequest;

class UsageQueryBuilder
{
    /** @var Builder<SpectraRequest> */
    protected Builder $query;

    protected ?Carbon $startDate = null;

    protected ?Carbon $endDate = null;

    public function __construct()
    {
        $this->query = SpectraRequest::query();
    }

    public function provider(string $provider): self
    {
        $this->query->where('provider', $provider);

        return $this;
    }

    public function model(string $model): self
    {
        $this->query->where('model', $model);

        return $this;
    }

    public function forTrackable(Model $trackable): self
    {
        $this->query->where('trackable_type', $trackable->getMorphClass())
            ->where('trackable_id', $trackable->getKey());

        return $this;
    }

    public function between(Carbon|string $start, Carbon|string $end): self
    {
        $this->startDate = $start instanceof Carbon ? $start : Carbon::parse($start);
        $this->endDate = $end instanceof Carbon ? $end : Carbon::parse($end);

        $this->query->whereBetween('created_at', [$this->startDate, $this->endDate]);

        return $this;
    }

    public function today(): self
    {
        return $this->between(now()->startOfDay(), now());
    }

    public function thisWeek(): self
    {
        return $this->between(now()->startOfWeek(), now());
    }

    public function thisMonth(): self
    {
        return $this->between(now()->startOfMonth(), now());
    }

    public function lastDays(int $days): self
    {
        return $this->between(now()->subDays($days), now());
    }

    /**
     * Filter for successful requests only (2xx status codes).
     */
    public function successful(): self
    {
        $this->query->whereBetween('status_code', [200, 299]);

        return $this;
    }

    /**
     * Filter for failed requests only (4xx/5xx or null status codes).
     */
    public function failed(): self
    {
        $this->query->where(function ($q) {
            $q->where('status_code', '>=', 400)
                ->orWhereNull('status_code');
        });

        return $this;
    }

    public function withTag(string $key, ?string $value = null): self
    {
        if ($value === null) {
            $this->query->whereHas('tags', function (Builder $query) use ($key) {
                $query->where('name', $key)
                    ->orWhere('name', 'like', $key.':%');
            });
        } else {
            $this->query->whereHas('tags', function (Builder $query) use ($key, $value) {
                $query->where('name', "{$key}:{$value}");
            });
        }

        return $this;
    }

    public function count(): int
    {
        return $this->query->count();
    }

    public function totalTokens(): int
    {
        return (int) $this->query->sum(DB::raw('prompt_tokens + completion_tokens'));
    }

    public function promptTokens(): int
    {
        return (int) $this->query->sum('prompt_tokens');
    }

    public function completionTokens(): int
    {
        return (int) $this->query->sum('completion_tokens');
    }

    public function averageLatency(): float
    {
        return round((float) $this->query->avg('latency_ms'), 2);
    }

    public function totalCostCents(): int
    {
        return (int) $this->query->sum('total_cost_in_cents');
    }

    public function totalCostDollars(): float
    {
        return round($this->totalCostCents() / 100, 4);
    }

    public function stats(): UsageStats
    {
        $result = $this->query
            ->selectRaw('
                COUNT(*) as request_count,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as successful_count,
                SUM(CASE WHEN status_code >= 400 OR status_code IS NULL THEN 1 ELSE 0 END) as failed_count,
                SUM(prompt_tokens) as prompt_tokens,
                SUM(completion_tokens) as completion_tokens,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost,
                AVG(latency_ms) as avg_latency_ms,
                MIN(latency_ms) as min_latency_ms,
                MAX(latency_ms) as max_latency_ms
            ')
            ->first();

        if ($result === null) {
            return new UsageStats(
                requestCount: 0,
                successfulCount: 0,
                failedCount: 0,
                successRate: 0,
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                totalCostInCents: 0,
                totalCost: 0.0,
                avgLatencyMs: 0.0,
                minLatencyMs: 0,
                maxLatencyMs: 0,
            );
        }

        /** @var object{request_count: mixed, successful_count: mixed, failed_count: mixed, prompt_tokens: mixed, completion_tokens: mixed, total_tokens: mixed, total_cost: mixed, avg_latency_ms: mixed, min_latency_ms: mixed, max_latency_ms: mixed} $result */
        return new UsageStats(
            requestCount: (int) $result->request_count,
            successfulCount: (int) $result->successful_count,
            failedCount: (int) $result->failed_count,
            successRate: $result->request_count > 0
                ? round(($result->successful_count / $result->request_count) * 100, 2)
                : 0,
            promptTokens: (int) $result->prompt_tokens,
            completionTokens: (int) $result->completion_tokens,
            totalTokens: (int) $result->total_tokens,
            totalCostInCents: (int) $result->total_cost,
            totalCost: round((int) $result->total_cost / 100, 4),
            avgLatencyMs: round((float) $result->avg_latency_ms, 2),
            minLatencyMs: (int) $result->min_latency_ms,
            maxLatencyMs: (int) $result->max_latency_ms,
        );
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function byProvider(): Collection
    {
        // @phpstan-ignore-next-line return.type
        return $this->query
            ->selectRaw('
                provider,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost,
                AVG(latency_ms) as avg_latency_ms
            ')
            ->groupBy('provider')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'provider' => $item->provider,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_tokens' => (int) $item->total_tokens,
                'total_cost_in_cents' => (int) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => round((int) $item->total_cost / 100, 4), // @phpstan-ignore property.notFound
                'avg_latency_ms' => round((float) $item->avg_latency_ms, 2), // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function byModel(): Collection
    {
        // @phpstan-ignore-next-line return.type
        return $this->query
            ->selectRaw('
                provider,
                model,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost,
                AVG(latency_ms) as avg_latency_ms
            ')
            ->groupBy('provider', 'model')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'provider' => $item->provider,
                'model' => $item->model,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_tokens' => (int) $item->total_tokens,
                'total_cost_in_cents' => (int) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => round((int) $item->total_cost / 100, 4), // @phpstan-ignore property.notFound
                'avg_latency_ms' => round((float) $item->avg_latency_ms, 2), // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function dailyUsage(int $days = 30): Collection
    {
        // @phpstan-ignore-next-line return.type
        return $this->lastDays($days)
            ->query
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as request_count,
                SUM(prompt_tokens + completion_tokens) as total_tokens,
                SUM(total_cost_in_cents) as total_cost
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'date' => $item->date,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_tokens' => (int) $item->total_tokens,
                'total_cost_in_cents' => (int) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => round((int) $item->total_cost / 100, 4), // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Builder<SpectraRequest>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * @return Collection<int, SpectraRequest>
     */
    public function get(): Collection
    {
        return $this->query->get();
    }

    /**
     * @return LengthAwarePaginator<int, SpectraRequest>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->query->paginate($perPage);
    }
}
