<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\Tag;

/**
 * Every tag with the number of requests that carry it, most used first.
 */
readonly class TagsResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, Tag>  $tags
     */
    public function __construct(
        public Collection $tags,
    ) {}
}
