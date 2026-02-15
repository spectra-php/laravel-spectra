<?php

namespace Spectra\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

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
