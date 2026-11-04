<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Illuminate\Http\Request;
use Spectra\Actions\Prompts\GetPromptVersions;
use Spectra\Data\Responses\PromptsResponse;
use Spectra\Support\DateRange;

/**
 * GET /api/prompts: every prompt template version used in the selected period.
 */
class PromptsController extends BaseApiController
{
    /**
     * Get one row per prompt template and version, with its numbers and scores.
     */
    public function __invoke(Request $request, GetPromptVersions $getPromptVersions): PromptsResponse
    {
        return new PromptsResponse($getPromptVersions(DateRange::fromRequest($request)));
    }
}
