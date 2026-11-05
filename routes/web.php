<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Spectra\Http\Controllers\Api\AlertsController;
use Spectra\Http\Controllers\Api\Annotations\AnnotationQueuesController;
use Spectra\Http\Controllers\Api\Annotations\NextAnnotationItemController;
use Spectra\Http\Controllers\Api\Annotations\QueueTraceForReviewController;
use Spectra\Http\Controllers\Api\Annotations\ReviewAnnotationItemController;
use Spectra\Http\Controllers\Api\ConfigController;
use Spectra\Http\Controllers\Api\ConversationsController;
use Spectra\Http\Controllers\Api\CostsController;
use Spectra\Http\Controllers\Api\DownloadVideoController;
use Spectra\Http\Controllers\Api\ErrorsController;
use Spectra\Http\Controllers\Api\Evaluations\AddDatasetExampleController;
use Spectra\Http\Controllers\Api\Evaluations\EvalsController;
use Spectra\Http\Controllers\Api\Evaluations\ShowDatasetController;
use Spectra\Http\Controllers\Api\Evaluations\ShowEvalRunController;
use Spectra\Http\Controllers\Api\Maintenance\ApplyPricingController;
use Spectra\Http\Controllers\Api\Maintenance\CheckPricingController;
use Spectra\Http\Controllers\Api\Maintenance\DismissPricingReviewController;
use Spectra\Http\Controllers\Api\Maintenance\MaintenanceStatusController;
use Spectra\Http\Controllers\Api\Maintenance\PricingStatusController;
use Spectra\Http\Controllers\Api\Maintenance\PruneController;
use Spectra\Http\Controllers\Api\Maintenance\RebuildStatsController;
use Spectra\Http\Controllers\Api\Maintenance\RecordingController;
use Spectra\Http\Controllers\Api\PerformanceController;
use Spectra\Http\Controllers\Api\PromptsController;
use Spectra\Http\Controllers\Api\ProvidersController;
use Spectra\Http\Controllers\Api\RequestsController;
use Spectra\Http\Controllers\Api\ScoresController;
use Spectra\Http\Controllers\Api\ServeAudioController;
use Spectra\Http\Controllers\Api\ServeImageController;
use Spectra\Http\Controllers\Api\ShowConversationController;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Http\Controllers\Api\ShowTraceController;
use Spectra\Http\Controllers\Api\ShowTrackableController;
use Spectra\Http\Controllers\Api\StatsController;
use Spectra\Http\Controllers\Api\TagsController;
use Spectra\Http\Controllers\Api\TracesController;
use Spectra\Http\Controllers\Api\TrackableRequestsByDateController;
use Spectra\Http\Controllers\Api\TrackablesController;
use Spectra\Http\Controllers\DashboardController;

// API Routes
Route::prefix('api')->group(function () {
    Route::get('/config', ConfigController::class);
    Route::get('/stats', StatsController::class);
    Route::get('/performance', PerformanceController::class);
    Route::get('/alerts', AlertsController::class);
    Route::get('/requests', RequestsController::class);
    // Request ids are UUIDs; anything else is a 404 rather than a query
    // PostgreSQL's uuid column rejects with an error.
    Route::get('/requests/{id}', ShowRequestController::class)->whereUuid('id');
    Route::get('/requests/{id}/video', DownloadVideoController::class)->whereUuid('id');
    Route::get('/requests/{id}/audio/{action?}', ServeAudioController::class)->whereUuid('id')->whereIn('action', ['download']);
    Route::get('/requests/{id}/images/{index}', ServeImageController::class)->whereUuid('id')->whereNumber('index');
    Route::get('/traces', TracesController::class);
    Route::get('/traces/{traceId}', ShowTraceController::class);
    Route::get('/conversations', ConversationsController::class);
    Route::get('/conversations/{conversationId}', ShowConversationController::class)->where('conversationId', '.*');
    Route::get('/costs', CostsController::class);
    Route::get('/scores', ScoresController::class);
    Route::get('/prompts', PromptsController::class);
    Route::get('/errors', ErrorsController::class);
    Route::get('/annotations', AnnotationQueuesController::class);
    Route::get('/annotations/{queue}/next', NextAnnotationItemController::class)->where('queue', '[A-Za-z0-9._\-]+');
    Route::post('/annotations/{queue}/items', QueueTraceForReviewController::class)->where('queue', '[A-Za-z0-9._\-]+');
    Route::post('/annotations/items/{id}', ReviewAnnotationItemController::class)->whereUuid('id');
    Route::get('/evals', EvalsController::class);
    Route::get('/evals/runs/{id}', ShowEvalRunController::class)->whereUuid('id');
    Route::get('/datasets/{id}', ShowDatasetController::class)->whereUuid('id');
    Route::post('/datasets/examples', AddDatasetExampleController::class);
    Route::get('/tags', TagsController::class);
    Route::get('/trackables', TrackablesController::class);
    Route::get('/trackables/view/{id}', ShowTrackableController::class);
    Route::get('/trackables/view/{id}/requests-by-date', TrackableRequestsByDateController::class);
    Route::get('/providers', ProvidersController::class);

    // Maintenance: anyone who may view the dashboard sees the status; the
    // actions also need the manageSpectra gate.
    Route::get('/maintenance', MaintenanceStatusController::class);
    Route::post('/maintenance/prune', PruneController::class);
    Route::post('/maintenance/rebuild-stats', RebuildStatsController::class);
    Route::post('/maintenance/recording', RecordingController::class);
    Route::get('/maintenance/pricing', PricingStatusController::class);
    Route::post('/maintenance/pricing/check', CheckPricingController::class);
    Route::post('/maintenance/pricing/apply', ApplyPricingController::class);
    Route::delete('/maintenance/pricing/review', DismissPricingReviewController::class);
});

// SPA catch-all route. It skips api/ so an unknown or malformed API URL is a
// 404 instead of the dashboard's HTML.
Route::get('/{view?}', DashboardController::class)
    ->where('view', '(?!api(?:/|$)).*')
    ->name('spectra.dashboard');
