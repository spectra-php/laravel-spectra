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
     * Get the dashboard's trackable when the viewer may see it, or null to refuse them.
     */
    public function resolve(Authenticatable $viewer, string $type, string $id): ?Model;
}
