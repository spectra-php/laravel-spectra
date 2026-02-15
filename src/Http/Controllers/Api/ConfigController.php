<?php

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Config\GetDashboardConfig;
use Spectra\Data\Responses\DashboardConfigResponse;

class ConfigController extends BaseApiController
{
    public function __invoke(GetDashboardConfig $action): DashboardConfigResponse
    {
        return $action();
    }
}
