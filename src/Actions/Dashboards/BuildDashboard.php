<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Spectra\Data\DashboardBreakdown;
use Spectra\Data\DashboardModelTypeRow;
use Spectra\Data\DashboardUsageRow;
use Spectra\Data\DashboardWidgetData;
use Spectra\Data\DataTransferObject;
use Spectra\Data\Responses\DashboardResponse;
use Spectra\Enums\DashboardWidget;
use Spectra\Enums\ModelType;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardScope;
use Spectra\Support\DateRange;
use Spectra\Support\ProviderRegistry;
use stdClass;

/**
 * Everything a shared dashboard shows, from its own queries. The scope is the trackable model the
 * caller resolved, never a type or id from the request, and only the dashboard's
 * own widgets are queried. Costs are left out here, on the server, so a dashboard
 * that hides them never sends them.
 */
class BuildDashboard
{
    /**
     * Create a new build shared dashboard action instance.
     */
    public function __construct(
        private readonly GetDashboardStats $getStats,
        private readonly GetDashboardRecentRequests $getRecentRequests,
        private readonly GetDashboardBudgetLimits $getBudgetLimits,
        private readonly GetDashboardPerformance $getPerformance,
        private readonly GetDashboardScores $getScores,
        private readonly ProviderRegistry $providers,
    ) {}

    /**
     * Get the dashboard's widgets with their data for the trackable and period.
     */
    public function __invoke(SpectraDashboard $dashboard, Model $trackable, string $period): DashboardResponse
    {
        $dateRange = DateRange::forPeriod($period);
        $widgets = collect();

        foreach ($dashboard->widgets as $entry) {
            $widget = DashboardWidget::tryFrom($entry['type']);

            if ($widget === null || ($widget->requiresCosts() && ! $dashboard->showsCosts())) {
                continue;
            }

            $widgets->push(new DashboardWidgetData(
                type: $widget->value,
                size: $entry['size'],
                data: $this->widgetData($widget, $trackable, $dateRange, $dashboard->showsCosts()),
            ));
        }

        return new DashboardResponse(
            name: $dashboard->name,
            trackable_name: isset($trackable->name) && is_string($trackable->name) ? $trackable->name : null,
            period: $period,
            show_costs: $dashboard->showsCosts(),
            widgets: $widgets,
        );
    }

    /**
     * Get one widget's data.
     *
     * @return DataTransferObject|Collection<int, mixed>
     */
    private function widgetData(DashboardWidget $widget, Model $trackable, DateRange $dateRange, bool $showCosts): DataTransferObject|Collection
    {
        $scope = new DashboardScope($trackable);

        return match ($widget) {
            DashboardWidget::Stats => ($this->getStats)($scope, $dateRange, $showCosts),
            DashboardWidget::CostTrend => $this->perDay($scope, $dateRange, DashboardScope::COST_SQL)
                ->map(fn (stdClass $row) => ['date' => Date::parse((string) $row->day)->toDateString(), 'cost' => (float) $row->value]),
            DashboardWidget::RequestsTrend => $this->perDay($scope, $dateRange, 'COUNT(*)')
                ->map(fn (stdClass $row) => ['date' => Date::parse((string) $row->day)->toDateString(), 'count' => (int) $row->value]),
            DashboardWidget::UsageByModel => $this->usage($scope, $dateRange, ['provider', 'model'], $showCosts)->take(5)->values(),
            DashboardWidget::UsageByProvider => new DashboardBreakdown(
                providers: $this->usage($scope, $dateRange, ['provider'], false),
                model_types: $this->modelTypes($scope, $dateRange),
            ),
            DashboardWidget::RecentRequests => ($this->getRecentRequests)($scope->type, $scope->id, $dateRange),
            DashboardWidget::Budget => ($this->getBudgetLimits)($trackable, $showCosts),
            DashboardWidget::Performance => ($this->getPerformance)($scope, $dateRange),
            DashboardWidget::Scores => ($this->getScores)($scope, $dateRange),
        };
    }

    /**
     * Get a value per day, oldest first.
     *
     * @param  literal-string  $valueSql
     * @return Collection<int, stdClass>
     */
    private function perDay(DashboardScope $scope, DateRange $dateRange, string $valueSql): Collection
    {
        return $scope->calls($dateRange)->toBase()
            ->selectRaw("DATE(created_at) as day, {$valueSql} as value")
            ->groupBy('day')
            ->orderBy('day')
            ->get();
    }

    /**
     * Get requests, tokens and spend per group of the given columns, most used first.
     *
     * @param  list<'provider'|'model'>  $columns
     * @return Collection<int, DashboardUsageRow>
     */
    private function usage(DashboardScope $scope, DateRange $dateRange, array $columns, bool $showCosts): Collection
    {
        return $scope->calls($dateRange)->toBase()
            ->select($columns)
            ->selectRaw('COUNT(*) as requests')
            ->selectRaw(DashboardScope::TOKENS_SQL.' as tokens')
            ->selectRaw(DashboardScope::COST_SQL.' as cost')
            ->groupBy($columns)
            ->orderByDesc('requests')
            ->get()
            ->map(fn (stdClass $row) => new DashboardUsageRow(
                provider: $row->provider,
                provider_name: $this->providerName($row->provider),
                model: $row->model ?? null,
                requests: (int) $row->requests,
                tokens: (int) $row->tokens,
                cost: $showCosts ? (float) $row->cost : null,
            ))
            ->values();
    }

    /**
     * Get the requests per model type, without their cost.
     *
     * @return Collection<int, DashboardModelTypeRow>
     */
    private function modelTypes(DashboardScope $scope, DateRange $dateRange): Collection
    {
        return $scope->calls($dateRange)->toBase()
            ->select('model_type')
            ->selectRaw('COUNT(*) as requests')
            ->groupBy('model_type')
            ->get()
            ->map(fn (stdClass $row) => new DashboardModelTypeRow(
                model_type: (string) ($row->model_type ?? 'unknown'),
                label: is_string($row->model_type) && filled($row->model_type) ? (ModelType::tryFrom($row->model_type)?->label() ?? $row->model_type) : 'Unknown',
                count: (int) $row->requests,
            ))
            ->values();
    }

    /**
     * Get a provider's display name, which a viewer can't look up themselves.
     */
    private function providerName(?string $provider): ?string
    {
        return $provider === null ? null : $this->providers->displayName($provider);
    }
}
