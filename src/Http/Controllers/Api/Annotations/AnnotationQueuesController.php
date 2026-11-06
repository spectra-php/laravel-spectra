<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Annotations;

use Illuminate\Support\Facades\Gate;
use Spectra\Actions\Annotations\GetAnnotationQueues;
use Spectra\Data\Responses\AnnotationQueuesResponse;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * GET /api/annotations: every review queue with its counts.
 */
class AnnotationQueuesController extends BaseApiController
{
    /**
     * Get the queues, and whether the user may review.
     */
    public function __invoke(GetAnnotationQueues $getAnnotationQueues): AnnotationQueuesResponse
    {
        return new AnnotationQueuesResponse(
            queues: $getAnnotationQueues(),
            can_annotate: Gate::allows('annotateSpectra'),
            migrated: $getAnnotationQueues->migrated(),
        );
    }
}
