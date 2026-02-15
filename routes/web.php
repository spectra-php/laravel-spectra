<?php

use Illuminate\Support\Facades\Route;
use Spectra\Http\Controllers\Api\ConfigController;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Http\Controllers\Api\DownloadVideoController;
use Spectra\Http\Controllers\Api\ProvidersController;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Http\Controllers\Api\ServeAudioController;
use Spectra\Http\Controllers\Api\ServeImageController;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Http\Controllers\Api\ShowTrackableController;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Http\Controllers\Api\TagsController;
use Spectra\Http\Controllers\Api\TrackableRequestsByDateController;
use Spectra\Http\Controllers\Api\TrackablesController;
use Spectra\Http\Controllers\DashboardController;

// API Routes
Route::prefix('api')->group(function () {
    Route::get('/config', ConfigController::class);
    Route::get('/stats', StatsController::class);
    Route::get('/requests', RequestsController::class);
    Route::get('/requests/{id}', ShowRequestController::class);
    Route::get('/requests/{id}/video', DownloadVideoController::class);
    Route::get('/requests/{id}/audio/{action?}', ServeAudioController::class)->whereIn('action', ['download']);
    Route::get('/requests/{id}/images/{index}', ServeImageController::class);
    Route::get('/costs', CostsController::class);
    Route::get('/tags', TagsController::class);
    Route::get('/trackables', TrackablesController::class);
    Route::get('/trackables/view/{id}', ShowTrackableController::class);
    Route::get('/trackables/view/{id}/requests-by-date', TrackableRequestsByDateController::class);
    Route::get('/providers', ProvidersController::class);
});

// SPA catch-all route
Route::get('/{view?}', DashboardController::class)
    ->where('view', '(.*)')
    ->name('spectra.dashboard');
