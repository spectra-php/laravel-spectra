<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use InvalidArgumentException;
use Spectra\Data\AlertEntry;
use Spectra\Data\Responses\AlertsResponse;
use Spectra\Support\Alerts\AlertMonitor;
use Spectra\Support\Alerts\AlertStatus;

/**
 * GET /api/alerts: the configured alert rules with their values now and
 * whether each is firing. A misconfigured rule is reported, not a 500.
 */
class AlertsController extends BaseApiController
{
    /**
     * Get every alert rule's value and state.
     */
    public function __invoke(AlertMonitor $monitor): AlertsResponse
    {
        try {
            $statuses = $monitor->statuses(now());
        } catch (InvalidArgumentException $e) {
            return new AlertsResponse([], $e->getMessage());
        }

        return new AlertsResponse(collect($statuses)->map(fn (AlertStatus $status) => AlertEntry::fromStatus($status))->all());
    }
}
