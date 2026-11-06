<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Annotations;

use Illuminate\Support\Facades\Gate;
use Spectra\Actions\Annotations\GetAnnotationQueues;
use Spectra\Actions\Annotations\GetNextAnnotationItem;
use Spectra\Data\Responses\AnnotationItemResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Annotations\AnnotationQueue;
use Spectra\Support\Annotations\AnnotationQueues;

/**
 * GET /api/annotations/{queue}/next: the trace waiting longest in the queue.
 */
class NextAnnotationItemController extends BaseApiController
{
    /**
     * Get the queue and its next trace to review, or none when it is done.
     */
    public function __invoke(string $queue, AnnotationQueues $queues, GetAnnotationQueues $getAnnotationQueues, GetNextAnnotationItem $getNextAnnotationItem): AnnotationItemResponse
    {
        $definition = rescue(fn (): AnnotationQueue => $queues->find($queue), null, report: false);
        abort_if($definition === null, 404);

        return new AnnotationItemResponse(
            queue: $getAnnotationQueues->summary($definition, $getAnnotationQueues->counts()),
            item: $getAnnotationQueues->migrated() ? $getNextAnnotationItem($queue) : null,
            can_annotate: Gate::allows('annotateSpectra'),
        );
    }
}
