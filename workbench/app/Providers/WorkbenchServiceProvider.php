<?php

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Allow access to dashboard without authentication for testing
        Gate::define('viewSpectra', fn () => true);
    }
}
