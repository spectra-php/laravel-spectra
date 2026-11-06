<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Playground;

use Spectra\Actions\Playground\GetPlaygroundCalls;
use Spectra\Data\Responses\PlaygroundResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Playground\PlaygroundAccess;

/**
 * GET /api/playground: whether the Playground is on and open to this user, and
 * the recent calls to start from when it is. Anyone who may view the dashboard
 * sees the first two, so the screen can say how to turn it on.
 */
class PlaygroundController extends BaseApiController
{
    /**
     * Get the Playground's start screen.
     */
    public function __invoke(GetPlaygroundCalls $getPlaygroundCalls): PlaygroundResponse
    {
        $isAllowed = PlaygroundAccess::allowed();

        return new PlaygroundResponse(
            enabled: PlaygroundAccess::enabled(),
            allowed: $isAllowed,
            calls: $isAllowed ? $getPlaygroundCalls() : collect(),
        );
    }
}
