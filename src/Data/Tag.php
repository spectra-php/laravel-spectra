<?php

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraTag;

readonly class Tag extends DataTransferObject
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public int $count,
    ) {}

    /**
     * @param  \Illuminate\Support\Collection<int, SpectraTag>  $tags
     * @return \Illuminate\Support\Collection<int, self>
     */
    public static function fromCollection(Collection $tags): Collection
    {
        return $tags->map(fn (SpectraTag $tag) => new self(
            id: (int) $tag->id,
            name: $tag->name,
            slug: $tag->slug,
            count: (int) ($tag->requests_count ?? 0),
        ));
    }
}
