<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * The pages a shared dashboard can link to in its header, besides its overview.
 */
enum DashboardSection: string
{
    case Requests = 'requests';
    case Traces = 'traces';
    case Conversations = 'conversations';
    case Errors = 'errors';
    case Costs = 'costs';

    /**
     * Determine whether the section shows nothing but cost, so it is left out
     * of a dashboard that hides costs.
     */
    public function requiresCosts(): bool
    {
        return $this === self::Costs;
    }
}
