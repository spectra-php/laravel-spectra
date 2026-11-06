<?php

declare(strict_types=1);

namespace Spectra\Support\Dashboards;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Spectra\Contracts\DashboardTrackableResolver;

/**
 * The default rule for shared dashboards: a viewer sees only their own data,
 * so they must be the trackable themselves.
 */
class ViewerAsTrackable implements DashboardTrackableResolver
{
    /**
     * Get the viewer when they are the trackable the dashboard is for.
     */
    public function resolve(Authenticatable $viewer, string $type, ?string $id): ?Model
    {
        if (! $viewer instanceof Model || $viewer->getMorphClass() !== $type) {
            return null;
        }

        if ($id !== null && $id !== (string) $viewer->getKey()) {
            return null;
        }

        return $viewer;
    }
}
