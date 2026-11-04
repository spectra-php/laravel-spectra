<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Config\GetDashboardConfig;
use Spectra\Data\Responses\DashboardConfigResponse;

/**
 * GET /api/config: the settings the SPA loads first.
 */
class ConfigController extends BaseApiController
{
    /**
     * Get the dashboard layout and model types the SPA loads first.
     */
    public function __invoke(GetDashboardConfig $action): DashboardConfigResponse
    {
        return $action();
    }
}
