<?php

namespace Spectra\Support\Query;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Spectra\Data\CostBreakdown;
use Spectra\Models\SpectraRequest;

class CostQueryBuilder
{
    /** @var Builder<SpectraRequest> */
    protected Builder $query;

    public function __construct()
    {
        $this->query = SpectraRequest::query()->whereBetween('status_code', [200, 299]);
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
        $start = $start instanceof Carbon ? $start : Carbon::parse($start);
        $end = $end instanceof Carbon ? $end : Carbon::parse($end);

        $this->query->whereBetween('created_at', [$start, $end]);

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

    public function totalCents(): float
    {
        return (float) $this->query->sum('total_cost_in_cents');
    }

    public function totalDollars(): float
    {
        return $this->totalCents() / 100;
    }

    public function promptCostCents(): float
    {
        return (float) $this->query->sum('prompt_cost');
    }

    public function completionCostCents(): float
    {
        return (float) $this->query->sum('completion_cost');
    }

    public function averageCostCents(): float
    {
        return (float) $this->query->avg('total_cost_in_cents');
    }

    public function breakdown(): CostBreakdown
    {
        $result = $this->query
            ->selectRaw('
                COUNT(*) as request_count,
                SUM(prompt_cost) as prompt_cost,
                SUM(completion_cost) as completion_cost,
                SUM(total_cost_in_cents) as total_cost,
                AVG(total_cost_in_cents) as avg_cost
            ')
            ->first();

        if ($result === null) {
            return new CostBreakdown(
                requestCount: 0,
                promptCostInCents: 0.0,
                promptCost: 0.0,
                completionCostInCents: 0.0,
                completionCost: 0.0,
                totalCostInCents: 0.0,
                totalCost: 0.0,
                avgCostInCents: 0.0,
                avgCost: 0.0,
            );
        }

        /** @var object{request_count: mixed, prompt_cost: mixed, completion_cost: mixed, total_cost: mixed, avg_cost: mixed} $result */
        return new CostBreakdown(
            requestCount: (int) $result->request_count,
            promptCostInCents: (float) $result->prompt_cost,
            promptCost: (float) $result->prompt_cost / 100,
            completionCostInCents: (float) $result->completion_cost,
            completionCost: (float) $result->completion_cost / 100,
            totalCostInCents: (float) $result->total_cost,
            totalCost: (float) $result->total_cost / 100,
            avgCostInCents: (float) $result->avg_cost,
            avgCost: (float) $result->avg_cost / 100,
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
                SUM(total_cost_in_cents) as total_cost
            ')
            ->groupBy('provider')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'provider' => $item->provider,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_cost_in_cents' => (float) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => (float) $item->total_cost / 100, // @phpstan-ignore property.notFound
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
                SUM(total_cost_in_cents) as total_cost
            ')
            ->groupBy('provider', 'model')
            ->orderByDesc('total_cost')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'provider' => $item->provider,
                'model' => $item->model,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_cost_in_cents' => (float) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => (float) $item->total_cost / 100, // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function daily(int $days = 30): Collection
    {
        // @phpstan-ignore-next-line return.type
        return $this->lastDays($days)
            ->query
            ->selectRaw('
                DATE(created_at) as date,
                SUM(total_cost_in_cents) as total_cost,
                COUNT(*) as request_count
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'date' => $item->date,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_cost_in_cents' => (float) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => (float) $item->total_cost / 100, // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function monthly(int $months = 12): Collection
    {
        // @phpstan-ignore-next-line return.type
        return $this->between(now()->subMonths($months), now())
            ->query
            ->selectRaw('
                EXTRACT(YEAR FROM created_at) as year,
                EXTRACT(MONTH FROM created_at) as month,
                SUM(total_cost_in_cents) as total_cost,
                COUNT(*) as request_count
            ')
            ->groupBy('year', 'month')
            ->orderBy('year')
            ->orderBy('month')
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'year' => (int) $item->year, // @phpstan-ignore property.notFound
                'month' => (int) $item->month, // @phpstan-ignore property.notFound
                'period' => sprintf('%d-%02d', $item->year, $item->month), // @phpstan-ignore property.notFound, property.notFound
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_cost_in_cents' => (float) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => (float) $item->total_cost / 100, // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function topSpenders(int $limit = 10): Collection
    {
        // @phpstan-ignore-next-line return.type
        return $this->query
            ->selectRaw('
                trackable_type,
                trackable_id,
                SUM(total_cost_in_cents) as total_cost,
                COUNT(*) as request_count
            ')
            ->whereNotNull('trackable_type')
            ->groupBy('trackable_type', 'trackable_id')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get()
            ->map(fn (SpectraRequest $item) => [
                'trackable_type' => $item->trackable_type,
                'trackable_id' => $item->trackable_id,
                'request_count' => (int) $item->request_count, // @phpstan-ignore property.notFound
                'total_cost_in_cents' => (float) $item->total_cost, // @phpstan-ignore property.notFound
                'total_cost' => (float) $item->total_cost / 100, // @phpstan-ignore property.notFound
            ]);
    }

    /**
     * @return Builder<SpectraRequest>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
