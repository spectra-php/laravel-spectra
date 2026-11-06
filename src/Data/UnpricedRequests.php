<?php

declare(strict_types=1);

namespace Spectra\Data;

/**
 * The overview's count of successful requests recorded at $0 because their
 * model has no price in its provider's catalog, with the models involved, so
 * missing spend is visible instead of looking like free traffic.
 */
readonly class UnpricedRequests extends DataTransferObject
{
    /**
     * Create a new unpriced requests instance.
     *
     * @param  list<string>  $models  The unpriced models, most requests first.
     */
    public function __construct(
        public int $request_count,
        public array $models,
    ) {}
}
