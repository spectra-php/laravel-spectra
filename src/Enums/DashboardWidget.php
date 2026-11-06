<?php

declare(strict_types=1);

namespace Spectra\Enums;

/**
 * The widgets an admin can put on a shared dashboard.
 */
enum DashboardWidget: string
{
    case Stats = 'stats';
    case CostTrend = 'cost_trend';
    case RequestsTrend = 'requests_trend';
    case UsageByModel = 'usage_by_model';
    case UsageByProvider = 'usage_by_provider';
    case RecentRequests = 'recent_requests';
    case Budget = 'budget';

    /**
     * Determine whether the widget shows nothing but cost, so it is left out
     * of a dashboard that hides costs.
     */
    public function requiresCosts(): bool
    {
        return $this === self::CostTrend;
    }
}
