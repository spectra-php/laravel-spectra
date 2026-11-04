<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\ErrorGroup;

/**
 * The Errors screen: the period's failed calls, grouped by fingerprint.
 */
readonly class ErrorsResponse extends DataTransferObject
{
    /**
     * Create a new errors response instance.
     *
     * @param  Collection<int, ErrorGroup>  $groups  Most failures first
     * @param  int  $ungrouped_count  Failures saved before the fingerprint migration ran, until spectra:group-errors groups them
     * @param  bool  $migrated  Whether the error fingerprint migration has run
     */
    public function __construct(
        public int $request_count,
        public int $failed_count,
        public float $error_rate,
        public int $ungrouped_count,
        public bool $migrated,
        public Collection $groups,
    ) {}
}
