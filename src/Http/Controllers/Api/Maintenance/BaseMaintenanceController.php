<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Maintenance\MaintenanceAccess;

/**
 * Parent of the maintenance actions (prune, rebuild stats, pause recording).
 * On top of `viewSpectra`, they need the `manageSpectra` gate and the
 * `dashboard.maintenance` option, because they delete or skip data.
 */
abstract class BaseMaintenanceController extends BaseApiController
{
    /**
     * Create a new controller instance that also denies anyone who may not run maintenance.
     */
    public function __construct()
    {
        parent::__construct();

        $this->middleware(function ($request, $next) {
            abort_unless(MaintenanceAccess::allowed(), 403);

            return $next($request);
        });
    }

    /**
     * Get the retention rules shared by the prune and its preview.
     *
     * @return array<string, array<int, string>>
     */
    public static function retentionRules(): array
    {
        return [
            'hours' => ['required', 'integer', 'min:1', 'max:876000'],
            'stats_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
        ];
    }
}
