<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Playground;

use Spectra\Actions\Playground\GetPlaygroundCall;
use Spectra\Data\Responses\PlaygroundCallResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Playground\PlaygroundAccess;

/**
 * GET /api/playground/{id}: one stored call, opened in the Playground.
 */
class ShowPlaygroundCallController extends BaseApiController
{
    /**
     * Get the call with its editable body, models and notices.
     */
    public function __invoke(string $id, GetPlaygroundCall $getPlaygroundCall): PlaygroundCallResponse
    {
        abort_unless(PlaygroundAccess::allowed(), 403);

        return $getPlaygroundCall(SpectraRequest::query()->findOrFail($id));
    }
}
