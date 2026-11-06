<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Evaluations;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spectra\Facades\Spectra;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraRequest;

/**
 * POST /api/datasets/examples: add a tracked call (`request_id`) to a
 * dataset by name (`dataset`, made when missing), with its reply as the
 * expected output when `reply_is_expected`. Needs the annotateSpectra gate.
 */
class AddDatasetExampleController extends BaseApiController
{
    /**
     * Add the call as an example and answer with the dataset and example ids.
     */
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('annotateSpectra'), 403);

        $input = $request->validate([
            'dataset' => ['required', 'string', 'max:100'],
            'request_id' => ['required', 'uuid'],
            'reply_is_expected' => ['boolean'],
        ]);

        $call = SpectraRequest::query()->whereKey($input['request_id'])->firstOrFail();
        $dataset = Spectra::dataset($input['dataset']);
        $example = $dataset->addRequest($call, replyIsExpected: (bool) ($input['reply_is_expected'] ?? false));

        return new JsonResponse(['dataset_id' => $example->dataset_id, 'example_id' => $example->id], 201);
    }
}
