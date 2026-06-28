<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\Tag;

readonly class TagsResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, Tag>  $tags
     */
    public function __construct(
        public Collection $tags,
    ) {}
}
