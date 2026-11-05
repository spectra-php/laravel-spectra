<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Annotations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spectra\Enums\AnnotationStatus;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraAnnotationItem;
use Spectra\Support\Annotations\AnnotationQueues;

/**
 * POST /api/annotations/items/{id}: save a review of a trace (`action:
 * review` with `scores` by name and an optional `comment`), or skip it
 * (`action: skip`). Needs the annotateSpectra gate.
 */
class ReviewAnnotationItemController extends BaseApiController
{
    /**
     * Review or skip the item, as the signed-in user.
     */
    public function __invoke(Request $request, string $id, AnnotationQueues $queues): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        $input = $request->validate([
            'action' => ['required', 'in:review,skip'],
            'scores' => ['array'],
            'comment' => ['nullable', 'string', 'max:10000'],
        ]);

        $item = SpectraAnnotationItem::query()->findOrFail($id);

        throw_unless($item->status === AnnotationStatus::Pending, ValidationException::withMessages(['action' => 'This trace was already reviewed.']));

        $reviewer = $request->user();

        try {
            $finished = $input['action'] === 'skip'
                ? $queues->skip($item, $reviewer)
                : $queues->review($item, (array) ($input['scores'] ?? []), $input['comment'] ?? null, $reviewer);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['scores' => $e->getMessage()]);
        }

        // Another reviewer finished it between the check above and now.
        throw_unless($finished, ValidationException::withMessages(['action' => 'This trace was already reviewed.']));

        return new JsonResponse(['status' => $item->status->value]);
    }
}
