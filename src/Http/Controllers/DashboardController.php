<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Serves the dashboard SPA shell for every non-API path. It is not an API
 * controller, so it checks the `viewSpectra` gate itself, the same way
 * BaseApiController does.
 */
class DashboardController extends Controller
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

    public function __invoke(): View
    {
        return view('spectra::layout');
    }
}
