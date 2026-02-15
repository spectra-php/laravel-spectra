<?php

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;

readonly class TagsResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, \Spectra\Data\Tag>  $tags
     */
    public function __construct(
        public Collection $tags,
    ) {}
}
