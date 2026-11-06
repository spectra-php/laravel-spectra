<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Spectra\Actions\Trackables\GetAvailableTrackableTypes;
use Spectra\Data\Responses\AvailableFiltersResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraTag;

/**
 * The filter options the request and trackable screens offer: every tag, and
 * the finish reasons and reasoning efforts of recent calls and the trackable
 * types actually stored.
 */
class GetAvailableFilters
{
    /** How many of the latest calls the finish reasons and efforts are read from. */
    private const RECENT_CALLS = 20000;

    /**
     * Create a new get available filters action instance.
     */
    public function __construct(
        private readonly GetAvailableTrackableTypes $getAvailableTrackableTypes,
    ) {}

    /**
     * Get every tag and the stored finish reasons, reasoning efforts and trackable types.
     */
    public function __invoke(): AvailableFiltersResponse
    {
        // The latest calls only: DISTINCT over a column without an index reads
        // the whole table, and every list screen asks for these.
        $filters = (new SpectraRequest)->getConnection()->query()
            ->fromSub(
                SpectraRequest::query()->toBase()->select(['finish_reason', 'reasoning_effort'])->latest('created_at')->limit(self::RECENT_CALLS),
                'recent_calls',
            )
            ->select(['finish_reason', 'reasoning_effort'])
            ->distinct()
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
            trackable_types: ($this->getAvailableTrackableTypes)(),
        );
    }
}
