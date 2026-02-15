<?php

namespace Spectra\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;

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
