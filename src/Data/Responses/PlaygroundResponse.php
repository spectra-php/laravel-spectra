<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\PlaygroundCall;

/**
 * The Playground's start screen: whether it is on and open to this user, and
 * the recent calls to start from.
 */
readonly class PlaygroundResponse extends DataTransferObject
{
    /**
     * Create a new playground response instance.
     *
     * @param  Collection<int, PlaygroundCall>  $calls
     */
    public function __construct(
        public bool $enabled,
        public bool $allowed,
        public Collection $calls,
    ) {}
}
