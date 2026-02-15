<?php

namespace Spectra\Actions\Requests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FormatRequestsList
{
    /**
     * @param  \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, \Spectra\Models\SpectraRequest>  $paginated
     * @return array<int, array<string, mixed>>
     */
    public function __invoke(LengthAwarePaginator $paginated): array
    {
        /** @var array<int, \Spectra\Models\SpectraRequest> $items */
        $items = $paginated->items();

        return collect($items)->map(function ($item) {
            $arr = $item->toArray();
            $arr['tags'] = $item->tags->pluck('name')->toArray();

            return $arr;
        })->toArray();
    }
}
