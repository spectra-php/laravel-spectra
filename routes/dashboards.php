<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spectra\Http\Controllers\Dashboards\DashboardCallMessagesController;
use Spectra\Http\Controllers\Dashboards\DashboardCallsController;
use Spectra\Http\Controllers\Dashboards\DashboardConversationController;
use Spectra\Http\Controllers\Dashboards\DashboardConversationsController;
use Spectra\Http\Controllers\Dashboards\DashboardCostsController;
use Spectra\Http\Controllers\Dashboards\DashboardDataController;
use Spectra\Http\Controllers\Dashboards\DashboardErrorsController;
use Spectra\Http\Controllers\Dashboards\DashboardTraceController;
use Spectra\Http\Controllers\Dashboards\DashboardTracesController;
use Spectra\Http\Controllers\Dashboards\ShowDashboardController;

// Shared dashboards: read-only pages for people outside the dashboard, under
// their own middleware. Dashboard ids are UUIDs; anything else never reaches a
// query PostgreSQL's uuid column would reject, and falls to the dashboard's routes.
Route::get('/{id}', ShowDashboardController::class)->whereUuid('id')->name('spectra.dashboards.show');
Route::get('/{id}/data', DashboardDataController::class)->whereUuid('id')->name('spectra.dashboards.data');

Route::get('/{id}/data/requests', DashboardCallsController::class)->whereUuid('id');
Route::get('/{id}/data/requests/{requestId}', DashboardCallMessagesController::class)->whereUuid(['id', 'requestId']);
Route::get('/{id}/data/traces', DashboardTracesController::class)->whereUuid('id');
Route::get('/{id}/data/traces/{traceId}', DashboardTraceController::class)->whereUuid('id');
Route::get('/{id}/data/conversations', DashboardConversationsController::class)->whereUuid('id');
Route::get('/{id}/data/conversations/{conversationId}', DashboardConversationController::class)->whereUuid('id')->where('conversationId', '.*');
Route::get('/{id}/data/errors', DashboardErrorsController::class)->whereUuid('id');
Route::get('/{id}/data/costs', DashboardCostsController::class)->whereUuid('id');

// The page again, for a section's links and a reload on one. Only the sections
// match, so the admin's /{id}/edit and /{id}/preview stay with the dashboard's routes.
Route::get('/{id}/{section}/{rest?}', ShowDashboardController::class)
    ->whereUuid('id')
    ->whereIn('section', ['requests', 'traces', 'conversations', 'errors', 'costs'])
    ->where('rest', '.*');
