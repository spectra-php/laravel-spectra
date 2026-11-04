<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * How many searches a request ran, for endpoints billed per search (e.g.
 * rerank), so a failed or unparsed request is never billed for one.
 */
readonly class SearchMetrics extends DataTransferObject
{
    /**
     * Create a new search metrics instance.
     */
    public function __construct(
        public int $count = 0,
    ) {}
}
