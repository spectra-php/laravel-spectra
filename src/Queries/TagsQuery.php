<?php

declare(strict_types=1);

namespace Spectra\Queries;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraTag;

class TagsQuery
{
    /**
     * @return Collection<int, SpectraTag>
     */
    public function __invoke(): Collection
    {
        return SpectraTag::withCount('requests')
            ->orderByDesc('requests_count')
            ->get();
    }
}
