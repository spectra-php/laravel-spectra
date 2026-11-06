<?php

declare(strict_types=1);

namespace Spectra\Actions\Dashboards;

use Illuminate\Support\Collection;
use Spectra\Data\DashboardSummary;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardTrackables;

/**
 * The saved shared dashboards for the admin screen, newest first, each with
 * the name of the trackable it is for and the URL to give them.
 */
class GetDashboards
{
    /**
     * Create a new get shared dashboards action instance.
     */
    public function __construct(
        private readonly DashboardTrackables $trackables,
    ) {}

    /**
     * Get every saved shared dashboard.
     *
     * @return Collection<int, DashboardSummary>
     */
    public function __invoke(): Collection
    {
        return SpectraDashboard::query()
            ->latest()
            ->get()
            ->map(fn (SpectraDashboard $dashboard) => new DashboardSummary(
                id: $dashboard->id,
                name: $dashboard->name,
                trackable_type: $dashboard->trackable_type,
                trackable_id: $dashboard->trackable_id,
                trackable_name: $this->trackableName($dashboard),
                widgets: $dashboard->widgets,
                default_period: $dashboard->default_period,
                show_costs: $dashboard->show_costs,
                sections: $dashboard->sections ?? [],
                show_messages: $dashboard->show_messages,
                is_active: $dashboard->is_active,
                url: $dashboard->url(),
            ))
            ->values();
    }

    /**
     * Get the name of the one trackable the dashboard is for, when it has one.
     */
    private function trackableName(SpectraDashboard $dashboard): ?string
    {
        if ($dashboard->trackable_id === null) {
            return null;
        }

        $trackable = $this->trackables->find($dashboard->trackable_type, $dashboard->trackable_id);

        return isset($trackable->name) && is_string($trackable->name) ? $trackable->name : null;
    }
}
