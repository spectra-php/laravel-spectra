<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards\Sections;

use Illuminate\Support\Facades\Date;
use Spectra\Data\Responses\DashboardCostsResponse;
use Spectra\Enums\ModelType;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use Spectra\Support\ProviderRegistry;
use stdClass;

/**
 * The trackable's spend for a shared dashboard's Costs page. Spend covers
 * successful calls; the request count covers every call.
 */
class GetDashboardCosts
{
    /** Models listed. */
    private const MODELS = 10;

    /**
     * Create a new get dashboard costs action instance.
     */
    public function __construct(
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * Get the trackable's total, its spend per day, and the biggest providers, models and types.
     */
    public function __invoke(DashboardScope $scope, DateRange $dateRange): DashboardCostsResponse
    {
        /** @var stdClass $totals */
        $totals = $scope->calls($dateRange)->toBase()
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::COST_SQL.' as cost')
            ->first();

        return new DashboardCostsResponse(
            total_cost: (float) $totals->cost,
            total_requests: (int) $totals->requests,
            by_date: $scope->calls($dateRange)->successful()->toBase()
                ->selectRaw('DATE(created_at) as day')
                ->selectRaw(DashboardScope::COST_SQL.' as cost')
                ->groupBy('day')
                ->orderBy('day')
                ->get()
                ->map(fn (stdClass $row) => ['date' => Date::parse((string) $row->day)->toDateString(), 'cost' => (float) $row->cost])
                ->all(),
            by_provider: $scope->calls($dateRange)->toBase()
                ->select('provider')
                ->selectRaw('COUNT(*) as requests')
                ->selectRaw(DashboardScope::COST_SQL.' as cost')
                ->groupBy('provider')
                ->orderByDesc('cost')
                ->get()
                ->map(fn (stdClass $row) => [
                    'provider' => $row->provider,
                    'provider_name' => $row->provider === null ? null : $this->providers->displayName($row->provider),
                    'requests' => (int) $row->requests,
                    'cost' => (float) $row->cost,
                ])
                ->all(),
            by_model: $scope->calls($dateRange)->toBase()
                ->select('provider', 'model')
                ->selectRaw('COUNT(*) as requests')
                ->selectRaw(DashboardScope::COST_SQL.' as cost')
                ->groupBy('provider', 'model')
                ->orderByDesc('cost')
                ->limit(self::MODELS)
                ->get()
                ->map(fn (stdClass $row) => [
                    'provider' => $row->provider,
                    'provider_name' => $row->provider === null ? null : $this->providers->displayName($row->provider),
                    'model' => (string) $row->model,
                    'requests' => (int) $row->requests,
                    'cost' => (float) $row->cost,
                ])
                ->all(),
            by_model_type: $scope->calls($dateRange)->toBase()
                ->select('model_type')
                ->selectRaw(DashboardScope::COST_SQL.' as cost')
                ->groupBy('model_type')
                ->orderByDesc('cost')
                ->get()
                ->map(fn (stdClass $row) => [
                    'model_type' => (string) ($row->model_type ?? 'unknown'),
                    'label' => is_string($row->model_type) && filled($row->model_type) ? (ModelType::tryFrom($row->model_type)?->label() ?? $row->model_type) : 'Unknown',
                    'cost' => (float) $row->cost,
                ])
                ->all(),
        );
    }
}
