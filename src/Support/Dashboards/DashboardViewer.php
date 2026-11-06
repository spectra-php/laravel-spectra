<?php

declare(strict_types=1);

namespace Spectra\Support\Dashboards;

use Illuminate\Contracts\Auth\Authenticatable;
use Spectra\Contracts\DashboardTrackableResolver;
use Spectra\Models\SpectraDashboard;

/**
 * Decides whose data a logged-in viewer sees on a shared dashboard, failing
 * closed: no viewer, no trackable, or a trackable the dashboard isn't for gives
 * no scope.
 */
class DashboardViewer
{
    /**
     * Create a new dashboard viewer instance.
     */
    public function __construct(
        private readonly DashboardTrackableResolver $resolver,
    ) {}

    /**
     * Get the scope the viewer may see on the dashboard, or null to refuse them.
     */
    public function scope(SpectraDashboard $dashboard, ?Authenticatable $viewer): ?DashboardScope
    {
        if ($viewer === null) {
            return null;
        }

        $trackable = $this->resolver->resolve($viewer, $dashboard->trackable_type, $dashboard->trackable_id);

        return $trackable !== null && $dashboard->isFor($trackable) ? new DashboardScope($trackable) : null;
    }
}
