<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\PlaygroundGroup;

/**
 * The Playground's start screen: whether it is on and open to this user, and
 * the latest requests sent from it, one row per stored call or new request.
 */
readonly class PlaygroundResponse extends DataTransferObject
{
    /**
     * Create a new playground response instance.
     *
     * @param  Collection<int, PlaygroundGroup>  $groups
     */
    public function __construct(
        public bool $enabled,
        public bool $allowed,
        public Collection $groups,
    ) {}
}
