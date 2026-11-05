<?php

declare(strict_types=1);

namespace Spectra\Support\Query;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Spectra\Data\CostBreakdown;
use Spectra\Models\SpectraRequest;
use Spectra\Support\DateRange;

/**
 * The fluent cost query behind `Spectra::costs()`: filter by provider,
 * model, trackable and period, then read totals or breakdowns in cents and
 * dollars.
 *
 * It starts from successful (2xx) requests only, because a failed call is
 * not billed; every count it returns is therefore a count of successful
 * requests. Its method names and result keys are public API.
 */
class CostQueryBuilder
{
    /** @var Builder<SpectraRequest> */
    protected Builder $query;

    /**
     * Create a new cost query over successful (2xx) requests.
     */
    public function __construct()
    {
        $this->query = SpectraRequest::query()->whereBetween('status_code', [200, 299]);
    }

    /**
     * Limit the query to requests to the given provider.
     */
    public function provider(string $provider): self
    {
        $this->query->where('provider', $provider);

        return $this;
    }

    /**
     * Limit the query to requests for the given model.
     */
    public function model(string $model): self
    {
        $this->query->where('model', $model);

        return $this;
    }

    /**
     * Limit the query to requests tracked for the given model (a user, team…).
     */
    public function forTrackable(Model $trackable): self
    {
        $this->query->where('trackable_type', $trackable->getMorphClass())
            ->where('trackable_id', $trackable->getKey());

        return $this;
    }

    /**
     * Limit the query to requests made between the two dates, the end date inclusive.
     */
    public function between(CarbonInterface|string $start, CarbonInterface|string $end): self
    {
        $start = $start instanceof CarbonInterface ? $start : Date::parse($start);

        $this->query->whereBetween('created_at', [$start, DateRange::inclusiveEnd($end)]);

        return $this;
    }

    /**
     * Limit the query to requests made since the start of today.
     */
    public function today(): self
    {
        return $this->between(today(), now());
    }

    /**
     * Limit the query to requests made since the start of this week.
     */
    public function thisWeek(): self
    {
        return $this->between(now()->startOfWeek(), now());
    }

    /**
     * Limit the query to requests made since the start of this month.
     */
    public function thisMonth(): self
    {
        return $this->between(now()->startOfMonth(), now());
    }

    /**
     * Limit the query to requests made in the last given number of days.
     */
    public function lastDays(int $days): self
    {
        return $this->between(now()->subDays($days), now());
    }

    /**
     * Get the total cost of the matching requests, in cents.
     */
    public function totalCents(): float
    {
        return (float) $this->query->sum('total_cost_in_cents');
    }

    /**
     * Get the total cost of the matching requests in whole currency units (cents / 100).
     */
    public function totalDollars(): float
    {
        return $this->totalCents() / 100;
    }

    /**
     * Get the prompt (input) cost of the matching requests, in cents.
     */
    public function promptCostCents(): float
    {
        return (float) $this->query->sum('prompt_cost');
    }

    /**
     * Get the completion (output) cost of the matching requests, in cents.
     */
    public function completionCostCents(): float
    {
        return (float) $this->query->sum('completion_cost');
    }

    /**
     * Get the average cost per matching request, in cents.
     */
    public function averageCostCents(): float
    {
        return (float) $this->query->avg('total_cost_in_cents');
    }

    /**
     * Get the request count and the prompt, completion, total and average cost.
     */
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
     * Get the request count and cost per provider, most expensive first.
     *
     * @return Collection<int, array{provider: string, request_count: int, total_cost_in_cents: float, total_cost: float}>
     */
    public function byProvider(): Collection
    {
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
                'request_count' => (int) $item->request_count,
                'total_cost_in_cents' => (float) $item->total_cost,
                'total_cost' => (float) $item->total_cost / 100,
            ]);
    }

    /**
     * Get the request count and cost per provider and model, most expensive first.
     *
     * @return Collection<int, array{provider: string, model: string, request_count: int, total_cost_in_cents: float, total_cost: float}>
     */
    public function byModel(): Collection
    {
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
                'request_count' => (int) $item->request_count,
                'total_cost_in_cents' => (float) $item->total_cost,
                'total_cost' => (float) $item->total_cost / 100,
            ]);
    }

    /**
     * Get the request count and cost per day for the last given number of days.
     *
     * @return Collection<int, array{date: mixed, request_count: int, total_cost_in_cents: float, total_cost: float}>
     */
    public function daily(int $days = 30): Collection
    {
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
                'request_count' => (int) $item->request_count,
                'total_cost_in_cents' => (float) $item->total_cost,
                'total_cost' => (float) $item->total_cost / 100,
            ]);
    }

    /**
     * Get the request count and cost per month for the last given number of months.
     *
     * @return Collection<int, array{year: int, month: int, period: string, request_count: int, total_cost_in_cents: float, total_cost: float}>
     */
    public function monthly(int $months = 12): Collection
    {
        $period = $this->monthlyPeriodExpression();

        return $this->between(now()->subMonths($months), now())
            ->query
            ->selectRaw("
                {$period} as period,
                SUM(total_cost_in_cents) as total_cost,
                COUNT(*) as request_count
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(function (SpectraRequest $item) {
                [$year, $month] = explode('-', (string) $item->period);

                return [
                    'year' => (int) $year,
                    'month' => (int) $month,
                    'period' => (string) $item->period,
                    'request_count' => (int) $item->request_count,
                    'total_cost_in_cents' => (float) $item->total_cost,
                    'total_cost' => (float) $item->total_cost / 100,
                ];
            });
    }

    /**
     * Build a driver-portable "YYYY-MM" expression for monthly grouping.
     *
     * @return literal-string
     */
    protected function monthlyPeriodExpression(): string
    {
        $driver = DB::connection(config('spectra.storage.connection'))->getDriverName();

        return match ($driver) {
            'sqlite' => "strftime('%Y-%m', created_at)",
            'pgsql' => "TO_CHAR(created_at, 'YYYY-MM')",
            default => "DATE_FORMAT(created_at, '%Y-%m')",
        };
    }

    /**
     * Get the trackables that spent the most, with their request count and cost.
     *
     * @return Collection<int, array{trackable_type: string|null, trackable_id: string|null, request_count: int, total_cost_in_cents: float, total_cost: float}>
     */
    public function topSpenders(int $limit = 10): Collection
    {
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
                'request_count' => (int) $item->request_count,
                'total_cost_in_cents' => (float) $item->total_cost,
                'total_cost' => (float) $item->total_cost / 100,
            ]);
    }

    /**
     * Get the underlying Eloquent query, with every filter applied so far.
     *
     * @return Builder<SpectraRequest>
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }
}
