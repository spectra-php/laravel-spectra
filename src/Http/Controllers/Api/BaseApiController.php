<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

/**
 * Parent of every dashboard API controller. Its constructor middleware denies
 * anyone the `viewSpectra` gate refuses, so no endpoint can forget the check.
 */
abstract class BaseApiController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            if (Gate::denies('viewSpectra')) {
                abort(403);
            }

            return $next($request);
        });
    }
}
