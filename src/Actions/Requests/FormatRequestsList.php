<?php

namespace Spectra\Actions\Requests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Spectra\Models\SpectraRequest;

class FormatRequestsList
{
    /**
     * @param  LengthAwarePaginator<int, SpectraRequest>  $paginated
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(LengthAwarePaginator $paginated): array
    {
        /** @var array<int, SpectraRequest> $items */
        $items = $paginated->items();

        return collect($items)->map(function ($item) {
            $arr = $item->toArray();
            $arr['tags'] = $item->tags->pluck('name')->toArray();

            return $arr;
        })->toArray();
    }
}
