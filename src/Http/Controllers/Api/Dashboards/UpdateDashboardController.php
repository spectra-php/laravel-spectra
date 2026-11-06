<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Dashboards;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spectra\Actions\Trackables\FindTrackable;
use Spectra\Actions\Trackables\ResolveTrackableClass;
use Spectra\Enums\DashboardWidget;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraDashboard;
use Spectra\Support\Dashboards\DashboardAccess;

/**
 * PUT /api/dashboards/{id}: change a saved shared dashboard. A null trackable
 * id means every trackable of the type. The type must be an Eloquent model, and
 * a given id must exist, so a typo can't save a page nobody can open.
 */
class UpdateDashboardController extends BaseApiController
{
    /**
     * Validate the dashboard and save the changes.
     */
    public function __invoke(string $id, Request $request, ResolveTrackableClass $resolveTrackableClass, FindTrackable $findTrackable): JsonResponse
    {
        abort_unless(DashboardAccess::allowed(), 403);

        $dashboard = SpectraDashboard::query()->findOrFail($id);

        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'trackable_type' => ['required', 'string', 'max:255'],
            'trackable_id' => ['nullable', 'string', 'max:255'],
            'widgets' => ['required', 'array', 'min:1'],
            'widgets.*' => ['required', 'array:type,size'],
            'widgets.*.type' => ['required', 'distinct', Rule::enum(DashboardWidget::class)],
            'widgets.*.size' => ['required', 'in:full,half'],
            'default_period' => ['required', 'string', 'in:today,week,month,year'],
            'show_costs' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);

        if ($resolveTrackableClass($input['trackable_type']) === null) {
            throw ValidationException::withMessages(['trackable_type' => 'This trackable type is not an Eloquent model.']);
        }

        $input['trackable_id'] ??= null;

        if ($input['trackable_id'] !== null && $findTrackable($input['trackable_type'], $input['trackable_id']) === null) {
            throw ValidationException::withMessages(['trackable_id' => 'No trackable of this type has this id.']);
        }

        $dashboard->update($input);

        return response()->json(['id' => $dashboard->id]);
    }
}
