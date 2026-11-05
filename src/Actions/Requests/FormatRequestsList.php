<?php

declare(strict_types=1);

namespace Spectra\Actions\Requests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spectra\Models\SpectraRequest;

/**
 * Shapes a page of requests into the rows the requests screen shows, with tag
 * names instead of tag models.
 */
class FormatRequestsList
{
    /**
     * Get the page of requests as rows, with tag names in place of tag models.
     *
     * @param  LengthAwarePaginator<int, SpectraRequest>  $paginated
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(LengthAwarePaginator $paginated): array
    {
        /** @var array<int, SpectraRequest> $items */
        $items = $paginated->items();

        return collect($items)->map(function ($item) {
            $row = $item->toArray();
            $row['tags'] = $item->tags->pluck('name')->toArray();

            return $row;
        })->toArray();
    }
}
