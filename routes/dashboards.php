<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spectra\Http\Controllers\Dashboards\DashboardDataController;
use Spectra\Http\Controllers\Dashboards\ShowDashboardController;

// Shared dashboards: read-only pages for people outside the dashboard, under
// their own middleware. Dashboard ids are UUIDs; anything else never reaches a
// query PostgreSQL's uuid column would reject, and falls to the dashboard's routes.
Route::get('/{id}', ShowDashboardController::class)->whereUuid('id')->name('spectra.dashboards.show');
Route::get('/{id}/data', DashboardDataController::class)->whereUuid('id')->name('spectra.dashboards.data');
