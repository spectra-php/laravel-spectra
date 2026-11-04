<?php

declare(strict_types=1);

namespace Spectra\Data;

use Illuminate\Support\Collection;
use Spectra\Models\SpectraTag;

/**
 * One tag on the tags endpoint, with how many requests carry it. The id is
 * the tag's UUID.
 */
readonly class Tag extends DataTransferObject
{
    /**
     * Create a new tag instance.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $slug,
        public int $count,
    ) {}

    /**
     * Build one row per tag, with its request count, from the tag models.
     *
     * @param  Collection<int, SpectraTag>  $tags
     * @return Collection<int, self>
     */
    public static function fromCollection(Collection $tags): Collection
    {
        return $tags->map(fn (SpectraTag $tag) => new self(
            id: $tag->id,
            name: $tag->name,
            slug: $tag->slug,
            count: $tag->requests_count ?? 0,
        ));
    }
}
