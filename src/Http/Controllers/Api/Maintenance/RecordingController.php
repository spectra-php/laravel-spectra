<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Maintenance;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Maintenance\MaintenanceAccess;
use Spectra\Support\Tracking\RecordingSwitch;

/**
 * POST /api/maintenance/recording: pause (`paused: true`) or resume
 * (`paused: false`) recording.
 */
class RecordingController extends BaseApiController
{
    /**
     * Pause or resume recording and answer with the new state.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(MaintenanceAccess::allowed(), 403);

        $paused = $request->validate(['paused' => ['required', 'boolean']])['paused'];

        filter_var($paused, FILTER_VALIDATE_BOOLEAN) ? RecordingSwitch::pause() : RecordingSwitch::resume();

        return new JsonResponse(['recording_paused' => RecordingSwitch::isPaused()]);
    }
}
