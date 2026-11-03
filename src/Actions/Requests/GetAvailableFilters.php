<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Spectra\Data\Responses\AvailableFiltersResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraTag;

/**
 * The filter options the request and trackable screens offer: every tag, and
 * the finish reasons and reasoning efforts actually stored.
 */
class GetAvailableFilters
{
    public function __invoke(): AvailableFiltersResponse
    {
        $filters = SpectraRequest::query()
            ->selectRaw('DISTINCT finish_reason, reasoning_effort')
            ->where(function ($query) {
                $query->whereNotNull('finish_reason')
                    ->orWhereNotNull('reasoning_effort');
            })
            ->get();

        return new AvailableFiltersResponse(
            tags: SpectraTag::query()->orderBy('name')->pluck('name')->toArray(),
            finish_reasons: $filters->pluck('finish_reason')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->toArray(),
            reasoning_efforts: $filters->pluck('reasoning_effort')
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->toArray(),
        );
    }
}
