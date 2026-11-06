<?php

declare(strict_types=1);

namespace Spectra\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Decides whose data a logged-in viewer sees on a shared dashboard. Apps whose
 * trackables aren't the logged-in users themselves (a team, a workspace) bind
 * their own implementation.
 */
interface DashboardTrackableResolver
{
    /**
     * Get the trackable the viewer may see, or null to refuse them.
     *
     * With an id, the dashboard is for that one trackable: return it when the
     * viewer may see it. With a null id, the dashboard is for every trackable
     * of the type: return the viewer's own.
     */
    public function resolve(Authenticatable $viewer, string $type, ?string $id): ?Model;
}
