<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\Provider;

/**
 * Every provider the dashboard can show, for its provider filters and labels.
 */
readonly class ProvidersResponse extends DataTransferObject
{
    /**
     * @param  Collection<int, Provider>  $providers
     */
    public function __construct(
        public Collection $providers,
    ) {}
}
