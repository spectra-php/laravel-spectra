<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Annotations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Annotations\AnnotationQueue;
use Spectra\Support\Annotations\AnnotationQueues;

/**
 * POST /api/annotations/{queue}/items: put a trace (`trace_id`) in a review
 * queue from the dashboard. Needs the annotateSpectra gate.
 */
class QueueTraceForReviewController extends BaseApiController
{
    /**
     * Queue the trace, and answer whether it is in the queue.
     */
    public function __invoke(Request $request, string $queue, AnnotationQueues $queues): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);
        abort_if(rescue(fn (): AnnotationQueue => $queues->find($queue), null, report: false) === null, 404);

        $traceId = $request->validate(['trace_id' => ['required', 'string', 'max:64']])['trace_id'];

        return new JsonResponse(['queued' => $queues->add($queue, $traceId)]);
    }
}
