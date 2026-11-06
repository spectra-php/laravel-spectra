<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spectra\Actions\Trackables\GetTrackableCostsByDate;
use Spectra\Actions\Trackables\GetTrackableRequestsByDate;
use Spectra\Actions\Trackables\GetTrackableStats;
use Spectra\Actions\Trackables\GetTrackableUsageByModel;
use Spectra\Actions\Trackables\GetTrackableUsageByProvider;
use Spectra\Data\DashboardStats;
use Spectra\Data\DashboardUsageRow;
use Spectra\Data\DashboardWidgetData;
use Spectra\Data\DataTransferObject;
use Spectra\Data\Responses\DashboardResponse;
use Spectra\Data\UsageByModel;
use Spectra\Data\UsageByProvider;
use Spectra\Enums\DashboardWidget;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\DateRange;
use Spectra\Support\ProviderRegistry;

/**
 * Everything a shared dashboard shows. The scope is the trackable model the
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
        private readonly GetTrackableStats $getStats,
        private readonly GetTrackableCostsByDate $getCostsByDate,
        private readonly GetTrackableRequestsByDate $getRequestsByDate,
        private readonly GetTrackableUsageByModel $getUsageByModel,
        private readonly GetTrackableUsageByProvider $getUsageByProvider,
        private readonly GetDashboardRecentRequests $getRecentRequests,
        private readonly GetDashboardBudgetLimits $getBudgetLimits,
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

            if ($widget === null || ($widget->requiresCosts() && ! $dashboard->show_costs)) {
                continue;
            }

            $widgets->push(new DashboardWidgetData(
                type: $widget->value,
                size: $entry['size'],
                data: $this->widgetData($widget, $trackable, $dateRange, $dashboard->show_costs),
            ));
        }

        return new DashboardResponse(
            name: $dashboard->name,
            trackable_name: isset($trackable->name) && is_string($trackable->name) ? $trackable->name : null,
            period: $period,
            show_costs: $dashboard->show_costs,
            widgets: $widgets,
        );
    }

    /**
     * Get one widget's data.
     *
     * @return DataTransferObject|Collection<int, covariant DataTransferObject>
     */
    private function widgetData(DashboardWidget $widget, Model $trackable, DateRange $dateRange, bool $showCosts): DataTransferObject|Collection
    {
        $type = $trackable->getMorphClass();
        $id = (string) $trackable->getKey();

        return match ($widget) {
            DashboardWidget::Stats => $this->stats($type, $id, $dateRange, $showCosts),
            DashboardWidget::CostTrend => ($this->getCostsByDate)($type, $id, $dateRange),
            DashboardWidget::RequestsTrend => ($this->getRequestsByDate)($type, $id, $dateRange),
            DashboardWidget::UsageByModel => ($this->getUsageByModel)($type, $id, $dateRange)
                ->map(fn (UsageByModel $row) => new DashboardUsageRow(
                    provider: $row->provider,
                    provider_name: $this->providerName($row->provider),
                    model: $row->model,
                    requests: $row->requests,
                    tokens: $row->tokens,
                    cost: $showCosts ? $row->cost : null,
                )),
            DashboardWidget::UsageByProvider => ($this->getUsageByProvider)($type, $id, $dateRange)
                ->map(fn (UsageByProvider $row) => new DashboardUsageRow(
                    provider: $row->provider,
                    provider_name: $this->providerName($row->provider),
                    model: null,
                    requests: $row->requests,
                    tokens: $row->tokens,
                    cost: $showCosts ? $row->cost : null,
                )),
            DashboardWidget::RecentRequests => ($this->getRecentRequests)($type, $id, $dateRange, $showCosts),
            DashboardWidget::Budget => ($this->getBudgetLimits)($trackable, $showCosts),
        };
    }

    /**
     * Get the headline numbers, without the cost on a dashboard that hides it.
     */
    private function stats(string $type, string $id, DateRange $dateRange, bool $showCosts): DashboardStats
    {
        $stats = ($this->getStats)($type, $id, $dateRange);

        return new DashboardStats(
            total_requests: $stats->total_requests,
            total_tokens: $stats->total_tokens,
            total_images: $stats->total_images,
            total_videos: $stats->total_videos,
            total_tts_characters: $stats->total_tts_characters,
            stt_duration_seconds: $stats->stt_duration_seconds,
            avg_latency: $stats->avg_latency,
            total_cost: $showCosts ? $stats->total_cost : null,
        );
    }

    /**
     * Get a provider's display name, which a viewer can't look up themselves.
     */
    private function providerName(?string $provider): ?string
    {
        return $provider === null ? null : $this->providers->displayName($provider);
    }
}
